/**
 * AJAX Field Helper Utilities for 404 Solution Plugin Tests
 * 
 * This module provides reusable utilities for interacting with jQuery UI autocomplete
 * fields in the 404 Solution plugin. The plugin uses a custom 'catcomplete' widget
 * that creates dropdowns with categories and clickable items.
 */

/**
 * Configuration options for AJAX field interactions
 * @typedef {Object} AjaxFieldOptions
 * @property {string} fieldSelector - CSS selector for the input field
 * @property {string} triggerText - Text to type to trigger the dropdown (minimum 3 chars recommended)
 * @property {string} [fallbackText] - Fallback text if dropdown fails (defaults to triggerText + 'e')
 * @property {number} [waitForDropdown=2000] - Milliseconds to wait for dropdown to appear
 * @property {string} [selectOption='first'] - Selection strategy: 'first', 'last', or text to match
 * @property {boolean} [debug=false] - Enable debug logging
 */

/**
 * Fill an AJAX autocomplete field by direct selector
 * 
 * @param {Page} page - Playwright page object
 * @param {AjaxFieldOptions} options - Configuration options
 * @returns {Promise<boolean>} True if successful, false otherwise
 */
async function fillAjaxField(page, options) {
  const {
    fieldSelector,
    triggerText,
    fallbackText = triggerText + 'e',
    waitForDropdown = 2000,
    selectOption = 'first',
    debug = false
  } = options;

  if (debug) console.log(`🔍 [ajaxFieldHelper] Filling field: ${fieldSelector}`);
  
  try {
    // Wait for and locate the field
    await page.waitForSelector(fieldSelector, { timeout: 5000 });
    const field = page.locator(fieldSelector);
    
    // Clear and fill the field with trigger text
    await field.fill(triggerText);
    if (debug) console.log(`🔍 [ajaxFieldHelper] Typed trigger text: "${triggerText}"`);
    
    // Wait for dropdown to appear
    await page.waitForTimeout(waitForDropdown);
    
    // Try to interact with the dropdown
    const success = await selectFromDropdown(page, selectOption, debug);
    
    if (!success) {
      if (debug) console.log(`🔍 [ajaxFieldHelper] Dropdown selection failed, using fallback: "${fallbackText}"`);
      await field.fill(fallbackText);
      await page.waitForTimeout(1000);
      return false;
    }
    
    if (debug) console.log(`✅ [ajaxFieldHelper] Successfully selected from dropdown`);
    return true;
    
  } catch (error) {
    if (debug) console.error(`❌ [ajaxFieldHelper] Error filling field: ${error.message}`);
    return false;
  }
}

/**
 * Fill an AJAX field by container and field index
 * 
 * @param {Page} page - Playwright page object
 * @param {Object} options - Configuration options
 * @param {string} options.containerSelector - CSS selector for the container form
 * @param {number} options.fieldIndex - Index of the field within the container
 * @param {string} options.triggerText - Text to trigger the dropdown
 * @param {string} [options.fallbackText] - Fallback text if dropdown fails
 * @param {number} [options.waitForDropdown=2000] - Wait time for dropdown
 * @param {string} [options.selectOption='first'] - Selection strategy
 * @param {boolean} [options.debug=false] - Enable debug logging
 * @returns {Promise<boolean>} True if successful, false otherwise
 */
async function fillAjaxFieldByIndex(page, options) {
  const {
    containerSelector,
    fieldIndex,
    triggerText,
    fallbackText = triggerText + 'e',
    waitForDropdown = 2000,
    selectOption = 'first',
    debug = false
  } = options;

  if (debug) console.log(`🔍 [ajaxFieldHelper] Filling field by index: ${containerSelector} [${fieldIndex}]`);
  
  try {
    // Wait for container and get all text fields
    await page.waitForSelector(containerSelector, { timeout: 5000 });
    const fields = await page.locator(`${containerSelector} input[type="text"]`).all();
    
    if (fieldIndex >= fields.length) {
      if (debug) console.error(`❌ [ajaxFieldHelper] Field index ${fieldIndex} out of bounds (found ${fields.length} fields)`);
      return false;
    }
    
    const field = fields[fieldIndex];
    
    // Clear and fill the field
    await field.fill(triggerText);
    if (debug) console.log(`🔍 [ajaxFieldHelper] Typed trigger text: "${triggerText}" in field ${fieldIndex}`);
    
    // Wait for dropdown to appear
    await page.waitForTimeout(waitForDropdown);
    
    // Try to interact with the dropdown
    const success = await selectFromDropdown(page, selectOption, debug);
    
    if (!success) {
      if (debug) console.log(`🔍 [ajaxFieldHelper] Dropdown selection failed, using fallback: "${fallbackText}"`);
      await field.fill(fallbackText);
      await page.waitForTimeout(1000);
      return false;
    }
    
    if (debug) console.log(`✅ [ajaxFieldHelper] Successfully selected from dropdown`);
    return true;
    
  } catch (error) {
    if (debug) console.error(`❌ [ajaxFieldHelper] Error filling field by index: ${error.message}`);
    return false;
  }
}

/**
 * Fill an AJAX field with fallback context-based selection
 * 
 * @param {Page} page - Playwright page object
 * @param {Object} options - Configuration options
 * @param {string} options.contextText - Text near the field to help locate it
 * @param {string} options.triggerText - Text to trigger the dropdown
 * @param {string} [options.fallbackText] - Fallback text if dropdown fails
 * @param {number} [options.waitForDropdown=2000] - Wait time for dropdown
 * @param {string} [options.selectOption='first'] - Selection strategy
 * @param {boolean} [options.debug=false] - Enable debug logging
 * @returns {Promise<boolean>} True if successful, false otherwise
 */
async function fillAjaxFieldByContext(page, options) {
  const {
    contextText,
    triggerText,
    fallbackText = triggerText + 'e',
    waitForDropdown = 2000,
    selectOption = 'first',
    debug = false
  } = options;

  if (debug) console.log(`🔍 [ajaxFieldHelper] Filling field by context: "${contextText}"`);
  
  try {
    // Find field by context text
    const field = page.locator(`text=${contextText}`).locator('..').locator('input[type="text"]');
    
    // Clear and fill the field
    await field.fill(triggerText);
    if (debug) console.log(`🔍 [ajaxFieldHelper] Typed trigger text: "${triggerText}" in context field`);
    
    // Wait for dropdown to appear
    await page.waitForTimeout(waitForDropdown);
    
    // Try to interact with the dropdown
    const success = await selectFromDropdown(page, selectOption, debug);
    
    if (!success) {
      if (debug) console.log(`🔍 [ajaxFieldHelper] Dropdown selection failed, using fallback: "${fallbackText}"`);
      await field.fill(fallbackText);
      await page.waitForTimeout(1000);
      return false;
    }
    
    if (debug) console.log(`✅ [ajaxFieldHelper] Successfully selected from dropdown`);
    return true;
    
  } catch (error) {
    if (debug) console.error(`❌ [ajaxFieldHelper] Error filling field by context: ${error.message}`);
    return false;
  }
}

/**
 * Select an item from the jQuery UI autocomplete dropdown
 * 
 * @param {Page} page - Playwright page object
 * @param {string} selectOption - Selection strategy: 'first', 'last', or text to match
 * @param {boolean} debug - Enable debug logging
 * @returns {Promise<boolean>} True if successful, false otherwise
 */
async function selectFromDropdown(page, selectOption, debug = false) {
  try {
    // Look for the dropdown
    const dropdown = page.locator('.ui-autocomplete, .ui-menu');
    const isDropdownVisible = await dropdown.isVisible().catch(() => false);
    
    if (!isDropdownVisible) {
      if (debug) console.log(`🔍 [ajaxFieldHelper] Dropdown not visible`);
      return false;
    }
    
    if (debug) {
      const dropdownHTML = await dropdown.innerHTML().catch(() => 'Could not get HTML');
      console.log(`🔍 [ajaxFieldHelper] Dropdown HTML: ${dropdownHTML}`);
    }
    
    // Find clickable items (not category headers)
    const clickableItems = dropdown.locator('li.ui-menu-item .ui-menu-item-wrapper');
    const clickableCount = await clickableItems.count();
    
    if (debug) console.log(`🔍 [ajaxFieldHelper] Found ${clickableCount} clickable items`);
    
    if (clickableCount === 0) {
      return false;
    }
    
    // Select based on strategy
    let targetItem;
    
    if (selectOption === 'first') {
      targetItem = clickableItems.first();
    } else if (selectOption === 'last') {
      targetItem = clickableItems.last();
    } else if (typeof selectOption === 'string') {
      // Try to find item containing the text
      targetItem = clickableItems.filter({ hasText: selectOption }).first();
      const hasMatch = await targetItem.count() > 0;
      if (!hasMatch) {
        if (debug) console.log(`🔍 [ajaxFieldHelper] No item found containing "${selectOption}", using first`);
        targetItem = clickableItems.first();
      }
    } else {
      targetItem = clickableItems.first();
    }
    
    if (debug) {
      const itemText = await targetItem.textContent().catch(() => 'unknown');
      console.log(`🔍 [ajaxFieldHelper] Clicking item: "${itemText}"`);
    }
    
    await targetItem.click();
    return true;
    
  } catch (error) {
    if (debug) console.error(`❌ [ajaxFieldHelper] Error selecting from dropdown: ${error.message}`);
    return false;
  }
}

/**
 * Validate that an AJAX field was successfully filled
 * 
 * @param {Page} page - Playwright page object
 * @param {string} fieldSelector - CSS selector for the field to validate
 * @param {boolean} debug - Enable debug logging
 * @returns {Promise<boolean>} True if field has a value, false otherwise
 */
async function validateAjaxField(page, fieldSelector, debug = false) {
  try {
    const field = page.locator(fieldSelector);
    const value = await field.inputValue();
    const hasValue = value && value.trim().length > 0;
    
    if (debug) {
      console.log(`🔍 [ajaxFieldHelper] Field ${fieldSelector} value: "${value}" (valid: ${hasValue})`);
    }
    
    return hasValue;
    
  } catch (error) {
    if (debug) console.error(`❌ [ajaxFieldHelper] Error validating field: ${error.message}`);
    return false;
  }
}

module.exports = {
  fillAjaxField,
  fillAjaxFieldByIndex,
  fillAjaxFieldByContext,
  selectFromDropdown,
  validateAjaxField
};