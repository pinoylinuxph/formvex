export const DISCOVERY_SCHEMA_VERSION = 1;

const SUPPORTED_TYPES = new Set([
  'text',
  'email',
  'tel',
  'textarea',
  'select',
  'radio',
  'checkbox',
]);
const DISCOVERY_FRAGMENT_KEY = 'formvex_discovery';
const MAX_FORMS = 25;
const MAX_CONTROLS = 100;
const MAX_CHOICES = 100;
const DEFAULT_MAX_LENGTH = 10000;

export function discoverParameterSuggestions(controlType, controlName, displayLabel) {
  const text = `${controlName} ${displayLabel}`.toLowerCase();
  const suggestions = [];

  if (controlType === 'email' || /(^|[^a-z])e[-_ ]?mail([^a-z]|$)/u.test(text)) {
    suggestions.push('email');
  }

  if (controlType === 'tel' || /(phone|mobile|telephone|tel)/u.test(text)) {
    suggestions.push('phone');
  }

  if (/(company|organisation|organization|business)/u.test(text)) {
    suggestions.push('company');
  }

  if (/(subject|topic|title)/u.test(text)) {
    suggestions.push('subject');
  }

  if (/(message|comment|inquiry|enquiry|description|details)/u.test(text)) {
    suggestions.push('message');
  }

  if (/(full[-_ ]?name|your[-_ ]?name|^name$)/u.test(text)) {
    suggestions.push('full_name');
  }

  return [...new Set(suggestions)];
}

export function collectDiscoveryMetadata(documentRef, locationRef) {
  const forms = Array.from(documentRef.querySelectorAll('form'));
  const idCounts = new Map();

  for (const form of forms) {
    const id = (form.getAttribute('id') || '').trim();

    if (id !== '') {
      idCounts.set(id, (idCounts.get(id) || 0) + 1);
    }
  }

  const discoveredForms = forms
    .slice(0, MAX_FORMS)
    .map((form, index) => discoverForm(form, index, idCounts));

  if (forms.length > MAX_FORMS) {
    discoveredForms.push({
      form_marker: 'formvex-discovery-overflow',
      display_name: 'Discovery limit exceeded',
      marker_generated: true,
      ambiguous: true,
      controls: [],
      unsupported_controls: [{ control_name: '', control_type: 'form_count_exceeded' }],
    });
  }

  return {
    schema_version: DISCOVERY_SCHEMA_VERSION,
    page_path: locationRef.pathname || '/',
    forms: discoveredForms,
  };
}

function discoverForm(form, index, idCounts) {
  const existingId = (form.getAttribute('id') || '').trim();
  const existingMarker = (form.getAttribute('data-formvex') || '').trim();
  const hasUniqueId = existingId !== '' && idCounts.get(existingId) === 1;
  const hasMarker = existingMarker !== '';
  const marker = hasUniqueId
    ? existingId
    : hasMarker
      ? existingMarker
      : `formvex-discovery-${index + 1}`;
  const markerGenerated = !hasUniqueId && !hasMarker;
  const controls = [];
  const unsupportedControls = [];
  const groupedControls = new Map();
  const allControls = Array.from(form.querySelectorAll('input, textarea, select'));

  for (const control of allControls) {
    const name = (control.getAttribute('name') || '').trim();
    const type = controlType(control);

    if (!SUPPORTED_TYPES.has(type)) {
      unsupportedControls.push({ control_name: name, control_type: type });
      continue;
    }

    if (name === '') {
      unsupportedControls.push({ control_name: '', control_type: 'missing_name' });
      continue;
    }

    const groupKey =
      type === 'radio' || type === 'checkbox'
        ? `${type}:${name}`
        : `${type}:${name}:${controls.length}`;
    const current = groupedControls.get(groupKey);

    if (current) {
      current.required ||= Boolean(control.required);
      current.choices.push(...controlChoices(control, type));
      continue;
    }

    const label = controlLabel(control);
    const discovered = {
      discovery_key: `control-${index + 1}-${controls.length + 1}`,
      control_name: name,
      control_type: type,
      display_label: label.text,
      label_resolved: label.resolved,
      required: Boolean(control.required),
      max_length: controlLength(control),
      choices: controlChoices(control, type),
      choice_group_key: type === 'radio' || type === 'checkbox' ? groupKey : null,
      suggested_parameters: discoverParameterSuggestions(type, name, label.text),
    };
    groupedControls.set(groupKey, discovered);
    controls.push(discovered);
  }

  for (const control of controls) {
    control.choices = uniqueChoices(control.choices);

    if (control.choices.length > MAX_CHOICES) {
      unsupportedControls.push({
        control_name: control.control_name,
        control_type: 'choice_count_exceeded',
      });
      control.choices = control.choices.slice(0, MAX_CHOICES);
    }
  }

  if (controls.length > MAX_CONTROLS) {
    unsupportedControls.push({ control_name: '', control_type: 'control_count_exceeded' });
    controls.length = MAX_CONTROLS;
  }

  return {
    form_marker: marker,
    display_name: formName(form, index),
    marker_generated: markerGenerated,
    ambiguous: false,
    controls,
    unsupported_controls: unsupportedControls,
  };
}

function controlType(control) {
  const tagName = control.tagName.toLowerCase();

  if (tagName === 'textarea') {
    return 'textarea';
  }

  if (tagName === 'select') {
    return 'select';
  }

  const type = (control.getAttribute('type') || 'text').toLowerCase();

  if (type === 'radio' || type === 'checkbox') {
    return type;
  }

  return type;
}

function controlLength(control) {
  const value = control.getAttribute('maxlength');

  if (value === null || value === '' || !/^\d+$/u.test(value)) {
    return DEFAULT_MAX_LENGTH;
  }

  return Math.min(Math.max(Number.parseInt(value, 10), 1), DEFAULT_MAX_LENGTH);
}

function controlChoices(control, type) {
  if (type === 'select') {
    return Array.from(control.querySelectorAll('option')).flatMap((option) => {
      const value = option.hasAttribute('value')
        ? option.getAttribute('value')
        : option.textContent.trim();
      const label = option.textContent.trim();

      if (value === '' || label === '') {
        return [];
      }

      return [{ value, label }];
    });
  }

  if (type === 'radio' || type === 'checkbox') {
    const label = controlLabel(control);
    return [
      {
        value: control.getAttribute('value') || 'on',
        label: label.text,
      },
    ];
  }

  return [];
}

function controlLabel(control) {
  const id = (control.getAttribute('id') || '').trim();
  let label = null;

  if (id !== '') {
    label =
      Array.from(control.ownerDocument.querySelectorAll('label')).find(
        (candidate) => candidate.htmlFor === id,
      ) || null;
  }

  label ||= control.closest('label');
  const ariaLabel = (control.getAttribute('aria-label') || '').trim();
  const placeholder = (control.getAttribute('placeholder') || '').trim();
  const name = (control.getAttribute('name') || '').trim();
  const text = (label?.textContent || '').trim() || ariaLabel || placeholder || name;

  return {
    text: text || 'Unresolved field label',
    resolved: text !== '',
  };
}

function formName(form, index) {
  return (
    form.getAttribute('aria-label') ||
    form.getAttribute('id') ||
    form.getAttribute('name') ||
    `Form ${index + 1}`
  ).trim();
}

function uniqueChoices(choices) {
  const seen = new Set();

  return choices.filter((choice) => {
    if (seen.has(choice.value)) {
      return false;
    }

    seen.add(choice.value);
    return true;
  });
}

export async function runDiscovery({
  documentRef = document,
  windowRef = window,
  fetchImpl = fetch,
} = {}) {
  const hash = windowRef.location.hash || '';
  const fragment = new URLSearchParams(hash.startsWith('#') ? hash.slice(1) : hash);
  const token = fragment.get(DISCOVERY_FRAGMENT_KEY);

  if (!token) {
    return null;
  }

  const payload = collectDiscoveryMetadata(documentRef, windowRef.location);
  const body = JSON.stringify({ ...payload, capability: token });
  let response;
  let result;

  try {
    response = await fetchImpl('/formvex/api/v1/discovery/redeem', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body,
      credentials: 'omit',
    });
    result = await response.json();
  } catch {
    result = {
      error: {
        message:
          'The local discovery service could not be reached. Return to the portal and try again.',
      },
    };
  }

  windowRef.history.replaceState(
    {},
    documentRef.title,
    `${windowRef.location.pathname}${windowRef.location.search}`,
  );
  showDiscoveryResult(documentRef, response?.ok === true, result);

  return result;
}

function showDiscoveryResult(documentRef, success, result) {
  const notice = documentRef.createElement('div');
  notice.setAttribute('role', success ? 'status' : 'alert');
  notice.setAttribute('data-formvex-discovery-result', 'true');
  notice.textContent = success
    ? 'Discovery completed. Return to the local administration portal to review the detected forms.'
    : result?.error?.message ||
      'Discovery could not be completed. Return to the portal and try again.';
  documentRef.body.append(notice);
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
  void runDiscovery();
}
