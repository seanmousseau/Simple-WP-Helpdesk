<?php
/**
 * Unit tests for swh_wrap_html_email() — colour-scheme metadata (#340).
 *
 * @package Simple_WP_Helpdesk
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

require_once SWH_PLUGIN_DIR . 'includes/class-email.php';

/**
 * Tests for the dark-mode-friendly metadata emitted by swh_wrap_html_email().
 */
final class EmailWrapperTest extends TestCase {

	/** Set up WP_Mock and stub WordPress functions used by the wrapper. */
	protected function setUp() : void {
		WP_Mock::setUp();
		WP_Mock::userFunction( 'esc_html', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'esc_url', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'wp_parse_url', array( 'return' => '' ) );
		WP_Mock::userFunction( 'get_bloginfo', array( 'return' => 'Test Site' ) );
		WP_Mock::userFunction( 'swh_get_string_option', array( 'return' => '' ) );
		WP_Mock::userFunction( 'get_option', array( 'return' => '' ) );
		WP_Mock::userFunction( 'get_site_icon_url', array( 'return' => '' ) );
		WP_Mock::userFunction( 'apply_filters', array( 'return_arg' => 1 ) );
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
	}

	/** Tear down WP_Mock state. */
	protected function tearDown() : void {
		WP_Mock::tearDown();
	}

	/** Wrapper emits the color-scheme + supported-color-schemes meta tags. */
	public function test_wrapper_includes_color_scheme_meta() : void {
		$html = swh_wrap_html_email( 'hello' );
		$this->assertStringContainsString( '<meta name="color-scheme" content="light dark">', $html );
		$this->assertStringContainsString( '<meta name="supported-color-schemes" content="light dark">', $html );
	}

	/** Wrapper emits the prefers-color-scheme media query targeting the email card. */
	public function test_wrapper_includes_dark_media_query() : void {
		$html = swh_wrap_html_email( 'hello' );
		$this->assertStringContainsString( '@media (prefers-color-scheme: dark)', $html );
		$this->assertStringContainsString( 'swh-email-card', $html );
	}

	/** Inline color-scheme hint is present so clients without <style> still opt in. */
	public function test_wrapper_emits_color_scheme_inline() : void {
		$html = swh_wrap_html_email( 'hello' );
		$this->assertStringContainsString( 'color-scheme:light dark', $html );
	}
}
