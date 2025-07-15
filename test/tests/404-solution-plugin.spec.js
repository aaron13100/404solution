// @ts-check
const { test, expect } = require('@playwright/test');

test.describe('404 Solution Plugin Tests', () => {
  // Setup: Login before each test
  test.beforeEach(async ({ page }) => {
    // Login to WordPress admin
    await page.goto('./wp-admin');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**');
  });

  test('should access 404 Solution plugin admin page', async ({ page }) => {
    // Navigate to 404 Solution plugin page
    await page.goto('./wp-admin/options-general.php?page=404-solution');

    // Verify we're on the plugin page
    await expect(page.locator('h1')).toContainText('404 Solution');
    
    // Check for main plugin elements
    await expect(page.locator('#abj404_settings')).toBeVisible();
  });

  test('should show plugin in plugins list', async ({ page }) => {
    // Go to plugins page
    await page.goto('./wp-admin/plugins.php');

    // Look for 404 Solution plugin
    await expect(page.locator('[data-slug="404-solution"]')).toBeVisible();
    await expect(page.getByText('404 Solution')).toBeVisible();
  });

  test('should display main settings sections', async ({ page }) => {
    await page.goto('./wp-admin/options-general.php?page=404-solution');

    // Check for main settings tabs/sections
    await expect(page.locator('text=General')).toBeVisible();
    await expect(page.locator('text=Captured')).toBeVisible();
    await expect(page.locator('text=Redirects')).toBeVisible();
  });

  test('should allow creating a manual redirect', async ({ page }) => {
    await page.goto('./wp-admin/options-general.php?page=404-solution');

    // Switch to Redirects tab if not already there
    await page.click('text=Redirects');

    // Look for the add redirect form
    await expect(page.locator('#abj404_redirect_from')).toBeVisible();
    await expect(page.locator('#abj404_redirect_to')).toBeVisible();

    // Fill in a test redirect
    await page.fill('#abj404_redirect_from', '/test-404-page');
    await page.fill('#abj404_redirect_to', '/');
    
    // Submit the form
    await page.click('#abj404_addRedirect');

    // Should see success message or redirect in list
    // Note: This might need adjustment based on actual plugin behavior
    await expect(page.locator('text=/test-404-page')).toBeVisible();
  });

  test('should handle 404 pages and show suggestions', async ({ page }) => {
    // Visit a non-existent page to trigger 404
    const response = await page.goto('./this-page-does-not-exist', { 
      waitUntil: 'networkidle' 
    });

    // Should return 404 status
    expect(response.status()).toBe(404);

    // The page should contain 404 content
    // This will depend on how the theme handles 404 pages
    await expect(page.locator('body')).toContainText(/404|not found/i);
  });

  test('should show logs in admin panel', async ({ page }) => {
    await page.goto('./wp-admin/options-general.php?page=404-solution');

    // Switch to Captured URLs tab
    await page.click('text=Captured');

    // Should see the logs table or empty state
    await expect(page.locator('.abj404_table, .abj404_norecords')).toBeVisible();
  });
});