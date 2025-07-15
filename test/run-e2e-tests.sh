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
TEST_DOCROOT="/Users/user/Documents/htdocs/404solution-test"
MYSQL_SOCKET="/Applications/MAMP/tmp/mysql/mysql.sock"
DB_USER="root"
DB_PASS="root"
DB_HOST="localhost:8889"
TEST_DB="wordpress_test"
TEST_URL="http://localhost:8888/404solution-test/"

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
    print_status "Setting up fresh WordPress test environment in Apache document root..."
    
    # Step 1: Put fresh install inside Apache's doc-root
    print_status "Creating fresh WordPress installation in $TEST_DOCROOT..."
    rm -rf "$TEST_DOCROOT"
    
    # Download WordPress core
    php -d memory_limit=256M /usr/local/bin/wp core download --path="$TEST_DOCROOT" --allow-root || {
        print_error "Failed to download WordPress core!"
        exit 1
    }
    
    # Create database
    print_status "Resetting test database..."
    mysql -u "$DB_USER" -p"$DB_PASS" -S "$MYSQL_SOCKET" -e "DROP DATABASE IF EXISTS $TEST_DB; CREATE DATABASE $TEST_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    print_success "Test database reset"
    
    # Create wp-config.php with proper database credentials
    print_status "Creating WordPress configuration..."
    php -d memory_limit=256M /usr/local/bin/wp config create \
        --path="$TEST_DOCROOT" \
        --dbname="$TEST_DB" \
        --dbuser="$DB_USER" \
        --dbpass="$DB_PASS" \
        --dbhost="$DB_HOST" \
        --extra-php="
// Override socket path for MAMP
ini_set('mysqli.default_socket', '/Applications/MAMP/tmp/mysql/mysql.sock');
ini_set('pdo_mysql.default_socket', '/Applications/MAMP/tmp/mysql/mysql.sock');

// WordPress Language
define('WPLANG', 'en_US');" \
        --allow-root || {
            print_error "Failed to create wp-config.php!"
            exit 1
        }
    
    # Install WordPress core
    print_status "Installing WordPress core..."
    php -d memory_limit=256M /usr/local/bin/wp core install \
        --path="$TEST_DOCROOT" \
        --url="$TEST_URL" \
        --title="Test Site" \
        --admin_user="admin" \
        --admin_password="password" \
        --admin_email="test@example.com" \
        --skip-email \
        --allow-root || {
            print_error "WordPress installation failed!"
            exit 1
        }
        
    # Set language explicitly to avoid setup screen
    print_status "Setting language to English..."
    php -d memory_limit=256M /usr/local/bin/wp option update WPLANG "en_US" --path="$TEST_DOCROOT" --allow-root
    
    # Ensure languages directory exists and download .mo file if needed
    mkdir -p "$TEST_DOCROOT/wp-content/languages"
    if [ ! -f "$TEST_DOCROOT/wp-content/languages/en_US.mo" ]; then
        print_status "Downloading English language files..."
        wget -q -O "$TEST_DOCROOT/wp-content/languages/en_US.mo" \
            "https://downloads.wordpress.org/translation/core/6.8/en_US.mo" 2>/dev/null || \
            print_warning "Could not download language file, continuing anyway"
    fi
    
    # Create symlink to 404 Solution plugin (keep code in sync)
    print_status "Creating symlink to 404 Solution plugin..."
    ln -sf "$PLUGIN_ROOT" "$TEST_DOCROOT/wp-content/plugins/404-solution"
    
    # Activate 404 Solution plugin
    php -d memory_limit=256M /usr/local/bin/wp plugin activate 404-solution --path="$TEST_DOCROOT" --allow-root || {
        print_warning "Failed to activate 404 Solution plugin"
    }
    
    # Set proper file permissions
    print_status "Setting file permissions..."
    chmod -R 755 "$TEST_DOCROOT"
    find "$TEST_DOCROOT" -type f -exec chmod 644 {} \;
    
    # Verify installation
    print_status "Verifying WordPress installation..."
    if php -d memory_limit=256M /usr/local/bin/wp core is-installed --path="$TEST_DOCROOT" --allow-root 2>/dev/null; then
        print_success "WordPress core installed and verified"
    else
        print_error "WordPress installation verification failed!"
        exit 1
    fi
    
    # Verify web accessibility with curl
    print_status "Testing web accessibility..."
    if curl -I "$TEST_URL/wp-login.php" 2>/dev/null | grep -q "200 OK"; then
        print_success "WordPress is web-accessible"
    else
        print_warning "WordPress may not be web-accessible (check Apache configuration)"
    fi
    
    print_success "Fresh WordPress test environment ready at $TEST_URL"
}
# Function to run Playwright tests
run_tests() {
    print_status "Running Playwright tests..."
    cd "$TEST_ROOT"
    
    if npm list @playwright/test > /dev/null 2>&1; then
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