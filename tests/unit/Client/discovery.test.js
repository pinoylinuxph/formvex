import { describe, expect, it } from 'vitest';

import {
  collectDiscoveryMetadata,
  discoverParameterSuggestions,
  runDiscovery,
} from '../../../packages/client/src/discovery.js';

function form(id) {
  return {
    getAttribute(name) {
      return name === 'id' ? id : null;
    },
    querySelectorAll() {
      return [];
    },
  };
}

describe('authorized form discovery client', () => {
  it('returns deterministic built-in suggestions without reading control values', () => {
    expect(discoverParameterSuggestions('email', 'customer_email', 'Email address')).toEqual([
      'email',
    ]);
    expect(discoverParameterSuggestions('text', 'contact_name', 'Full name')).toEqual([
      'full_name',
    ]);
    expect(discoverParameterSuggestions('text', 'details', 'Message')).toEqual(['message']);
  });

  it('reports more than the approved page form limit instead of silently dropping forms', () => {
    const forms = Array.from({ length: 26 }, (_, index) => form(`form-${index + 1}`));
    const payload = collectDiscoveryMetadata(
      { querySelectorAll: () => forms },
      { pathname: '/contact' },
    );

    expect(payload.forms).toHaveLength(26);
    expect(payload.forms.at(-1)).toMatchObject({
      form_marker: 'formvex-discovery-overflow',
      ambiguous: true,
      unsupported_controls: [{ control_name: '', control_type: 'form_count_exceeded' }],
    });
  });

  it('removes the discovery capability from the URL and sends metadata only', async () => {
    let requestBody = '';
    const resultNotice = { textContent: '', setAttribute() {} };
    const documentRef = {
      title: 'Contact',
      querySelectorAll: () => [],
      createElement: () => resultNotice,
      body: { append() {} },
    };
    const locationRef = {
      hash: '#formvex_discovery=one-time-capability',
      pathname: '/contact',
      search: '?source=home',
    };
    const windowRef = {
      location: locationRef,
      history: {
        replaceState: (...args) => {
          windowRef.replacement = args;
        },
      },
    };

    const result = await runDiscovery({
      documentRef,
      windowRef,
      fetchImpl: async (_url, options) => {
        requestBody = options.body;

        return {
          ok: true,
          json: async () => ({ schema_version: 1, candidate_id: 'candidate' }),
        };
      },
    });

    expect(result.candidate_id).toBe('candidate');
    expect(requestBody).toContain('one-time-capability');
    expect(requestBody).not.toContain('visitor');
    expect(windowRef.replacement[2]).toBe('/contact?source=home');
  });
});
