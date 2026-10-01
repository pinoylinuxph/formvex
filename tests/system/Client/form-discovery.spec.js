import { expect, test } from '@playwright/test';

test('discovers the protected Logoslab form without changing its DOM or collecting values', async ({
  page,
}) => {
  let receivedPayload;

  await page.route('**/formvex/api/v1/discovery/redeem', async (route) => {
    receivedPayload = JSON.parse(route.request().postData() ?? '{}');
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        schema_version: 1,
        candidate_id: '0195f2b8-7c3a-7f42-8c11-4ac3b865e092',
        status: 'pending',
        expires_at: '2026-09-28T12:00:00+00:00',
      }),
    });
  });

  await page.goto('/logoslab#formvex_discovery=one-time-capability');
  const formBefore = await page.locator('#contactForm').evaluate((form) => form.outerHTML);

  await page.addScriptTag({ type: 'module', url: '/build/client.js' });
  await expect(page.locator('[data-formvex-discovery-result]')).toHaveText(
    'Discovery completed. Return to the local administration portal to review the detected forms.',
  );

  const formAfter = await page.locator('#contactForm').evaluate((form) => form.outerHTML);
  expect(formAfter).toBe(formBefore);
  expect(receivedPayload.capability).toBe('one-time-capability');
  expect(receivedPayload.page_path).toBe('/logoslab');
  expect(receivedPayload.forms[0].form_marker).toBe('contactForm');
  expect(JSON.stringify(receivedPayload)).not.toContain('visitor');
  expect(JSON.stringify(receivedPayload)).not.toContain('hello@logoslab.xyz');
  await expect(page).not.toHaveURL(/formvex_discovery/);
});

test('detects all approved controls and reports unsupported controls on a generic multi-form page', async ({
  page,
}) => {
  let receivedPayload;

  await page.route('**/formvex/api/v1/discovery/redeem', async (route) => {
    receivedPayload = JSON.parse(route.request().postData() ?? '{}');
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ schema_version: 1, candidate_id: 'candidate', status: 'pending' }),
    });
  });

  await page.goto('/multi-form#formvex_discovery=generic-capability');
  await page.addScriptTag({ type: 'module', url: '/build/client.js' });
  await expect(page.locator('[data-formvex-discovery-result]')).toHaveAttribute('role', 'status');

  expect(receivedPayload.forms).toHaveLength(2);
  expect(receivedPayload.forms[0].controls.map((control) => control.control_type)).toEqual([
    'text',
    'email',
    'tel',
    'textarea',
    'select',
    'radio',
    'checkbox',
  ]);
  expect(
    receivedPayload.forms[0].controls.find((control) => control.control_name === 'sector').choices,
  ).toEqual([{ value: 'engineering', label: 'Engineering' }]);
  expect(receivedPayload.forms[1].unsupported_controls).toEqual([
    { control_name: 'search', control_type: 'search' },
    { control_name: '', control_type: 'missing_name' },
  ]);
});
