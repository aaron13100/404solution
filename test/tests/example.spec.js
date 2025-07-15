// @ts-check
const { test, expect } = require('@playwright/test');

test('has title', async ({ page }) => {
  await page.goto('./');

  // Expect a title "to contain" a substring.
  await expect(page).toHaveTitle(/WordPress/);
});

test('WordPress home page loads', async ({ page }) => {
  await page.goto('./');

  // Expect the page to contain WordPress content
  await expect(page.locator('body')).toBeVisible();
});