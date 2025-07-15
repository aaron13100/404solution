// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('WordPress Admin Login', () => {
  test('should login to WordPress admin', async ({ page }) => {
    // Navigate to WordPress admin login
    await page.goto('./wp-admin');

    // Check if we're on the login page
    await expect(page.locator('#loginform')).toBeVisible();

    // Fill in login credentials
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');

    // Click login button
    await page.click('#wp-submit');

    // Wait for redirect to admin dashboard
    await page.waitForURL('**/wp-admin/**');

    // Verify we're in the admin area
    await expect(page.locator('#wpadminbar')).toBeVisible();
    await expect(page.locator('#adminmenumain')).toBeVisible();
  });

  test('should show error for invalid credentials', async ({ page }) => {
    await page.goto('./wp-admin');

    await page.fill('#user_login', 'invalid');
    await page.fill('#user_pass', 'wrongpassword');
    await page.click('#wp-submit');

    // Should see error message
    await expect(page.locator('#login_error')).toBeVisible();
    await expect(page.locator('#login_error')).toContainText('ERROR');
  });
});