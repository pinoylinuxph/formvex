import {
  collectSubmissionData,
  findFormCandidates,
  initializeQualificationIntegration,
  installPendingSubmitGuard,
} from './form-integration.js';

export const QUALIFICATION_SCHEMA_VERSION = 1;

const QUALIFICATION_FRAGMENT_KEY = 'formvex_qualification';
const MAX_FORMS = 25;
const MAX_CONTROLS = 100;

export async function runQualification({
  documentRef = globalThis.document,
  windowRef = globalThis.window,
  fetchImpl = windowRef?.fetch?.bind(windowRef) || globalThis.fetch,
} = {}) {
  if (!documentRef || !windowRef?.location || typeof fetchImpl !== 'function') {
    return null;
  }

  const fragment = new URLSearchParams((windowRef.location.hash || '').replace(/^#/, ''));
  const token = fragment.get(QUALIFICATION_FRAGMENT_KEY);

  if (!token) {
    return null;
  }

  const candidates = findFormCandidates(documentRef).slice(0, MAX_FORMS);
  const forms = candidates.map(({ form, marker }) => ({
    form_marker: marker,
    field_shape: collectSubmissionData(form).field_shape.slice(0, MAX_CONTROLS),
  }));
  const pendingGuards = candidates.map(({ form }) => ({
    form,
    release: installPendingSubmitGuard(
      form,
      documentRef,
      'Qualification is still loading. Wait until the page says it is ready, then try again.',
    ),
  }));
  const body = JSON.stringify({
    schema_version: QUALIFICATION_SCHEMA_VERSION,
    qualification: token,
    page_path: windowRef.location.pathname || '/',
    forms,
  });

  windowRef.history.replaceState(
    {},
    documentRef.title,
    `${windowRef.location.pathname}${windowRef.location.search}`,
  );

  let response;
  let result;

  try {
    response = await fetchImpl('/formvex/api/v1/qualification/redeem', {
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
          'The qualification page could not contact the local service. Return to the portal and start a new qualification session.',
      },
    };
  }

  const success = response?.status === 201 && isQualificationPayload(result);

  if (success) {
    const attached = initializeQualificationIntegration({
      documentRef,
      windowRef,
      fetchImpl,
      resolution: result,
      qualificationToken: result.qualification_token,
    });

    if (attached.length === 1) {
      pendingGuards.forEach(({ release }) => release());
    }

    showQualificationResult(
      documentRef,
      attached.length === 1,
      attached.length === 1
        ? 'Qualification is ready. Enter synthetic test values and submit the existing form button or keyboard path.'
        : 'The approved form was not found on this page. Return to the portal and check the page path and form marker.',
    );
  } else {
    showQualificationResult(
      documentRef,
      false,
      result?.error?.message ||
        'Qualification could not be completed. Return to the portal and start a new qualification session.',
    );
  }

  return result;
}

function isQualificationPayload(payload) {
  return (
    payload &&
    typeof payload === 'object' &&
    !Array.isArray(payload) &&
    payload.schema_version === QUALIFICATION_SCHEMA_VERSION &&
    typeof payload.qualification_token === 'string' &&
    payload.qualification_token.length > 0 &&
    typeof payload.public_form_id === 'string' &&
    Number.isInteger(payload.configuration_version) &&
    payload.configuration_version > 0 &&
    typeof payload.form_marker === 'string'
  );
}

function showQualificationResult(documentRef, success, message) {
  const notice = documentRef.createElement('div');
  notice.setAttribute('role', success ? 'status' : 'alert');
  notice.setAttribute('data-formvex-qualification-result', 'true');
  notice.textContent = message;
  documentRef.body.append(notice);
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
  const fragment = new URLSearchParams((window.location.hash || '').replace(/^#/, ''));

  if (fragment.has(QUALIFICATION_FRAGMENT_KEY)) {
    void runQualification();
  }
}
