<?php
/**
 * Unit tests for violet theme enqueue gate and body class filter.
 *
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

namespace SWH\Tests\Unit\Theme;

use WP_Mock;
use PHPUnit\Framework\TestCase;

final class VioletThemeTest extends TestCase {
	protected function setUp(): void {
		WP_Mock::setUp();
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'includes/theme-violet.php';
	}
	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_enqueue_skips_when_setting_off(): void {
		WP_Mock::userFunction( 'get_option', [
			'args'   => [ 'swh_settings_general', [] ],
			'return' => array( 'use_violet_theme' => false ),
		] );
		WP_Mock::userFunction( 'wp_enqueue_style' )->never();

		swh_violet_enqueue();
	}

	public function test_enqueue_loads_stylesheet_when_setting_on(): void {
		WP_Mock::userFunction( 'get_option', [
			'args'   => [ 'swh_settings_general', [] ],
			'return' => array( 'use_violet_theme' => true ),
		] );
		// swh_violet_is_helpdesk_screen() calls is_admin() then get_current_screen().
		// Simulate an admin helpdesk_ticket screen so the guard passes.
		WP_Mock::userFunction( 'is_admin', [ 'return' => true ] );
		$mock_screen            = new \stdClass();
		$mock_screen->post_type = 'helpdesk_ticket';
		WP_Mock::userFunction( 'get_current_screen', [ 'return' => $mock_screen ] );
		WP_Mock::userFunction( 'wp_enqueue_style', [
			'args'   => array(
				'swh-theme-violet',
				WP_Mock\Functions::type( 'string' ),
				WP_Mock\Functions::type( 'array' ),
				WP_Mock\Functions::type( 'string' ),
			),
			'times'  => 1,
		] );

		swh_violet_enqueue();
	}

	public function test_body_class_filter_appends_when_setting_on(): void {
		WP_Mock::userFunction( 'get_option', [
			'args'   => [ 'swh_settings_general', [] ],
			'return' => array( 'use_violet_theme' => true ),
		] );
		// swh_violet_filter_body_class calls swh_violet_is_helpdesk_screen() which
		// requires is_admin() and get_current_screen() to be stubbed.
		WP_Mock::userFunction( 'is_admin', [ 'return' => true ] );
		$mock_screen            = new \stdClass();
		$mock_screen->post_type = 'helpdesk_ticket';
		WP_Mock::userFunction( 'get_current_screen', [ 'return' => $mock_screen ] );

		$result = swh_violet_filter_body_class( 'existing-class' );
		$this->assertStringContainsString( 'swh-theme-violet', $result );
	}

	public function test_body_class_filter_no_op_when_setting_off(): void {
		WP_Mock::userFunction( 'get_option', [
			'args'   => [ 'swh_settings_general', [] ],
			'return' => array( 'use_violet_theme' => false ),
		] );

		$result = swh_violet_filter_body_class( 'existing-class' );
		$this->assertSame( 'existing-class', $result );
	}
}
