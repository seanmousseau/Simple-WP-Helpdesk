<?php
/**
 * PHPUnit bootstrap for the wp-phpunit integration test suite.
 *
 * Unlike tests/bootstrap.php (which uses WP-Mock to stub WordPress functions),
 * this bootstrap loads the REAL WordPress test framework. Tests that extend
 * WP_UnitTestCase or WP_REST_TestCase get a real WP environment with a real
 * (test) database, real REST server, real cookie/nonce flow, etc.
 *
 * Setup:
 *   1. composer install --dev    (installs wp-phpunit + yoast/phpunit-polyfills)
 *   2. bin/install-wp-tests.sh <db_name> <db_user> <db_pass> [db_host]
 *      (recreates the test DB and writes tests/Integration/wp-tests-config.php)
 *   3. vendor/bin/phpunit --configuration phpunit-integration.xml
 *
 * The wp-tests-config.php file is .gitignored — operators (and CI) generate
 * their own. The bootstrap below loads it.
 *
 * @package Simple_WP_Helpdesk
 */

$swh_tests_dir = dirname( __DIR__, 1 ) . '/vendor/wp-phpunit/wp-phpunit';
if ( ! is_dir( $swh_tests_dir ) ) {
	echo "ERROR: wp-phpunit framework not found at $swh_tests_dir\n";
	echo "  Run: composer install --dev\n";
	exit( 1 );
}

if ( ! file_exists( __DIR__ . '/wp-tests-config.php' ) ) {
	echo "ERROR: wp-tests-config.php not found in tests/Integration/\n";
	echo "  Run: bin/install-wp-tests.sh <db_name> <db_user> <db_pass>\n";
	exit( 1 );
}

// wp-phpunit looks for the config file via the WP_TESTS_CONFIG_FILE_PATH env
// var. Point it at our local file.
putenv( 'WP_TESTS_CONFIG_FILE_PATH=' . __DIR__ . '/wp-tests-config.php' );

// Load wp-phpunit's "functions" file FIRST so we can register the
// muplugins_loaded hook that activates our plugin before WP loads anything else.
require_once $swh_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 *
 * `tests_add_filter` is provided by wp-phpunit's functions.php. The
 * `muplugins_loaded` hook fires before WP's plugins_loaded so we can hook
 * in our plugin's bootstrap as if it were active.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 1 ) . '/simple-wp-helpdesk/simple-wp-helpdesk.php';
	}
);

// Start the WP test framework. This blocks until WP has booted, the test DB
// has been (re)installed, and the REST server is available.
require $swh_tests_dir . '/includes/bootstrap.php';

// Ensure our Composer autoload is on the path AFTER WP boots (so PSR-4 namespaced
// test classes under SWH\Tests\Integration\ are autoloadable).
require_once dirname( __DIR__, 1 ) . '/vendor/autoload.php';
