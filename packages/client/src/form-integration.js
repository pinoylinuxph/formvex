export const SUBMISSION_SCHEMA_VERSION = 1;

const SUPPORTED_TYPES = new Set([
  'text',
  'email',
  'tel',
  'textarea',
  'select',
  'radio',
  'checkbox',
]);
const MAX_FORMS = 25;
const MAX_MARKER_LENGTH = 120;
const MAX_PAGE_PATH_LENGTH = 2048;
const MAX_FEEDBACK_LENGTH = 512;
const DEFAULT_BRAND_NAME = 'Noname';
const ATTEMPT_KEY_PREFIX = 'formvex:attempt:';
const FORM_MARKER_PATTERN = /^[A-Za-z][A-Za-z0-9_.:-]{0,119}$/u;
const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iu;

export function findFormCandidates(documentRef) {
  const forms = Array.from(documentRef?.querySelectorAll?.('form') ?? []);
  const idCounts = new Map();

  for (const form of forms) {
    const id = (form.getAttribute('id') || '').trim();

    if (id !== '') {
      idCounts.set(id, (idCounts.get(id) || 0) + 1);
    }
  }

  return forms.slice(0, MAX_FORMS).flatMap((form) => {
    const marker = formMarker(form, idCounts);

    if (marker === null || hasUnsupportedControls(form)) {
      return [];
    }

    return [{ form, marker }];
  });
}

export function installPendingSubmitGuard(
  form,
  documentRef,
  message = 'The form is still connecting. Wait a moment and try again.',
) {
  let pendingFeedback = null;

  const guard = (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();

    if (pendingFeedback === null) {
      pendingFeedback = documentRef.createElement('div');
      pendingFeedback.className = 'formvex-feedback';
      pendingFeedback.setAttribute('data-formvex-pending-feedback', 'true');
      pendingFeedback.setAttribute('role', 'status');
      pendingFeedback.setAttribute('aria-live', 'polite');
      form.append(pendingFeedback);
    }

    pendingFeedback.textContent = message;
    pendingFeedback.hidden = false;
  };

  form.addEventListener('submit', guard, true);

  return () => {
    form.removeEventListener('submit', guard, true);
    pendingFeedback?.remove();
  };
}

export async function initializeFormIntegration({
  documentRef = globalThis.document,
  windowRef = globalThis.window,
  fetchImpl = windowRef?.fetch?.bind(windowRef) || globalThis.fetch,
  storageRef = sessionStorageFor(windowRef),
  cryptoRef = windowRef?.crypto || globalThis.crypto,
} = {}) {
  if (!documentRef || typeof fetchImpl !== 'function') {
    return [];
  }

  const candidates = findFormCandidates(documentRef);
  const attached = [];

  await Promise.all(
    candidates.map(async ({ form, marker }) => {
      const releasePendingGuard = installPendingSubmitGuard(form, documentRef);
      let resolution;

      try {
        resolution = await resolveForm({
          marker,
          locationRef: windowRef?.location,
          fetchImpl,
        });
      } finally {
        releasePendingGuard();
      }

      if (resolution === null) {
        return;
      }

      attachForm(form, resolution, {
        documentRef,
        windowRef,
        fetchImpl,
        storageRef,
        cryptoRef,
        pagePath: windowRef?.location?.pathname || '/',
      });
      void submitFormChangeObservation(
        form,
        resolution,
        windowRef?.location?.pathname || '/',
        fetchImpl,
        cryptoRef,
      );
      attached.push(form);
    }),
  );

  return attached;
}

export function initializeQualificationIntegration({
  documentRef = globalThis.document,
  windowRef = globalThis.window,
  fetchImpl = windowRef?.fetch?.bind(windowRef) || globalThis.fetch,
  storageRef = sessionStorageFor(windowRef),
  cryptoRef = windowRef?.crypto || globalThis.crypto,
  resolution,
  qualificationToken,
} = {}) {
  if (
    !documentRef ||
    typeof fetchImpl !== 'function' ||
    !isResolutionPayload(resolution, resolution?.form_marker) ||
    typeof qualificationToken !== 'string' ||
    qualificationToken === ''
  ) {
    return [];
  }

  const candidate = findFormCandidates(documentRef).find(
    ({ marker }) => marker === resolution.form_marker,
  );

  if (!candidate) {
    return [];
  }

  attachForm(candidate.form, resolution, {
    documentRef,
    windowRef,
    fetchImpl,
    storageRef,
    cryptoRef,
    pagePath: windowRef?.location?.pathname || '/',
    qualificationToken,
  });

  return [candidate.form];
}

export async function resolveForm({ marker, locationRef, fetchImpl }) {
  if (
    typeof fetchImpl !== 'function' ||
    !isValidMarker(marker) ||
    typeof locationRef?.pathname !== 'string' ||
    !isValidPagePath(locationRef.pathname)
  ) {
    return null;
  }

  const query = new URLSearchParams({
    schema_version: '1',
    page_path: locationRef.pathname || '/',
    form_marker: marker,
  });

  try {
    const response = await fetchImpl(`/formvex/api/v1/forms/resolve?${query.toString()}`, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      credentials: 'omit',
    });

    if (response.status !== 200) {
      return null;
    }

    const payload = await response.json();

    if (!isResolutionPayload(payload, marker)) {
      return null;
    }

    return payload;
  } catch {
    return null;
  }
}

export function collectSubmissionData(form) {
  const fields = {};
  const fieldShape = [];
  const shapeKeys = new Set();
  const controls = Array.from(form?.querySelectorAll?.('input, textarea, select') ?? []);

  for (const control of controls) {
    const name = (control.getAttribute('name') || '').trim();
    const type = controlType(control);

    if (name === '' || !SUPPORTED_TYPES.has(type) || control.disabled) {
      continue;
    }

    const shapeKey = `${name}:${type}`;

    if (!shapeKeys.has(shapeKey)) {
      shapeKeys.add(shapeKey);
      fieldShape.push({ control_name: name, control_type: type });
    }

    if ((type === 'radio' || type === 'checkbox') && !control.checked) {
      continue;
    }

    const value = controlValue(control, type);

    if (value === null) {
      continue;
    }

    if (Array.isArray(value)) {
      fields[name] = [...(Array.isArray(fields[name]) ? fields[name] : []), ...value];
      continue;
    }

    fields[name] = value;
  }

  return { fields, field_shape: fieldShape };
}

/**
 * Collect only bounded form structure for administrator change observation.
 * This intentionally never reads control.value, checked, selected values, or
 * any other visitor-entered state.
 */
export function collectFormChangeObservation(form) {
  const controls = [];
  const groups = new Map();
  const sourceControls = Array.from(form?.querySelectorAll?.('input, textarea, select') ?? []);

  for (const control of sourceControls) {
    const name = (control.getAttribute('name') || '').trim();
    const type = controlType(control);

    if (name === '' || !SUPPORTED_TYPES.has(type)) {
      continue;
    }

    const groupKey =
      type === 'radio' || type === 'checkbox'
        ? `${type}:${name}`
        : `${type}:${name}:${controls.length}`;
    const existing = groups.get(groupKey);

    if (existing) {
      existing.required ||= controlRequired(control);
      existing.choice_values = uniqueBoundedValues([
        ...existing.choice_values,
        ...controlChoiceValues(control, type),
      ]);
      continue;
    }

    const observed = {
      control_name: name,
      control_type: type,
      required: controlRequired(control),
      max_length: controlMaxLength(control),
      choice_values: uniqueBoundedValues(controlChoiceValues(control, type)),
    };
    groups.set(groupKey, observed);
    controls.push(observed);
  }

  return controls.slice(0, 100);
}

export function createSubmissionEnvelope(
  form,
  resolution,
  attemptId,
  pagePath = globalThis.window?.location?.pathname || '/',
) {
  const { fields, field_shape: fieldShape } = collectSubmissionData(form);
  const honeypotControl = form?.__formvexHoneypotControl;
  const captchaToken = form?.__formvexCaptchaToken;

  const envelope = {
    schema_version: SUBMISSION_SCHEMA_VERSION,
    page_path: pagePath,
    attempt_id: attemptId,
    configuration_version: resolution.configuration_version,
    form_marker: resolution.form_marker,
    fields,
    field_shape: fieldShape,
  };

  if (honeypotControl) {
    envelope.honeypot = typeof honeypotControl.value === 'string' ? honeypotControl.value : '';
  }

  if (typeof captchaToken === 'string') {
    envelope.captcha_token = captchaToken;
  }

  return envelope;
}

export function mapSubmissionResponse(status, payload, brandName = DEFAULT_BRAND_NAME) {
  if (status === 202 && isAcceptedPayload(payload)) {
    return {
      state: 'accepted',
      message: boundedText(
        payload.acknowledgement || payload.message,
        'Your message has been received.',
      ),
    };
  }

  const error = isObject(payload?.error) ? payload.error : {};
  const message = boundedText(error.message, defaultMessage(status, safeBrandName(brandName)));
  const fieldErrors = Array.isArray(error.fields)
    ? error.fields.flatMap((field) => {
        if (!isObject(field) || typeof field.field !== 'string') {
          return [];
        }

        return [
          {
            field: field.field.slice(0, MAX_MARKER_LENGTH),
            message: boundedText(field.message, message),
          },
        ];
      })
    : [];

  if (status === 409) {
    return { state: 'unavailable', message, invalidateAttempt: true, fieldErrors };
  }

  if (status === 413) {
    return { state: 'rejected', message, fieldErrors };
  }

  if (status === 422) {
    return { state: 'rejected', message, fieldErrors };
  }

  if (status === 429) {
    return { state: 'rejected', message, fieldErrors };
  }

  if (status >= 500 && status <= 599) {
    return { state: 'uncertain', message, fieldErrors };
  }

  return {
    state: 'uncertain',
    message: defaultMessage(status, safeBrandName(brandName)),
    fieldErrors,
  };
}

function attachForm(
  form,
  resolution,
  { documentRef, windowRef, fetchImpl, storageRef, cryptoRef, pagePath, qualificationToken = null },
) {
  let state = 'ready';
  let attemptId = null;
  let attemptInvalidated = false;
  const feedbackInstanceId = `formvex-${safeIdPart(resolution.public_form_id)}-${safeIdPart(
    resolution.form_marker,
  )}`;
  const originalDisabledState = new Map();
  form.__formvexHoneypotControl = addHoneypot(form, documentRef);
  const captchaState = setupCaptcha(form, resolution, documentRef, windowRef);
  const showInvalidFeedback = () => {
    state = 'invalid';
    renderFeedback(form, documentRef, {
      state: 'rejected',
      message: 'Please correct the highlighted fields and try again.',
      fieldErrors: [],
    });
  };
  const invalid = () => {
    if (state !== 'submitting') {
      showInvalidFeedback();
    }
  };

  const submit = (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();

    if (state === 'submitting') {
      return;
    }

    if (!form.checkValidity()) {
      showInvalidFeedback();
      focusInvalidControl(form);
      restoreSubmitControls(form, originalDisabledState);
      return;
    }

    if (resolution.captcha?.enabled && !captchaState.token) {
      renderFeedback(form, documentRef, {
        state: 'rejected',
        message: 'Complete the security challenge before submitting this form.',
        fieldErrors: [],
      });
      restoreSubmitControls(form, originalDisabledState);
      return;
    }

    state = 'submitting';
    disableSubmitControls(form, originalDisabledState);

    let envelope;

    try {
      attemptId = attemptInvalidated
        ? null
        : getAttemptId(resolution, pagePath, storageRef, cryptoRef, attemptId);
      attemptInvalidated = false;
      envelope = createSubmissionEnvelope(form, resolution, attemptId, pagePath);
    } catch {
      state = 'unavailable';
      renderFeedback(form, documentRef, {
        state,
        message: `${safeBrandName(resolution)} could not prepare this message. Please try again.`,
        fieldErrors: [],
      });
      restoreSubmitControls(form, originalDisabledState);
      return;
    }

    void submitEnvelope(resolution, envelope, {
      fetchImpl,
      qualificationToken,
    }).then((result) => {
      state = result.state;

      if (result.invalidateAttempt) {
        attemptInvalidated = true;
        removeAttemptId(resolution, pagePath, storageRef);
        attemptId = null;
      }

      renderFeedback(form, documentRef, result);
      clearFieldErrors(form);

      if (result.state === 'accepted') {
        form.reset();
        removeAttemptId(resolution, pagePath, storageRef);
        attemptId = null;
      } else {
        form.__formvexCaptchaToken = null;
        resetCaptcha(captchaState, windowRef);
        renderFieldErrors(form, result.fieldErrors || [], documentRef, feedbackInstanceId);
      }

      restoreSubmitControls(form, originalDisabledState);
    });
  };

  form.addEventListener('submit', submit, true);
  form.addEventListener('invalid', invalid, true);
}

async function submitFormChangeObservation(form, resolution, pagePath, fetchImpl, cryptoRef) {
  if (typeof resolution.source_fingerprint !== 'string' || resolution.source_fingerprint === '') {
    return;
  }

  const controls = collectFormChangeObservation(form);
  const sourceFingerprint = await formStructureFingerprint(controls, cryptoRef);

  if (sourceFingerprint === null || sourceFingerprint === resolution.source_fingerprint) {
    return;
  }

  try {
    await fetchImpl(
      `/formvex/api/v1/forms/${encodeURIComponent(resolution.public_form_id)}/change-observations`,
      {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        credentials: 'omit',
        body: JSON.stringify({
          schema_version: SUBMISSION_SCHEMA_VERSION,
          configuration_version: resolution.configuration_version,
          page_path: pagePath,
          form_marker: resolution.form_marker,
          source_fingerprint: sourceFingerprint,
          controls,
        }),
      },
    );
  } catch {
    // Observation is advisory. A network or storage failure must never block the form.
  }
}

async function formStructureFingerprint(controls, cryptoRef) {
  const TextEncoderClass = globalThis.TextEncoder;

  if (typeof cryptoRef?.subtle?.digest !== 'function' || typeof TextEncoderClass !== 'function') {
    return null;
  }

  try {
    const bytes = new TextEncoderClass().encode(JSON.stringify(controls));
    const digest = await cryptoRef.subtle.digest('SHA-256', bytes);

    return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join(
      '',
    );
  } catch {
    return null;
  }
}

async function submitEnvelope(resolution, envelope, { fetchImpl, qualificationToken = null }) {
  try {
    const qualification = typeof qualificationToken === 'string' && qualificationToken !== '';
    const response = await fetchImpl(
      qualification
        ? '/formvex/api/v1/qualification/submissions'
        : `/formvex/api/v1/forms/${encodeURIComponent(resolution.public_form_id)}/submissions`,
      {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
        },
        credentials: 'omit',
        body: JSON.stringify(
          qualification ? { ...envelope, qualification_token: qualificationToken } : envelope,
        ),
      },
    );
    const payload = await response.json();

    return mapSubmissionResponse(
      response.status,
      payload,
      safeBrandName(resolution.branding?.brand_name),
    );
  } catch {
    return {
      state: 'uncertain',
      message: `${safeBrandName(resolution)} could not confirm whether your message was received. Check your connection and try again.`,
      fieldErrors: [],
    };
  }
}

function formMarker(form, idCounts) {
  const explicitMarker = (form.getAttribute('data-formvex') || '').trim();

  if (explicitMarker !== '') {
    return isValidMarker(explicitMarker) ? explicitMarker : null;
  }

  const id = (form.getAttribute('id') || '').trim();

  return id !== '' && idCounts.get(id) === 1 && isValidMarker(id) ? id : null;
}

function hasUnsupportedControls(form) {
  return Array.from(form.querySelectorAll('input, textarea, select')).some((control) => {
    const type = controlType(control);

    return (control.getAttribute('name') || '').trim() === '' || !SUPPORTED_TYPES.has(type);
  });
}

function controlType(control) {
  const tagName = control.tagName.toLowerCase();

  if (tagName === 'textarea') {
    return 'textarea';
  }

  if (tagName === 'select') {
    return 'select';
  }

  return (control.getAttribute('type') || 'text').toLowerCase();
}

function controlRequired(control) {
  return control.required === true || control.hasAttribute?.('required') === true;
}

function controlMaxLength(control) {
  const value = control.getAttribute('maxlength');

  if (value === null || value === '' || !/^\d+$/u.test(value)) {
    return 10000;
  }

  return Math.min(Math.max(Number.parseInt(value, 10), 1), 10000);
}

function controlChoiceValues(control, type) {
  if (type === 'select') {
    return Array.from(control.querySelectorAll?.('option') ?? []).flatMap((option) => {
      const value =
        option.hasAttribute?.('value') === true
          ? option.getAttribute('value')
          : option.textContent?.trim();

      return typeof value === 'string' && value !== '' ? [value] : [];
    });
  }

  if (type === 'radio' || type === 'checkbox') {
    return [control.getAttribute('value') || 'on'];
  }

  return [];
}

function uniqueBoundedValues(values) {
  return [
    ...new Set(
      values
        .filter((value) => typeof value === 'string' && value !== '')
        .map((value) => value.slice(0, 256)),
    ),
  ].slice(0, 100);
}

function controlValue(control, type) {
  if (type === 'checkbox') {
    return [control.getAttribute('value') || 'on'];
  }

  if (type === 'radio') {
    return control.getAttribute('value') || 'on';
  }

  if (type === 'select') {
    const options = Array.from(control.selectedOptions || []).map((option) => option.value);

    return control.multiple ? options : options[0] || '';
  }

  return typeof control.value === 'string' ? control.value : '';
}

function isResolutionPayload(payload, marker) {
  const captcha = payload?.captcha;
  const branding = payload?.branding;

  return (
    isObject(payload) &&
    payload.schema_version === 1 &&
    typeof payload.public_form_id === 'string' &&
    payload.public_form_id.length > 0 &&
    payload.public_form_id.length <= 128 &&
    Number.isInteger(payload.configuration_version) &&
    payload.configuration_version > 0 &&
    payload.form_marker === marker &&
    (!payload.source_fingerprint || /^[0-9a-f]{64}$/u.test(payload.source_fingerprint)) &&
    (!branding ||
      (isObject(branding) && safeBrandName(branding.brand_name) === branding.brand_name)) &&
    (!captcha ||
      (isObject(captcha) &&
        typeof captcha.enabled === 'boolean' &&
        captcha.provider === 'turnstile' &&
        typeof captcha.site_key === 'string' &&
        captcha.site_key.length <= 2048 &&
        (!captcha.enabled || captcha.site_key.length > 0)))
  );
}

function addHoneypot(form, documentRef) {
  if (typeof documentRef?.createElement !== 'function') {
    return null;
  }

  const honeypot = documentRef.createElement('input');
  honeypot.type = 'text';
  honeypot.name = 'formvex_website';
  honeypot.setAttribute('data-formvex-honeypot', 'true');
  honeypot.setAttribute('aria-hidden', 'true');
  honeypot.setAttribute('autocomplete', 'off');
  honeypot.tabIndex = -1;
  honeypot.style.position = 'fixed';
  honeypot.style.left = '-10000px';
  honeypot.style.top = 'auto';
  honeypot.style.width = '1px';
  honeypot.style.height = '1px';
  honeypot.style.opacity = '0';
  honeypot.style.pointerEvents = 'none';

  const host = documentRef.body || form.parentNode;

  if (host && typeof host.append === 'function') {
    host.append(honeypot);
  }

  return honeypot;
}

function setupCaptcha(form, resolution, documentRef, windowRef) {
  const state = { token: null, widgetId: null };

  if (!resolution.captcha?.enabled || typeof documentRef?.createElement !== 'function') {
    return state;
  }

  const wrapper = documentRef.createElement('div');
  wrapper.className = 'formvex-captcha';
  wrapper.setAttribute('data-formvex-captcha', 'true');
  wrapper.setAttribute('aria-label', 'Security challenge');
  form.append(wrapper);

  const render = () => {
    if (state.widgetId !== null || typeof windowRef?.turnstile?.render !== 'function') {
      return;
    }

    state.widgetId = windowRef.turnstile.render(wrapper, {
      sitekey: resolution.captcha.site_key,
      action: 'formvex',
      callback: (token) => {
        state.token = typeof token === 'string' ? token : null;
        form.__formvexCaptchaToken = state.token;
      },
      'expired-callback': () => {
        state.token = null;
        form.__formvexCaptchaToken = null;
      },
      'error-callback': () => {
        state.token = null;
        form.__formvexCaptchaToken = null;
      },
    });
  };

  if (typeof windowRef?.turnstile?.render === 'function') {
    render();
  } else {
    loadTurnstileScript(documentRef, render);
  }

  return state;
}

function loadTurnstileScript(documentRef, onReady) {
  const existing = documentRef.querySelector?.('script[data-formvex-turnstile]');

  if (existing) {
    existing.addEventListener?.('load', onReady, { once: true });
    return;
  }

  const script = documentRef.createElement('script');
  script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
  script.async = true;
  script.defer = true;
  script.setAttribute('data-formvex-turnstile', 'true');
  script.addEventListener('load', onReady, { once: true });
  (documentRef.head || documentRef.body)?.append(script);
}

function resetCaptcha(state, windowRef) {
  state.token = null;

  if (state.widgetId !== null && typeof windowRef?.turnstile?.reset === 'function') {
    windowRef.turnstile.reset(state.widgetId);
  }
}

function isAcceptedPayload(payload) {
  return (
    isObject(payload) &&
    payload.schema_version === SUBMISSION_SCHEMA_VERSION &&
    typeof payload.receipt_id === 'string' &&
    payload.receipt_id.length > 0 &&
    payload.receipt_id.length <= 128 &&
    (typeof payload.acknowledgement === 'string' || typeof payload.message === 'string')
  );
}

function getAttemptId(resolution, pagePath, storageRef, cryptoRef, currentAttemptId) {
  if (isValidUuid(currentAttemptId)) {
    return currentAttemptId;
  }

  const key = attemptStorageKey(resolution, pagePath);

  try {
    const stored = storageRef?.getItem(key);

    if (isValidUuid(stored)) {
      return stored;
    }
  } catch {
    // Continue with in-memory correlation when browser storage is unavailable.
  }

  const generated = cryptoRef?.randomUUID?.();

  if (!isValidUuid(generated)) {
    throw new Error('Secure submission attempts require crypto.randomUUID.');
  }

  try {
    storageRef?.setItem(key, generated);
  } catch {
    // The generated opaque id remains in the form integration closure.
  }

  return generated;
}

function removeAttemptId(resolution, pagePath, storageRef) {
  try {
    storageRef?.removeItem(attemptStorageKey(resolution, pagePath));
  } catch {
    // Browser storage cleanup is best effort and never blocks the form.
  }
}

function attemptStorageKey(resolution, pagePath) {
  return `${ATTEMPT_KEY_PREFIX}${resolution.public_form_id}:${pagePath}:${resolution.form_marker}`;
}

function disableSubmitControls(form, originalDisabledState) {
  for (const control of submitControls(form)) {
    originalDisabledState.set(control, control.disabled);
    control.disabled = true;
  }
}

function restoreSubmitControls(form, originalDisabledState) {
  for (const control of submitControls(form)) {
    control.disabled = originalDisabledState.get(control) ?? control.disabled;
  }

  originalDisabledState.clear();
}

function submitControls(form) {
  return Array.from(form.querySelectorAll('button, input')).filter((control) => {
    const type = (control.getAttribute('type') || 'submit').toLowerCase();

    return type === 'submit';
  });
}

function focusInvalidControl(form) {
  const invalid = form.querySelector(':invalid');

  if (typeof invalid?.focus === 'function') {
    invalid.focus();
  }
}

function renderFeedback(form, documentRef, result) {
  const feedback = feedbackElement(form, documentRef);

  feedback.setAttribute('role', result.state === 'accepted' ? 'status' : 'alert');
  feedback.setAttribute('aria-live', result.state === 'accepted' ? 'polite' : 'assertive');
  feedback.textContent = boundedText(result.message, defaultMessage(500));
  feedback.hidden = false;
}

function feedbackElement(form, documentRef) {
  const existing = form.querySelector('[data-formvex-feedback]');

  if (existing) {
    return existing;
  }

  const feedback = documentRef.createElement('div');
  feedback.setAttribute('data-formvex-feedback', 'true');
  feedback.className = 'formvex-feedback';
  form.append(feedback);

  return feedback;
}

function clearFieldErrors(form) {
  for (const error of form.querySelectorAll('[data-formvex-field-error]')) {
    const control = Array.from(form.querySelectorAll('input, textarea, select')).find((candidate) =>
      (candidate.getAttribute('aria-describedby') || '').split(/\s+/u).includes(error.id),
    );

    if (control) {
      const previousDescribedBy = error.getAttribute('data-formvex-previous-describedby');
      const previousInvalid = error.getAttribute('data-formvex-previous-invalid');

      if (previousDescribedBy === '__absent__') {
        control.removeAttribute('aria-describedby');
      } else {
        control.setAttribute('aria-describedby', previousDescribedBy || '');
      }

      if (previousInvalid === '__absent__') {
        control.removeAttribute('aria-invalid');
      } else {
        control.setAttribute('aria-invalid', previousInvalid || 'true');
      }
    }

    error.remove();
  }
}

function renderFieldErrors(form, fieldErrors, documentRef, feedbackInstanceId) {
  fieldErrors.forEach((fieldError, index) => {
    const control = Array.from(form.querySelectorAll('input, textarea, select')).find(
      (candidate) => candidate.getAttribute('name') === fieldError.field,
    );

    if (!control) {
      return;
    }

    const error = documentRef.createElement('span');
    const errorId = `formvex-field-error-${feedbackInstanceId}-${index + 1}`;
    const previousDescribedBy = control.getAttribute('aria-describedby');
    const previousInvalid = control.getAttribute('aria-invalid');

    error.id = errorId;
    error.setAttribute('data-formvex-field-error', 'true');
    error.setAttribute(
      'data-formvex-previous-describedby',
      previousDescribedBy === null ? '__absent__' : previousDescribedBy,
    );
    error.setAttribute(
      'data-formvex-previous-invalid',
      previousInvalid === null ? '__absent__' : previousInvalid,
    );
    error.textContent = boundedText(fieldError.message, 'Check this field and try again.');
    control.setAttribute('aria-invalid', 'true');
    control.setAttribute(
      'aria-describedby',
      previousDescribedBy === null ? errorId : `${previousDescribedBy} ${errorId}`,
    );
    control.insertAdjacentElement('afterend', error);
  });
}

function defaultMessage(status, brandName = DEFAULT_BRAND_NAME) {
  if (status === 413) {
    return 'This message is too large to send. Shorten it and try again.';
  }

  if (status === 422) {
    return 'Please correct the highlighted fields and try again.';
  }

  if (status === 409) {
    return 'This form changed or is temporarily unavailable. Review it and try again.';
  }

  if (status === 429) {
    return 'Too many attempts were made. Wait a moment and try again.';
  }

  if (status >= 500 && status <= 599) {
    return `${brandName} could not accept your message. Please try again.`;
  }

  return `${brandName} could not confirm whether your message was received. Check your connection and try again.`;
}

function boundedText(value, fallback) {
  return typeof value === 'string' && value.trim() !== ''
    ? value.trim().slice(0, MAX_FEEDBACK_LENGTH)
    : fallback;
}

function isValidMarker(value) {
  return (
    typeof value === 'string' &&
    value.length <= MAX_MARKER_LENGTH &&
    FORM_MARKER_PATTERN.test(value)
  );
}

function isValidPagePath(value) {
  return value.length > 0 && value.length <= MAX_PAGE_PATH_LENGTH && value.startsWith('/');
}

function safeIdPart(value) {
  return encodeURIComponent(value).replaceAll('%', '-');
}

function safeBrandName(value) {
  return typeof value === 'string' && value.trim() !== '' && value.length <= 80
    ? value.trim()
    : DEFAULT_BRAND_NAME;
}

function isValidUuid(value) {
  return typeof value === 'string' && UUID_PATTERN.test(value);
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function sessionStorageFor(windowRef) {
  try {
    return windowRef?.sessionStorage;
  } catch {
    return null;
  }
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
  const fragment = new URLSearchParams((window.location.hash || '').replace(/^#/, ''));

  if (!fragment.has('formvex_qualification')) {
    void initializeFormIntegration();
  }
}
