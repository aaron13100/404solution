const { chromium } = require('@playwright/test');

(async () => {
  // First check which config is active
  const fs = require('fs');
  const configContent = fs.readFileSync('/Users/user/Documents/htdocs/404solution-site/wp-config.php', 'utf8');
  console.log('Current database name in config:', configContent.match(/DB_NAME['"]\s*,\s*['"]([^'"]+)['"]/)?.[1] || 'NOT FOUND');
  
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  
  console.log('Navigating to WordPress home page...');
  await page.goto('http://localhost:8888/404solution-site/');
  
  const title = await page.title();
  console.log('Home page title:', title);
  
  console.log('Navigating to WordPress admin...');
  await page.goto('http://localhost:8888/404solution-site/wp-admin/');
  
  const adminTitle = await page.title();
  console.log('Admin page title:', adminTitle);
  
  // Take a screenshot
  await page.screenshot({ path: 'test/admin-page.png' });
  
  // Check for different page elements
  const loginForm = await page.$('#loginform');
  const updateButton = await page.$('.button-primary');
  const adminBar = await page.$('#wpadminbar');
  
  console.log('Login form found:', !!loginForm);
  console.log('Update button found:', !!updateButton);
  console.log('Admin bar found:', !!adminBar);
  
  if (updateButton) {
    console.log('Update button text:', await updateButton.innerText());
  }
  
  await browser.close();
})();