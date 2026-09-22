import { expect, test } from '@playwright/test';

test('the local shell applies themes and preserves sidebar interaction state', async ({ page }) => {
  await page.goto('/spoke-shell');

  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
  await page.getByRole('button', { name: /Theme:/ }).click();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await expect(page.getByText('Dark', { exact: true })).toBeVisible();

  await page.getByRole('button', { name: 'Collapse navigation' }).click();
  await expect(page.locator('body')).toHaveAttribute('data-sidebar-state', 'collapsed');

  await page.setViewportSize({ width: 600, height: 800 });
  await page.getByRole('button', { name: 'Open navigation' }).click();
  await expect(page.locator('body')).toHaveClass(/fv-sidebar-open/);
  await expect(page.locator('[data-sidebar-close]')).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(page.locator('[data-sidebar] a').last()).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.locator('[data-sidebar-close]')).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.locator('body')).not.toHaveClass(/fv-sidebar-open/);
});
