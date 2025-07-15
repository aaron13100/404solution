/**
 * Usage Examples for AJAX Field Helper
 * 
 * This file demonstrates how to use the ajaxFieldHelper utility for different
 * AJAX fields in the 404 Solution plugin.
 */

const { fillAjaxField, fillAjaxFieldByIndex, fillAjaxFieldByContext } = require('./ajaxFieldHelper');

// Example 1: Manual Redirect Destination Field (current implementation)
async function fillRedirectDestination(page) {
  return await fillAjaxFieldByIndex(page, {
    containerSelector: 'form[name="add-manual-redirect-top"]',
    fieldIndex: 1,
    triggerText: 'hom',
    fallbackText: 'home',
    selectOption: 'first',
    debug: false
  });
}

// Example 2: Logs Search Field (for future tests)
async function fillLogsSearch(page, searchTerm) {
  return await fillAjaxField(page, {
    fieldSelector: '#logs_ajax_search_field',
    triggerText: searchTerm,
    selectOption: 'first',
    debug: true
  });
}

// Example 3: Any field by context (flexible approach)
async function fillFieldByLabel(page, labelText, triggerText) {
  return await fillAjaxFieldByContext(page, {
    contextText: labelText,
    triggerText: triggerText,
    selectOption: 'first',
    waitForDropdown: 1500,
    debug: false
  });
}

// Example 4: Advanced usage with custom selection
async function fillRedirectWithSpecificPage(page, pageTitle) {
  return await fillAjaxFieldByIndex(page, {
    containerSelector: 'form[name="add-manual-redirect-top"]',
    fieldIndex: 1,
    triggerText: pageTitle.substring(0, 3), // First 3 chars
    selectOption: pageTitle, // Try to find page with this title
    waitForDropdown: 3000,
    debug: true
  });
}

// Example 5: Using in a test with validation
async function testRedirectCreation(page) {
  // Fill the source URL
  await page.fill('input[placeholder*="404solution"]', '/test-404-page');
  
  // Fill destination with AJAX helper
  const success = await fillAjaxFieldByIndex(page, {
    containerSelector: 'form[name="add-manual-redirect-top"]',
    fieldIndex: 1,
    triggerText: 'hom',
    selectOption: 'Home Page', // Try to find "Home Page" specifically
    debug: true
  });
  
  if (!success) {
    console.log('AJAX field filling failed, using fallback');
    // Fallback approach
    const destField = page.locator('text=Redirect to:').locator('..').locator('input[type="text"]');
    await destField.fill('home');
  }
  
  // Submit form
  await page.click('input[value="Add Redirect"]');
}

module.exports = {
  fillRedirectDestination,
  fillLogsSearch,
  fillFieldByLabel,
  fillRedirectWithSpecificPage,
  testRedirectCreation
};