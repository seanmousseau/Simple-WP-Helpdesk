<?php
/**
 * PHPUnit bootstrap for Simple WP Helpdesk unit tests.
 *
 * Initialises WP_Mock and defines the WordPress constants required by plugin
 * files so they can be loaded without a full WordPress environment.
 *
 * @package Simple_WP_Helpdesk
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WordPress constants used by plugin files.
define( 'ABSPATH', '/tmp/wp/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

// Plugin path constants (mirrors simple-wp-helpdesk.php bootstrap).
define( 'SWH_PLUGIN_DIR', dirname( __DIR__ ) . '/simple-wp-helpdesk/' );
define( 'SWH_PLUGIN_URL', 'https://example.com/wp-content/plugins/simple-wp-helpdesk/' );
define( 'SWH_PLUGIN_FILE', SWH_PLUGIN_DIR . 'simple-wp-helpdesk.php' );
define( 'SWH_VERSION', '2.5.0' );

WP_Mock::setUsePatchwork( false );
WP_Mock::bootstrap();

// Minimal WordPress function stubs required by unit tests.
// WP_Mock does not define every WordPress function; define those needed here.

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Stub: strip tags and trim (matches WP behaviour for simple strings).
	 *
	 * @param string $str Input string.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stub only; real WP is not available.
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * Stub: replaces spaces with hyphens and lowercases (core WP behaviour).
	 *
	 * @param string $filename Input filename.
	 * @return string
	 */
	function sanitize_file_name( $filename ) {
		return strtolower( preg_replace( '/\s+/', '-', (string) $filename ) );
	}
}
