# 404 Solution Plugin - E2E Testing

This directory contains the end-to-end testing infrastructure for the 404 Solution WordPress plugin using Playwright and WP-CLI.

## Setup

The testing infrastructure uses Playwright for E2E testing with WP-CLI for fast WordPress environment setup.

### Prerequisites

- Node.js and npm installed
- MAMP running with MySQL on default port (8889)
- WordPress installation at the configured path
- WP-CLI installed globally

### One-Time Installation

1. **Install Playwright:**
   ```bash
   npm install -D @playwright/test
   npx playwright install
   ```

2. **Install WP-CLI:** (Already done)
   ```bash
   curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
   chmod +x wp-cli.phar
   sudo mv wp-cli.phar /usr/local/bin/wp
   ```

3. **Verify test environment setup:**
   ```bash
   ./run-e2e-tests.sh --setup-wordpress
   ```

## Architecture

### Directory Structure
```
404-solution/
├── test-wp/                    # WordPress test installation
│   ├── wp-config.php          # Points to wordpress_test DB
│   └── wp-content/
│       └── plugins/
│           └── 404-solution/  # Symlink to main plugin
├── run-e2e-tests.sh          # Test runner script
├── tests/                     # Playwright tests
│   ├── example.spec.js        # Basic connectivity tests
│   ├── wordpress-login.spec.js # WordPress admin login
│   └── 404-solution-plugin.spec.js # Plugin functionality
└── playwright.config.js      # Playwright configuration
```

### Configuration Files

- `wp-config-dev.php` - Development configuration pointing to '404-solution' database
- `wp-config-test.php` - Test configuration pointing to 'wordpress_test' database
- `test-wp/wp-config.php` - Test WordPress configuration (auto-generated)

## Running Tests

### Full Test Suite (Recommended)
```bash
npm test
# OR
./run-e2e-tests.sh
```

This will:
1. Backup your current wp-config.php
2. Switch to test configuration
3. Reset the test database
4. Install fresh WordPress tables via WP-CLI
5. Activate the 404 Solution plugin
6. Run all Playwright tests
7. Restore your original configuration

### Test Options
```bash
# Run tests with browser visible (debugging)
npm run test:headed

# Run tests with interactive UI
npm run test:ui

# View test report after running
npm run test:report

# Only run tests (skip WordPress setup)
./run-e2e-tests.sh --test-only

# Show help
./run-e2e-tests.sh --help
```

## Test Runner Features

### Fast WordPress Setup
The test runner uses WP-CLI for lightning-fast WordPress environment setup:
- **Database reset**: Drops and recreates test database (seconds)
- **WordPress install**: Creates fresh tables only (no file downloads)
- **Plugin activation**: Automatically activates 404 Solution plugin
- **Known credentials**: admin/password for consistent testing

### Safe Configuration Management
- Automatic backup and restore of wp-config.php
- Cleanup runs even if tests fail or are interrupted (Ctrl+C)
- Uses separate test database for complete isolation

### Error Handling
- Comprehensive error checking and reporting
- Colored output for easy status identification
- Detailed help documentation

## Test Coverage

The current test suite covers:

### WordPress Core
- Home page loading and title verification
- Admin login functionality with valid/invalid credentials
- Plugin listing and activation status

### 404 Solution Plugin
- Plugin admin page accessibility
- Settings interface display
- Manual redirect creation
- 404 page handling and suggestions
- Admin logs display

## Configuration

### Playwright Configuration (playwright.config.js)
- **Base URL**: `http://localhost:8888/404solution-site/`
- **Browsers**: Chrome, Firefox, Safari
- **Screenshots/videos**: Captured on failure
- **Retries**: Configured for CI environments

### Database Configuration
- **Development DB**: `404-solution`
- **Test DB**: `wordpress_test`
- **Credentials**: root/root
- **Host**: localhost:8889 (MAMP default)

## Extending Tests

### Adding New Tests
1. Create new `.spec.js` files in the `tests/` directory
2. Use relative URLs with `./` prefix (e.g., `await page.goto('./wp-admin')`)
3. Follow existing patterns for login and navigation
4. Tests should be independent and not rely on specific data

### Test Best Practices
- Each test run gets a fresh WordPress installation
- Use known admin credentials (admin/password)
- Tests should clean up after themselves
- Use descriptive test names and comments

## Troubleshooting

### Common Issues

1. **WP-CLI Memory Errors**
   ```bash
   # Run with increased memory
   php -d memory_limit=256M /usr/local/bin/wp core download
   ```

2. **Database Connection Issues**
   - Verify MAMP is running
   - Check MySQL socket path in configurations
   - Ensure database credentials are correct

3. **Plugin Not Found Errors**
   - Verify symlink exists: `ls -la test-wp/wp-content/plugins/`
   - Check plugin directory structure

4. **Test Navigation Failures**
   - Ensure baseURL is correct in playwright.config.js
   - Use relative URLs with `./` prefix in tests

### Debug Tests
```bash
# Run single test with browser visible
npx playwright test tests/404-solution-plugin.spec.js --headed --debug

# Generate test code interactively
npx playwright codegen localhost:8888/404solution-site/

# View detailed test report
npm run test:report
```

## CI/CD Integration

The test suite is designed for CI environments:
- Set `CI=true` environment variable for CI-specific behavior
- Tests run in parallel with automatic retries
- HTML report and artifacts generated on failure
- WP-CLI provides consistent, fast environment setup

## Performance

### Fast Test Runs
- **WordPress setup**: ~5-10 seconds (WP-CLI database operations)
- **No file downloads**: WordPress core files reused across test runs
- **Database isolation**: Clean state for every test run
- **Parallel execution**: Multiple tests run simultaneously

### One-Time Setup vs Per-Test Operations
- **One-time**: WordPress file download, WP-CLI installation, plugin symlink
- **Per-test**: Database reset, WordPress table creation, plugin activation