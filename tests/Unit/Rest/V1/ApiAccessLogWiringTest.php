<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest\V1;

use PHPUnit\Framework\TestCase;
use SWH\Admin\ApiAccessLog;
use SWH\Rest\Bootstrap;
use SWH\Rest\V1\RepliesController;
use SWH\Rest\V1\TicketsController;
use WP_Mock;
use WP_Post;
use WP_REST_Request;

/**
 * Phase 9 (#369) call-site wiring tests.
 *
 * Verifies the audit-log integration at the boundary between
 * {@see TicketsController}/{@see RepliesController} and
 * {@see \SWH\Admin\ApiAccessLog::record()}:
 *
 *   1. Toggle on, GET single ticket → audit entry recorded with action=read.
 *   2. Toggle on, PATCH ticket → audit entry recorded with action=write.
 *   3. Toggle on, GET tickets LIST → NO audit entry recorded.
 *   4. Toggle off, GET single ticket → NO audit entry recorded.
 *
 * Audit entries are observed by counting `update_post_meta` calls targeting
 * the {@see ApiAccessLog::META_KEY} meta key.
 */
final class ApiAccessLogWiringTest extends TestCase {

	/** @var array<string,mixed> */
	private static array $options = array();

	/** @var array<int,array<string,mixed>> */
	private static array $audit_writes = array();

	protected function setUp(): void {
		WP_Mock::setUp();
		Bootstrap::reset_for_tests();
		ApiAccessLog::reset_cache_for_tests();
		self::$options      = array();
		self::$audit_writes = array();

		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'includes/deprecations.php';
		require_once SWH_PLUGIN_DIR . 'src/Concurrency/TicketVersion.php';
		require_once SWH_PLUGIN_DIR . 'includes/ticket-update-fields.php';

		WP_Mock::userFunction(
			'rest_url',
			array(
				'return' => function ( string $path = '' ): string {
					return 'https://example.com/wp-json/' . ltrim( $path, '/' );
				},
			)
		);
		WP_Mock::userFunction( 'get_current_user_id', array( 'return' => 1 ) );
		WP_Mock::userFunction( 'wp_get_current_user', array( 'return' => (object) array( 'user_login' => 'admin' ) ) );
		WP_Mock::userFunction(
			'swh_rest_require',
			array(
				'return' => function () {
					return '__return_true'; },
			)
		);
		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => function ( string $key, $default = false ) {
					if ( 'swh_settings_integrations' === $key ) {
						return self::$options['swh_settings_integrations'] ?? $default;
					}
					$map = array(
						'swh_ticket_statuses'   => 'Open, In Progress, Resolved, Closed',
						'swh_ticket_priorities' => 'Low, Medium, High, Urgent',
						'swh_resolved_status'   => 'Resolved',
					);
					return $map[ $key ] ?? $default;
				},
			)
		);
		WP_Mock::userFunction(
			'apply_filters',
			array(
				'return' => function ( $tag, $value ) {
					return $value;
				},
			)
		);
		WP_Mock::userFunction(
			'apply_filters_deprecated',
			array(
				'return' => function ( $tag, $args ) {
					return $args[0];
				},
			)
		);
		WP_Mock::userFunction( 'has_filter', array( 'return' => false ) );
		WP_Mock::userFunction( '_deprecated_hook', array( 'return' => null ) );
		WP_Mock::userFunction( 'wp_update_post', array( 'return' => 42 ) );
		WP_Mock::userFunction( 'wp_set_post_terms', array( 'return' => array() ) );
		WP_Mock::userFunction(
			'sanitize_email',
			array(
				'return' => function ( $v ) {
					return strtolower( trim( (string) $v ) );
				},
			)
		);
		WP_Mock::userFunction(
			'sanitize_text_field',
			array(
				'return' => function ( $v ) {
					return trim( (string) $v );
				},
			)
		);
		WP_Mock::userFunction(
			'wp_kses_post',
			array(
				'return' => function ( $v ) {
					return (string) $v;
				},
			)
		);
		WP_Mock::userFunction(
			'is_email',
			array(
				'return' => function ( $v ) {
					return false !== strpos( (string) $v, '@' );
				},
			)
		);
		WP_Mock::userFunction( 'is_wp_error', array( 'return' => false ) );
		WP_Mock::userFunction( 'get_term', array( 'return' => null ) );
		WP_Mock::userFunction( 'wp_get_post_terms', array( 'return' => array() ) );

		// Capture every update_post_meta call. The audit log writes to
		// _swh_api_access_log; everything else is auxiliary and ignored.
		WP_Mock::userFunction(
			'update_post_meta',
			array(
				'return' => function ( int $post_id, string $key, $value ) {
					if ( ApiAccessLog::META_KEY === $key ) {
						self::$audit_writes[] = array(
							'post_id' => $post_id,
							'value'   => $value,
						);
					}
					return true;
				},
			)
		);
		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'return' => function ( int $post_id, string $key, bool $single = false ) {
					// No prior audit entries — first record() builds a fresh array.
					return $single ? '' : array();
				},
			)
		);
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		Bootstrap::reset_for_tests();
		ApiAccessLog::reset_cache_for_tests();
	}

	private function enable_toggle(): void {
		self::$options['swh_settings_integrations'] = array( 'api_audit_log_enabled' => true );
		ApiAccessLog::reset_cache_for_tests();
	}

	private function fake_post( int $id ): WP_Post {
		return new WP_Post(
			array(
				'ID'           => $id,
				'post_type'    => 'helpdesk_ticket',
				'post_title'   => 'Subject',
				'post_content' => 'Body',
				'post_status'  => 'publish',
				'post_date'    => '2026-05-27 00:00:00',
			)
		);
	}

	// -----------------------------------------------------------------------
	// TicketsController call-site wiring
	// -----------------------------------------------------------------------

	public function test_get_single_ticket_records_read_when_toggle_on(): void {
		$this->enable_toggle();
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 42 ),
				'return' => $this->fake_post( 42 ),
			)
		);

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );
		$req->set_param(
			'_swh_api_key',
			array(
				'id'    => 'k_abc',
				'label' => 'CI',
			)
		);

		( new TicketsController() )->get_item( $req );

		$this->assertCount( 1, self::$audit_writes );
		$this->assertSame( 42, self::$audit_writes[0]['post_id'] );
		$entry = self::$audit_writes[0]['value'][0];
		$this->assertSame( 'read', $entry['action'] );
		$this->assertSame( 'k_abc', $entry['key_id'] );
	}

	public function test_get_tickets_list_does_not_record(): void {
		$this->enable_toggle();

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets' );
		$req->set_param(
			'_swh_api_key',
			array(
				'id'    => 'k_abc',
				'label' => 'CI',
			)
		);

		( new TicketsController() )->get_items( $req );

		$this->assertSame( array(), self::$audit_writes, 'List endpoints must NOT audit.' );
	}

	public function test_get_single_ticket_does_not_record_when_toggle_off(): void {
		// Toggle defaults off.
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 42 ),
				'return' => $this->fake_post( 42 ),
			)
		);

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );
		$req->set_param(
			'_swh_api_key',
			array(
				'id'    => 'k_abc',
				'label' => 'CI',
			)
		);

		( new TicketsController() )->get_item( $req );

		$this->assertSame( array(), self::$audit_writes, 'Toggle off → no write.' );
	}

	public function test_patch_ticket_records_write(): void {
		$this->enable_toggle();
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 42 ),
				'return' => $this->fake_post( 42 ),
			)
		);

		$req = new WP_REST_Request( 'PATCH', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );
		// Empty patch — controller still resolves to a write on this ticket id.
		$req->set_param(
			'_swh_api_key',
			array(
				'id'    => 'k_abc',
				'label' => 'CI',
			)
		);

		( new TicketsController() )->update_item( $req );

		// One write of META_KEY captured. (Other meta writes — _ticket_status etc. — are filtered out.)
		$this->assertCount( 1, self::$audit_writes );
		$entry = self::$audit_writes[0]['value'][0];
		$this->assertSame( 'write', $entry['action'] );
		$this->assertSame( '/swh/v1/tickets/42', $entry['route'] );
	}
}
