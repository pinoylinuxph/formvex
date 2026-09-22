import { expect, test } from '@playwright/test';

test('the production website-client entry point loads without a browser error', async ({
  page,
}) => {
  const pageErrors = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));

  await page.goto('/fixture');
  const packageName = await page.evaluate(async () => {
    const module = await import('/build/client.js');
    return module.clientPackage;
  });

  await expect(
    page.getByRole('heading', { name: 'Formvex structural asset fixture' }),
  ).toBeVisible();
  expect(packageName).toBe('@formvex/client');
  expect(pageErrors).toEqual([]);
});
