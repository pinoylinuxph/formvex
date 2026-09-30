import { expect, test } from '@playwright/test';

const resolution = {
  schema_version: 1,
  public_form_id: 'public-contact',
  configuration_version: 3,
  form_marker: 'contact-form',
};

async function installClient(page, submissionHandler) {
  await page.route('**/formvex/api/v1/forms/resolve*', async (route) => {
    const url = new URL(route.request().url());

    const marker = url.searchParams.get('form_marker');

    if (!['contact-form', 'provided-form'].includes(marker)) {
      await route.fulfill({ status: 404, contentType: 'application/json', body: '{}' });
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        ...resolution,
        public_form_id: marker === 'provided-form' ? 'public-provided' : resolution.public_form_id,
        form_marker: marker,
      }),
    });
  });
  await page.route('**/formvex/api/v1/forms/public-contact/submissions', submissionHandler);
  await page.addScriptTag({ type: 'module', url: '/build/client.js' });
}

test('integrates only the resolved form, preserves its controls, and accepts one deliberate submission', async ({
  page,
}) => {
  const submissions = [];
  const resolutionRequests = [];
  await page.goto('/integration');
  await page
    .context()
    .addCookies([
      { name: 'admin_session', value: 'must-not-cross', domain: '127.0.0.1', path: '/' },
    ]);
  page.on('request', (request) => {
    if (request.url().includes('/formvex/api/v1/forms/resolve')) {
      resolutionRequests.push(request);
    }
  });

  await installClient(page, async (route) => {
    submissions.push({
      body: JSON.parse(route.request().postData() ?? '{}'),
      headers: route.request().headers(),
    });
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({
        schema_version: 1,
        receipt_id: 'receipt-1',
        acknowledgement: 'Message accepted for processing.',
      }),
    });
  });

  const before = await page.locator('#contact-form').evaluate((form) => ({
    id: form.id,
    buttonText: form.querySelector('button').textContent,
    controls: form.querySelectorAll('input').length,
  }));
  await page.locator('#contact-form input[name="name"]').fill('Ada Lovelace');
  await page.locator('#contact-form input[name="email"]').fill('ada@example.test');
  await page.locator('#contact-form input[value="hosting"]').check();

  await page.locator('#contact-form').evaluate((form) => {
    form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
    form.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
  });

  await expect(page.locator('#contact-form [data-formvex-feedback]')).toHaveText(
    'Message accepted for processing.',
  );
  expect(submissions).toHaveLength(1);
  expect(submissions[0].headers.cookie).toBeUndefined();
  expect(submissions[0].body).toMatchObject({
    schema_version: 1,
    page_path: '/integration',
    configuration_version: 3,
    form_marker: 'contact-form',
    fields: {
      name: 'Ada Lovelace',
      email: 'ada@example.test',
      interests: ['hosting'],
    },
  });
  expect(submissions[0].body.attempt_id).toMatch(
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
  );
  expect(resolutionRequests.length).toBeGreaterThan(0);
  expect(resolutionRequests.every((request) => request.method() === 'GET')).toBe(true);
  expect(resolutionRequests.every((request) => request.postData() === null)).toBe(true);
  expect(resolutionRequests.every((request) => !request.url().includes('Ada'))).toBe(true);
  await expect(page.locator('#contact-form input[name="name"]')).toHaveValue('');
  await expect(page.locator('#contact-form button')).toBeEnabled();
  await expect(page.locator('#unrelated-form')).toHaveAttribute('id', 'unrelated-form');
  expect(before).toEqual({ id: 'contact-form', buttonText: 'Send message', controls: 4 });

  await page.locator('#contact-form input[name="name"]').fill('Grace Hopper');
  await page.locator('#contact-form input[name="email"]').fill('grace@example.test');
  await page.locator('#contact-form input[name="name"]').press('Enter');
  await expect(page.locator('#contact-form input[name="name"]')).toHaveValue('');
  expect(submissions).toHaveLength(2);
});

test('preserves values and safely renders validation failures without HTML injection', async ({
  page,
}) => {
  await page.goto('/integration');
  await installClient(page, async (route) => {
    await route.fulfill({
      status: 422,
      contentType: 'application/json',
      body: JSON.stringify({
        error: {
          message: '<img src=x onerror=alert(1)>',
          fields: [{ field: 'email', message: '<b>Use a valid email</b>' }],
        },
      }),
    });
  });

  await page.locator('#contact-form input[name="name"]').fill('<b>Ada</b>');
  await page.locator('#contact-form input[name="email"]').fill('ada@example.test');
  await page.locator('#contact-form button').click();

  await expect(page.locator('#contact-form [data-formvex-feedback]')).toHaveText(
    '<img src=x onerror=alert(1)>',
  );
  await expect(page.locator('#contact-form input[name="name"]')).toHaveValue('<b>Ada</b>');
  await expect(page.locator('#contact-form [data-formvex-field-error]')).toHaveText(
    '<b>Use a valid email</b>',
  );
  await expect(page.locator('#contact-form [data-formvex-feedback] img')).toHaveCount(0);
  await expect(page.locator('#contact-form button')).toBeEnabled();
});

test('shows form-local feedback when native constraint validation blocks submission', async ({
  page,
}) => {
  let submissionCount = 0;
  await page.goto('/integration');
  await installClient(page, async (route) => {
    submissionCount += 1;
    await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
  });

  await page.locator('#contact-form button').click();

  await expect(page.locator('#contact-form [data-formvex-feedback]')).toHaveText(
    'Please correct the highlighted fields and try again.',
  );
  expect(submissionCount).toBe(0);
  await expect(page.locator('#contact-form input[name="name"]')).toBeFocused();
});

test('uses a host feedback element and leaves unsupported unrelated forms untouched', async ({
  page,
}) => {
  await page.goto('/integration');
  await installClient(page, async (route) => {
    await route.fulfill({
      status: 404,
      contentType: 'application/json',
      body: JSON.stringify({}),
    });
  });

  const unrelatedBefore = await page.locator('#unrelated-form').evaluate((form) => form.outerHTML);
  await page.locator('#unrelated-form').evaluate((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      window.unrelatedSubmitted = true;
    });
  });
  await page.locator('#unrelated-form').dispatchEvent('submit');
  const unrelatedSubmitted = await page.evaluate(() => window.unrelatedSubmitted === true);

  expect(unrelatedSubmitted).toBe(true);
  expect(await page.locator('#unrelated-form').evaluate((form) => form.outerHTML)).toBe(
    unrelatedBefore,
  );

  await page.locator('#provided-form input[name="name"]').fill('Ada');
  await page.locator('#provided-form button').click();
  await expect(page.locator('#provided-form [data-formvex-feedback]')).toHaveText(
    'Formvex could not confirm whether your message was received. Check your connection and try again.',
  );
  await expect(page.locator('#provided-form [data-formvex-feedback]')).toHaveAttribute(
    'role',
    'alert',
  );
});

test('preserves the Logoslab form markup and existing submit control when attached', async ({
  page,
}) => {
  await page.route('**/formvex/api/v1/forms/resolve*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ...resolution, form_marker: 'contactForm' }),
    });
  });
  await page.route('**/formvex/api/v1/forms/public-contact/submissions', async (route) => {
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({
        schema_version: 1,
        receipt_id: 'logoslab-receipt',
        acknowledgement: 'Received.',
      }),
    });
  });

  await page.goto('/logoslab');
  const before = await page.locator('#contactForm').evaluate((form) => ({
    className: form.className,
    button: form.querySelector('button[type="submit"]').outerHTML,
    fields: Array.from(form.querySelectorAll('input, select, textarea')).map((control) => ({
      name: control.name,
      type: control.type || control.tagName.toLowerCase(),
    })),
  }));
  await page.addScriptTag({ type: 'module', url: '/build/client.js' });
  const after = await page.locator('#contactForm').evaluate((form) => ({
    className: form.className,
    button: form.querySelector('button[type="submit"]').outerHTML,
    fields: Array.from(form.querySelectorAll('input, select, textarea')).map((control) => ({
      name: control.name,
      type: control.type || control.tagName.toLowerCase(),
    })),
  }));

  expect(after).toEqual(before);
});

test('blocks an existing submit handler while the integration is still loading', async ({
  page,
}) => {
  await page.goto('/integration');
  await page.locator('#contact-form').evaluate((form) => {
    form.addEventListener('submit', () => {
      window.legacySubmitted = true;
    });
  });

  await page.route('**/formvex/api/v1/forms/resolve*', async (route) => {
    await page.waitForTimeout(250);
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ...resolution, form_marker: 'contact-form' }),
    });
  });
  let submissionCount = 0;
  await page.route('**/formvex/api/v1/forms/public-contact/submissions', async (route) => {
    submissionCount += 1;
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({
        schema_version: 1,
        receipt_id: 'loading-guard-receipt',
        acknowledgement: 'Received.',
      }),
    });
  });

  await page.addScriptTag({ type: 'module', url: '/build/client.js' });
  await page.locator('#contact-form input[name="name"]').fill('Ada Lovelace');
  await page.locator('#contact-form input[name="email"]').fill('ada@example.test');
  await page.locator('#contact-form button').click();

  expect(await page.evaluate(() => window.legacySubmitted === true)).toBe(false);
  await expect(page.locator('#contact-form [data-formvex-pending-feedback]')).toHaveText(
    'The form is still connecting. Wait a moment and try again.',
  );

  await expect.poll(() => submissionCount).toBe(0);
  await expect(page.locator('#contact-form [data-formvex-pending-feedback]')).toHaveCount(0);
  await page.locator('#contact-form button').click();
  await expect(page.locator('#contact-form [data-formvex-feedback]')).toHaveText('Received.');
  expect(submissionCount).toBe(1);
});

test('blocks a legacy handler during qualification redemption', async ({ page }) => {
  await page.goto('/integration#formvex_qualification=one-time-capability');
  await page.locator('#contact-form').evaluate((form) => {
    form.addEventListener('submit', () => {
      window.legacySubmitted = true;
    });
  });

  let redemptionStarted = false;
  let qualificationRequestBody;
  let releaseRedemption;
  const redemptionBlocked = new Promise((resolve) => {
    releaseRedemption = resolve;
  });
  await page.route('**/formvex/api/v1/qualification/redeem', async (route) => {
    redemptionStarted = true;
    qualificationRequestBody = JSON.parse(route.request().postData() ?? '{}');
    await redemptionBlocked;
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        schema_version: 1,
        qualification_token: 'qualification-session-token',
        public_form_id: 'public-contact',
        configuration_version: 3,
        form_marker: 'contact-form',
        captcha: { enabled: false, provider: 'turnstile', site_key: '' },
      }),
    });
  });
  let qualificationSubmissionCount = 0;
  await page.route('**/formvex/api/v1/qualification/submissions', async (route) => {
    qualificationSubmissionCount += 1;
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({
        schema_version: 1,
        receipt_id: 'qualification-loading-guard-receipt',
        acknowledgement: 'Received.',
      }),
    });
  });

  await page.addScriptTag({ type: 'module', url: '/build/client.js' });
  await expect.poll(() => redemptionStarted).toBe(true);
  expect(qualificationRequestBody).toMatchObject({
    schema_version: 1,
    page_path: '/integration',
  });
  expect(qualificationRequestBody.forms[0]).toMatchObject({ form_marker: 'contact-form' });
  expect(qualificationRequestBody.forms.every((form) => !Object.hasOwn(form, 'form'))).toBe(true);
  await page.locator('#contact-form input[name="name"]').fill('Ada Lovelace');
  await page.locator('#contact-form input[name="email"]').fill('ada@example.test');
  await page.locator('#contact-form button').click();

  expect(await page.evaluate(() => window.legacySubmitted === true)).toBe(false);
  await expect(page.locator('#contact-form [data-formvex-pending-feedback]')).toHaveText(
    'Qualification is still loading. Wait until the page says it is ready, then try again.',
  );
  releaseRedemption();
  await expect(page.locator('[data-formvex-qualification-result]')).toHaveText(
    'Qualification is ready. Enter synthetic test values and submit the existing form button or keyboard path.',
  );

  await page.locator('#contact-form button').click();
  await expect(page.locator('#contact-form [data-formvex-feedback]')).toHaveText('Received.');
  expect(qualificationSubmissionCount).toBe(1);
});
