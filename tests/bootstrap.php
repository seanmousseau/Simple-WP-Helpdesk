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
define( 'SWH_VERSION', '3.1.0' );

WP_Mock::setUsePatchwork( false );
WP_Mock::bootstrap();

// WordPress class stubs for tests that exercise code using WP classes directly.

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query stub for unit tests.
	 * The stub always returns found_posts = 0; tests that need a specific count
	 * should work with the return value of swh_report_status_breakdown() directly.
	 */
	class WP_Query {
		/** @var int */
		public int $found_posts = 0;

		/** @param array<string, mixed> $args Query arguments (ignored in stub). */
		public function __construct( array $args = array() ) {}
	}
}

if ( ! class_exists( 'WP_Comment' ) ) {
	/**
	 * Minimal WP_Comment stub for unit tests.
	 *
	 * Defines the properties accessed by swh_format_comment_date() so tests can
	 * construct real WP_Comment objects without a full WordPress environment.
	 */
	class WP_Comment {
		/** @var string UTC timestamp of the comment. */
		public string $comment_date_gmt = '';

		/**
		 * @param array<string, mixed> $data Optional property overrides.
		 */
		public function __construct( array $data = array() ) {
			foreach ( $data as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->{$key} = (string) $value;
				}
			}
		}
	}
}

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
