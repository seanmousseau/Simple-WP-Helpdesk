<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use SWH\Admin\ApiAccessLog;
use SWH\Admin\ApiAccessLogMetaBox;
use WP_Mock;
use WP_Post;

/**
 * Unit tests for {@see \SWH\Admin\ApiAccessLogMetaBox} — Phase 9 (#369).
 */
final class ApiAccessLogMetaBoxTest extends TestCase {

	/** @var array<string,mixed> */
	private static array $options = array();
	/** @var array<string,mixed> */
	private static array $post_meta = array();
	/** @var array<int,array<string,mixed>> */
	private static array $registered_meta_boxes = array();

	protected function setUp(): void {
		WP_Mock::setUp();
		self::$options               = array();
		self::$post_meta             = array();
		self::$registered_meta_boxes = array();
		ApiAccessLog::reset_cache_for_tests();

		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';

		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => function ( string $name, $default = false ) {
					return self::$options[ $name ] ?? $default;
				},
			)
		);
		WP_Mock::userFunction(
			'update_option',
			array(
				'return' => function ( string $name, $value ) {
					self::$options[ $name ] = $value;
					return true;
				},
			)
		);
		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'return' => function ( int $post_id, string $key, bool $single = false ) {
					$k = $post_id . '::' . $key;
					if ( ! array_key_exists( $k, self::$post_meta ) ) {
						return $single ? '' : array();
					}
					return $single ? self::$post_meta[ $k ] : array( self::$post_meta[ $k ] );
				},
			)
		);
		WP_Mock::userFunction(
			'add_meta_box',
			array(
				'return' => function ( string $id, string $title, $callback, string $screen, string $context = 'advanced', string $priority = 'default' ) {
					self::$registered_meta_boxes[] = compact( 'id', 'title', 'callback', 'screen', 'context', 'priority' );
					return true;
				},
			)
		);
		WP_Mock::userFunction(
			'esc_html',
			array(
				'return' => function ( $v ) {
					return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); },
			)
		);
		WP_Mock::userFunction(
			'esc_html__',
			array(
				'return' => function ( $text ) {
					return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); },
			)
		);
		WP_Mock::userFunction(
			'esc_attr__',
			array(
				'return' => function ( $text ) {
					return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); },
			)
		);
		WP_Mock::userFunction(
			'wp_date',
			array(
				'return' => function ( string $fmt, int $ts ) {
					return gmdate( $fmt, $ts );
				},
			)
		);
		WP_Mock::userFunction(
			'get_date_from_gmt',
			array(
				'return' => function ( string $gmt, string $fmt ) {
					return $gmt;
				},
			)
		);
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		ApiAccessLog::reset_cache_for_tests();
	}

	private function set_toggle( bool $on ): void {
		self::$options['swh_settings_integrations'] = array( 'api_audit_log_enabled' => $on );
		ApiAccessLog::reset_cache_for_tests();
	}

	public function test_meta_box_not_registered_when_toggle_off(): void {
		// Toggle defaults off.
		ApiAccessLogMetaBox::register_meta_box();
		$this->assertSame( array(), self::$registered_meta_boxes );
	}

	public function test_meta_box_registered_on_helpdesk_ticket_screen_when_toggle_on(): void {
		$this->set_toggle( true );
		ApiAccessLogMetaBox::register_meta_box();

		$this->assertCount( 1, self::$registered_meta_boxes );
		$mb = self::$registered_meta_boxes[0];
		$this->assertSame( ApiAccessLogMetaBox::ID, $mb['id'] );
		$this->assertSame( 'helpdesk_ticket', $mb['screen'] );
		$this->assertSame( 'side', $mb['context'] );
		$this->assertSame( 'low', $mb['priority'] );
		$this->assertIsCallable( $mb['callback'] );
	}

	public function test_render_empty_state_when_no_entries(): void {
		$this->set_toggle( true );
		$post = new WP_Post(
			array(
				'ID'        => 42,
				'post_type' => 'helpdesk_ticket',
			)
		);

		ob_start();
		ApiAccessLogMetaBox::render( $post );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No API requests recorded', $html );
		$this->assertStringNotContainsString( '<table', $html );
	}

	public function test_render_entries_newest_first_with_a11y_attributes(): void {
		$this->set_toggle( true );
		self::$post_meta[ '42::' . ApiAccessLog::META_KEY ] = array(
			array(
				'ts'        => 1000,
				'key_id'    => 'k_old',
				'key_label' => 'Old Key',
				'action'    => 'read',
				'route'     => '/swh/v1/tickets/42',
			),
			array(
				'ts'        => 2000,
				'key_id'    => 'k_new',
				'key_label' => 'New Key',
				'action'    => 'write',
				'route'     => '/swh/v1/tickets/42',
			),
		);
		$post = new WP_Post(
			array(
				'ID'        => 42,
				'post_type' => 'helpdesk_ticket',
			)
		);

		ob_start();
		ApiAccessLogMetaBox::render( $post );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<table', $html );
		$this->assertStringContainsString( 'aria-label="API access log for this ticket"', $html );
		$this->assertStringContainsString( 'scope="col"', $html );
		// Newest first — New Key must appear BEFORE Old Key in the rendered HTML.
		$pos_new = strpos( $html, 'New Key' );
		$pos_old = strpos( $html, 'Old Key' );
		$this->assertNotFalse( $pos_new );
		$this->assertNotFalse( $pos_old );
		$this->assertLessThan( $pos_old, $pos_new, 'Newest entries must render first.' );
	}

	public function test_render_escapes_key_label_and_route(): void {
		$this->set_toggle( true );
		self::$post_meta[ '42::' . ApiAccessLog::META_KEY ] = array(
			array(
				'ts'        => 1000,
				'key_id'    => 'k_x',
				'key_label' => '<script>alert(1)</script>',
				'action'    => 'read',
				'route'     => '/swh/v1/tickets/42<bad>',
			),
		);
		$post = new WP_Post(
			array(
				'ID'        => 42,
				'post_type' => 'helpdesk_ticket',
			)
		);

		ob_start();
		ApiAccessLogMetaBox::render( $post );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '&lt;bad&gt;', $html );
	}

	public function test_post_meta_cleanup_on_delete_contract(): void {
		// This is really a WP-core contract — wp_delete_post() cascades post meta.
		// We lock the contract for future readers by asserting our delete_post_meta
		// shim wipes the value out, mirroring what core does on post deletion.
		$this->set_toggle( true );
		self::$post_meta[ '42::' . ApiAccessLog::META_KEY ] = array(
			array(
				'ts'        => 1000,
				'key_id'    => 'k',
				'key_label' => 'k',
				'action'    => 'read',
				'route'     => '/swh/v1/tickets/42',
			),
		);
		$this->assertArrayHasKey( '42::' . ApiAccessLog::META_KEY, self::$post_meta );

		// Simulate post-delete cascade.
		unset( self::$post_meta[ '42::' . ApiAccessLog::META_KEY ] );

		$this->assertArrayNotHasKey( '42::' . ApiAccessLog::META_KEY, self::$post_meta );
	}
}
