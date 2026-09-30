import { describe, expect, it, vi } from 'vitest';

import {
  collectSubmissionData,
  createSubmissionEnvelope,
  findFormCandidates,
  mapSubmissionResponse,
  resolveForm,
} from '../../../packages/client/src/form-integration.js';

function control({
  tagName = 'input',
  type = 'text',
  name,
  value = '',
  checked = false,
  disabled = false,
  multiple = false,
  selectedOptions = [],
  id = '',
  marker = '',
}) {
  return {
    tagName: tagName.toUpperCase(),
    checked,
    disabled,
    multiple,
    selectedOptions,
    value,
    getAttribute(attribute) {
      return (
        {
          id,
          'data-formvex': marker,
          name,
          type,
          value,
        }[attribute] ?? null
      );
    },
  };
}

function form(id, controls, marker = '') {
  return {
    getAttribute(attribute) {
      return { id, 'data-formvex': marker }[attribute] ?? null;
    },
    querySelectorAll() {
      return controls;
    },
  };
}

describe('website form integration client', () => {
  it('serializes only supported deliberate-submit controls and preserves empty group shape', () => {
    const formRef = form('contact-form', [
      control({ name: 'name', value: '<b>Ada</b>' }),
      control({ name: 'email', type: 'email', value: 'ada@example.test' }),
      control({ name: 'interests', type: 'checkbox', value: 'hosting', checked: true }),
      control({ name: 'interests', type: 'checkbox', value: 'support' }),
      control({ name: 'preferred', type: 'radio', value: 'email' }),
      control({ name: 'attachment', type: 'file', value: 'secret.txt' }),
    ]);

    expect(collectSubmissionData(formRef)).toEqual({
      fields: {
        name: '<b>Ada</b>',
        email: 'ada@example.test',
        interests: ['hosting'],
      },
      field_shape: [
        { control_name: 'name', control_type: 'text' },
        { control_name: 'email', control_type: 'email' },
        { control_name: 'interests', control_type: 'checkbox' },
        { control_name: 'preferred', control_type: 'radio' },
      ],
    });
  });

  it('serializes single and multiple select values using their approved shapes', () => {
    const formRef = form('selection-form', [
      control({
        tagName: 'select',
        name: 'sector',
        selectedOptions: [{ value: 'engineering' }],
      }),
      control({
        tagName: 'select',
        name: 'services',
        multiple: true,
        selectedOptions: [{ value: 'hosting' }, { value: 'support' }],
      }),
    ]);

    expect(collectSubmissionData(formRef)).toEqual({
      fields: { sector: 'engineering', services: ['hosting', 'support'] },
      field_shape: [
        { control_name: 'sector', control_type: 'select' },
        { control_name: 'services', control_type: 'select' },
      ],
    });
  });

  it('selects only uniquely identified supported forms and rejects invalid explicit markers', () => {
    const supported = form('contact-form', [control({ name: 'message' })]);
    const invalidMarker = form('other-form', [control({ name: 'message' })], 'invalid marker');
    const unsupported = form('search-form', [control({ name: 'query', type: 'search' })]);

    expect(
      findFormCandidates({ querySelectorAll: () => [supported, invalidMarker, unsupported] }),
    ).toEqual([{ form: supported, marker: 'contact-form' }]);
  });

  it('requests the versioned same-origin resolution without credentials and fails closed', async () => {
    const fetchImpl = vi.fn(async () => ({
      status: 200,
      json: async () => ({
        schema_version: 1,
        public_form_id: 'public-contact',
        configuration_version: 3,
        form_marker: 'contact-form',
      }),
    }));

    await expect(
      resolveForm({
        marker: 'contact-form',
        locationRef: { pathname: '/contact' },
        fetchImpl,
      }),
    ).resolves.toMatchObject({ public_form_id: 'public-contact' });

    expect(fetchImpl).toHaveBeenCalledWith(
      expect.stringContaining(
        '/formvex/api/v1/forms/resolve?schema_version=1&page_path=%2Fcontact&form_marker=contact-form',
      ),
      expect.objectContaining({ method: 'GET', credentials: 'omit' }),
    );

    const mismatch = vi.fn(async () => ({
      status: 200,
      json: async () => ({
        schema_version: 1,
        public_form_id: 'public-other',
        configuration_version: 3,
        form_marker: 'other-form',
      }),
    }));

    await expect(
      resolveForm({
        marker: 'contact-form',
        locationRef: { pathname: '/contact' },
        fetchImpl: mismatch,
      }),
    ).resolves.toBeNull();
  });

  it('creates the approved versioned submission envelope', () => {
    const result = createSubmissionEnvelope(
      form('contact-form', [control({ name: 'name', value: 'Ada' })]),
      {
        public_form_id: 'public-contact',
        configuration_version: 3,
        form_marker: 'contact-form',
      },
      '0195f2b8-7c3a-4f42-8c11-4ac3b865e092',
    );

    expect(result).toEqual({
      schema_version: 1,
      page_path: '/',
      attempt_id: '0195f2b8-7c3a-4f42-8c11-4ac3b865e092',
      configuration_version: 3,
      form_marker: 'contact-form',
      fields: { name: 'Ada' },
      field_shape: [{ control_name: 'name', control_type: 'text' }],
    });
  });

  it('keeps abuse values outside business fields in the versioned envelope', () => {
    const formRef = form('contact-form', [control({ name: 'name', value: 'Ada' })]);
    formRef.__formvexHoneypotControl = { value: 'filled-by-bot' };
    formRef.__formvexCaptchaToken = 'captcha-token';

    expect(
      createSubmissionEnvelope(
        formRef,
        {
          public_form_id: 'public-contact',
          configuration_version: 3,
          form_marker: 'contact-form',
        },
        '0195f2b8-7c3a-4f42-8c11-4ac3b865e092',
      ),
    ).toMatchObject({
      fields: { name: 'Ada' },
      honeypot: 'filled-by-bot',
      captcha_token: 'captcha-token',
    });
  });

  it('accepts the public CAPTCHA resolution and preserves a safe service message', async () => {
    await expect(
      resolveForm({
        marker: 'contact-form',
        locationRef: { pathname: '/' },
        fetchImpl: async () => ({
          status: 200,
          json: async () => ({
            schema_version: 1,
            public_form_id: 'public-contact',
            configuration_version: 3,
            form_marker: 'contact-form',
            captcha: { enabled: true, provider: 'turnstile', site_key: 'site-key' },
          }),
        }),
      }),
    ).resolves.toMatchObject({ captcha: { enabled: true, provider: 'turnstile' } });

    expect(
      mapSubmissionResponse(503, {
        error: { message: 'The form security service is temporarily unavailable.' },
      }),
    ).toMatchObject({
      state: 'uncertain',
      message: 'The form security service is temporarily unavailable.',
    });
  });

  it('maps bounded server field messages as text-safe validation feedback', () => {
    const result = mapSubmissionResponse(422, {
      error: {
        message: '<img src=x onerror=alert(1)>',
        fields: [{ field: 'email', message: '<b>Use a valid email</b>' }],
      },
    });

    expect(result).toEqual({
      state: 'rejected',
      message: '<img src=x onerror=alert(1)>',
      fieldErrors: [{ field: 'email', message: '<b>Use a valid email</b>' }],
    });
  });

  it('does not treat an incomplete acceptance response as success', () => {
    expect(mapSubmissionResponse(202, { schema_version: 1 })).toMatchObject({
      state: 'uncertain',
    });
  });
});
