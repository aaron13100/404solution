// @ts-check
const { test, expect } = require('@playwright/test');

test.describe.serial('404 Solution Plugin Tests', () => {
  // Setup: Login before each test
  test.beforeEach(async ({ page }) => {
    // Try to go directly to wp-admin first to see if already logged in
    await page.goto('./wp-admin');
    
    // Check if we're already logged in by looking for admin elements
    const isLoggedIn = await page.locator('#wpadminbar, #adminmenu').first().isVisible().catch(() => false);
    
    if (!isLoggedIn) {
      // If not logged in, look for login form and log in
      await page.waitForSelector('#loginform', { timeout: 10000 });
      
      await page.fill('#user_login', 'admin');
      await page.fill('#user_pass', 'password');
      await page.click('#wp-submit');
      
      // Wait for successful login
      await page.waitForSelector('#wpadminbar, #adminmenu', { timeout: 15000 });
    }
  });

  test('should access 404 Solution plugin admin page', async ({ page }) => {
    // Navigate to 404 Solution plugin page
    await page.goto('./wp-admin/options-general.php?page=abj404_solution');
    
    // Wait for page to load completely
    await page.waitForLoadState('networkidle');

    // Verify we're on the plugin page - look for the plugin title in the page content
    await expect(page.locator('text=404 Solution').first()).toBeVisible();
    
    // Check for main plugin elements (tabs)
    await expect(page.locator('text=Page Redirects')).toBeVisible();
    await expect(page.locator('text=Add a Manual Redirect')).toBeVisible();
  });

  test('should show plugin in plugins list', async ({ page }) => {
    // Go to plugins page
    await page.goto('./wp-admin/plugins.php');
    
    // Wait for plugins page to load
    await page.waitForLoadState('networkidle');

    // Look for 404 Solution plugin - use more specific selector to avoid multiple matches
    await expect(page.locator('tr').filter({ hasText: '404 Solution' })).toBeVisible();
    
    // Verify plugin is active or present in list
    await expect(page.locator('[data-slug="404-solution"], tr:has-text("404 Solution")')).toBeVisible();
  });

  test('should display main settings sections', async ({ page }) => {
    await page.goto('./wp-admin/options-general.php?page=abj404_solution');
    
    // Wait for page to load completely
    await page.waitForLoadState('networkidle');

    // Check for main settings tabs/sections
    await expect(page.locator('text=Page Redirects')).toBeVisible();
    await expect(page.locator('text=Captured 404 URLs')).toBeVisible();
    await expect(page.locator('text=Options')).toBeVisible();
  });

  test('should allow creating a manual redirect', async ({ page }) => {
    await page.goto('./wp-admin/options-general.php?page=abj404_solution');
    
    // Wait for page to load completely
    await page.waitForLoadState('networkidle');

    // Should be on the Page Redirects tab by default
    await expect(page.locator('text=Page Redirects')).toBeVisible();

    // Look for the add redirect form fields
    await page.waitForSelector('input[placeholder*="404solution"]', { timeout: 10000 });
    
    // Fill in the URL field
    await page.fill('input[placeholder*="404solution"]', '/test-404-page');
    
    // Fill in the redirect destination field - it uses AJAX dropdown after 3+ characters
    // Wait for and fill the "Redirect to:" field
    await page.waitForSelector('form[name="add-manual-redirect-top"] input[type="text"]', { timeout: 5000 });
    const redirectFields = await page.locator('form[name="add-manual-redirect-top"] input[type="text"]').all();
    if (redirectFields.length >= 2) {
      // Type at least 3 characters to trigger AJAX dropdown
      await redirectFields[1].fill('hom');
      
      // Wait for AJAX dropdown to appear
      await page.waitForTimeout(2000);
      
      // Check if dropdown appeared and select from it
      const dropdown = page.locator('.ui-autocomplete, .ui-menu');
      const isDropdownVisible = await dropdown.isVisible().catch(() => false);
      
      if (isDropdownVisible) {
        // Look for clickable menu items (not category headers)
        const clickableItems = dropdown.locator('li.ui-menu-item .ui-menu-item-wrapper');
        const clickableCount = await clickableItems.count();
        
        if (clickableCount > 0) {
          await clickableItems.first().click();
        } else {
          await redirectFields[1].fill('home');
        }
      } else {
        await redirectFields[1].fill('home');
        await page.waitForTimeout(1000);
      }
    } else {
      // Fallback: try to find the field by its context
      const destField = page.locator('text=Redirect to:').locator('..').locator('input[type="text"]');
      await destField.fill('home');
      await page.waitForTimeout(1000);
    }
    
    // Submit the form using the "Add Redirect" button
    await page.click('input[value="Add Redirect"]');

    // Wait for page reload and check for success or redirect in list
    await page.waitForLoadState('networkidle');
    
    // Look for the URL in the redirects table - should appear in the table
    // Use .first() to handle multiple matches from previous test runs
    await expect(page.locator('text=/test-404-page/').first()).toBeVisible();
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
    await page.goto('./wp-admin/options-general.php?page=abj404_solution');

    // Switch to Captured 404 URLs tab
    await page.click('text=Captured 404 URLs');
    
    // Wait for the page to load
    await page.waitForLoadState('networkidle');

    // Should see the logs table or empty state message
    // Look for either the table or the "No Captured 404 Records" message
    const hasTable = await page.locator('table').isVisible();
    const hasEmptyMessage = await page.locator('text=No Captured 404 Records To Display').isVisible();
    
    expect(hasTable || hasEmptyMessage).toBe(true);
  });
});