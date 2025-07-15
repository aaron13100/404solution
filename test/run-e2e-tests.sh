#!/bin/bash

# run-e2e-tests.sh - E2E Test Runner for 404 Solution Plugin
# This script uses WP-CLI to set up a fresh WordPress test environment

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
WORDPRESS_ROOT="/Users/user/Documents/htdocs/404solution-site"
PLUGIN_ROOT="$WORDPRESS_ROOT/wp-content/plugins/404-solution"
TEST_ROOT="$PLUGIN_ROOT/test"
TEST_WP_ROOT="$TEST_ROOT/test-wp"
MYSQL_SOCKET="/Applications/MAMP/tmp/mysql/mysql.sock"
DB_USER="root"
DB_PASS="root"
DB_HOST="localhost:8889"
TEST_DB="wordpress_test"
TEST_URL="http://localhost:8888/404solution-site/"

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Function to backup current wp-config.php
backup_config() {
    print_status "Backing up current wp-config.php..."
    if [ -f "$WORDPRESS_ROOT/wp-config.php" ]; then
        cp "$WORDPRESS_ROOT/wp-config.php" "$WORDPRESS_ROOT/wp-config.backup.php"
        print_success "Configuration backed up"
    else
        print_error "wp-config.php not found!"
        exit 1
    fi
}

# Function to switch to test configuration
switch_to_test_config() {
    print_status "Switching to test configuration..."
    if [ -f "$WORDPRESS_ROOT/wp-config-test.php" ]; then
        cp "$WORDPRESS_ROOT/wp-config-test.php" "$WORDPRESS_ROOT/wp-config.php"
        print_success "Switched to test configuration"
    else
        print_error "wp-config-test.php not found!"
        exit 1
    fi
}

# Function to restore development configuration
restore_dev_config() {
    print_status "Restoring development configuration..."
    if [ -f "$WORDPRESS_ROOT/wp-config-dev.php" ]; then
        cp "$WORDPRESS_ROOT/wp-config-dev.php" "$WORDPRESS_ROOT/wp-config.php"
        print_success "Restored development configuration"
    elif [ -f "$WORDPRESS_ROOT/wp-config.backup.php" ]; then
        cp "$WORDPRESS_ROOT/wp-config.backup.php" "$WORDPRESS_ROOT/wp-config.php"
        print_success "Restored configuration from backup"
    else
        print_error "No development configuration found to restore!"
        exit 1
    fi
}

# Function to setup fresh test WordPress
setup_test_wordpress() {
    print_status "Setting up fresh WordPress test environment..."
    
    # Drop and recreate test database
    print_status "Resetting test database..."
    mysql -u "$DB_USER" -p"$DB_PASS" -S "$MYSQL_SOCKET" -e "DROP DATABASE IF EXISTS $TEST_DB; CREATE DATABASE $TEST_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    print_success "Test database reset"
    
    # Install WordPress core tables using the main WordPress installation
    print_status "Installing WordPress core tables..."
    
    # Temporarily switch main site to test database
    cp "$WORDPRESS_ROOT/wp-config-test.php" "$WORDPRESS_ROOT/wp-config.php"
    
    # Use main WordPress installation to set up the test database
    cd "$WORDPRESS_ROOT"
    php -d memory_limit=256M /usr/local/bin/wp core install \
        --url="$TEST_URL" \
        --title="Test Site" \
        --admin_user="admin" \
        --admin_password="password" \
        --admin_email="test@example.com" \
        --locale="en_US" \
        --skip-email \
        --allow-root 2>/dev/null || true
    
    # Update site URLs to ensure they're correct
    php -d memory_limit=256M /usr/local/bin/wp option update home "$TEST_URL" --allow-root 2>/dev/null || true
    php -d memory_limit=256M /usr/local/bin/wp option update siteurl "$TEST_URL" --allow-root 2>/dev/null || true
    
    print_success "WordPress core tables installed"
    
    # Activate 404 Solution plugin
    print_status "Activating 404 Solution plugin..."
    php -d memory_limit=256M /usr/local/bin/wp plugin activate 404-solution --allow-root 2>/dev/null || true
    print_success "404 Solution plugin activated"
}

# Function to run Playwright tests
run_tests() {
    print_status "Running Playwright tests..."
    cd "$TEST_ROOT"
    
    if npm list @playwright/test --prefix="$PLUGIN_ROOT" > /dev/null 2>&1; then
        npx playwright test
        TEST_EXIT_CODE=$?
        
        if [ $TEST_EXIT_CODE -eq 0 ]; then
            print_success "All tests passed!"
        else
            print_error "Tests failed with exit code $TEST_EXIT_CODE"
        fi
        
        return $TEST_EXIT_CODE
    else
        print_error "Playwright not installed. Run 'npm install -D @playwright/test' first."
        exit 1
    fi
}

# Function to clean up on exit
cleanup() {
    print_status "Cleaning up..."
    restore_dev_config
    
    # Remove backup file
    if [ -f "$WORDPRESS_ROOT/wp-config.backup.php" ]; then
        rm "$WORDPRESS_ROOT/wp-config.backup.php"
    fi
    
    print_success "Cleanup completed"
}

# Set trap to ensure cleanup runs on exit
trap cleanup EXIT

# Main execution
main() {
    print_status "Starting E2E test run for 404 Solution Plugin"
    print_status "================================================="
    
    # Check if we're in the right directory
    if [ ! -f "$PLUGIN_ROOT/404-solution.php" ]; then
        print_error "Plugin not found at $PLUGIN_ROOT"
        exit 1
    fi
    
    # Check if test WordPress exists
    if [ ! -d "$TEST_WP_ROOT" ]; then
        print_error "Test WordPress not found at $TEST_WP_ROOT"
        print_error "Run the one-time setup first"
        exit 1
    fi
    
    # Backup current config
    backup_config
    
    # Switch to test configuration
    switch_to_test_config
    
    # Setup fresh WordPress test environment
    setup_test_wordpress
    
    # Give the database a moment to settle
    sleep 2
    
    # Run tests
    run_tests
    TEST_RESULT=$?
    
    print_status "================================================="
    if [ $TEST_RESULT -eq 0 ]; then
        print_success "E2E test run completed successfully!"
    else
        print_error "E2E test run failed!"
    fi
    
    exit $TEST_RESULT
}

# Parse command line arguments
case "${1:-}" in
    --help|-h)
        echo "Usage: $0 [options]"
        echo ""
        echo "Options:"
        echo "  --help, -h          Show this help message"
        echo "  --setup-wordpress   One-time setup of test WordPress"
        echo "  --test-only         Only run tests (skip WordPress setup)"
        echo ""
        echo "This script will:"
        echo "1. Backup current wp-config.php"
        echo "2. Switch to test configuration"
        echo "3. Reset database and install fresh WordPress"
        echo "4. Activate 404 Solution plugin"
        echo "5. Run Playwright tests"
        echo "6. Restore original configuration"
        echo ""
        echo "Prerequisites:"
        echo "- WP-CLI installed and available"
        echo "- Test WordPress in ./test-wp directory"
        echo "- wp-config-test.php configured"
        exit 0
        ;;
    --setup-wordpress)
        print_status "One-time WordPress test environment setup"
        if [ ! -d "$TEST_WP_ROOT" ]; then
            print_error "Test WordPress directory not found at $TEST_WP_ROOT"
            print_error "Please run from the test directory"
            exit 1
        fi
        print_success "Test WordPress found at $TEST_WP_ROOT"
        print_success "Ready for test runs!"
        exit 0
        ;;
    --test-only)
        run_tests
        exit $?
        ;;
    "")
        main
        ;;
    *)
        print_error "Unknown option: $1"
        echo "Use --help for usage information"
        exit 1
        ;;
esac