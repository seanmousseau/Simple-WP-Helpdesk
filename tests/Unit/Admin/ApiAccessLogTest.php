<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use SWH\Admin\ApiAccessLog;
use WP_Mock;

/**
 * Unit tests for {@see \SWH\Admin\ApiAccessLog} — Phase 9 (#369).
 *
 * Each rule in the Phase 9 contract is locked here. The in-memory option/meta
 * stores mirror the pattern used by ApiKeysAjaxTest so we exercise the real
 * helper code paths.
 */
final class ApiAccessLogTest extends TestCase {

	/** @var array<string,mixed> */
	private static array $options = array();

	/** @var array<string,mixed> Post-meta store keyed by "{post_id}::{key}". */
	private static array $post_meta = array();

	protected function setUp(): void {
		WP_Mock::setUp();
		self::$options   = array();
		self::$post_meta = array();
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
			'update_post_meta',
			array(
				'return' => function ( int $post_id, string $key, $value ) {
					self::$post_meta[ $post_id . '::' . $key ] = $value;
					return true;
				},
			)
		);
		WP_Mock::userFunction(
			'delete_post_meta',
			array(
				'return' => function ( int $post_id, string $key ) {
					unset( self::$post_meta[ $post_id . '::' . $key ] );
					return true;
				},
			)
		);
		WP_Mock::userFunction( 'wp_get_current_user', array( 'return' => (object) array( 'user_login' => 'admin' ) ) );
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		ApiAccessLog::reset_cache_for_tests();
	}

	/**
	 * Seed the integrations bag with `api_audit_log_enabled` set to $on.
	 */
	private function set_toggle( bool $on ): void {
		self::$options['swh_settings_integrations'] = array(
			'api_audit_log_enabled' => $on,
		);
		ApiAccessLog::reset_cache_for_tests();
	}

	private function read_log( int $ticket_id ): array {
		$raw = self::$post_meta[ $ticket_id . '::' . ApiAccessLog::META_KEY ] ?? null;
		return is_array( $raw ) ? $raw : array();
	}

	// -----------------------------------------------------------------------
	// Behaviour rules from the Phase 9 contract
	// -----------------------------------------------------------------------

	public function test_toggle_off_default_is_a_no_op(): void {
		// No seeding — toggle defaults to false.
		ApiAccessLog::record(
			42,
			'read',
			'/swh/v1/tickets/42',
			array(
				'id'    => 'k_abc',
				'label' => 'CI',
			)
		);
		$this->assertSame( array(), $this->read_log( 42 ), 'Meta must NOT be written when the toggle is off.' );
	}

	public function test_toggle_on_appends_entry(): void {
		$this->set_toggle( true );
		ApiAccessLog::record(
			42,
			'read',
			'/swh/v1/tickets/42',
			array(
				'id'    => 'k_abc',
				'label' => 'CI',
			)
		);

		$entries = $this->read_log( 42 );
		$this->assertCount( 1, $entries );
		$entry = $entries[0];
		$this->assertSame( 'k_abc', $entry['key_id'] );
		$this->assertSame( 'CI', $entry['key_label'] );
		$this->assertSame( 'read', $entry['action'] );
		$this->assertSame( '/swh/v1/tickets/42', $entry['route'] );
		$this->assertIsInt( $entry['ts'] );
		$this->assertGreaterThan( 0, $entry['ts'] );
	}

	public function test_fifo_trim_at_ten_entries(): void {
		$this->set_toggle( true );
		for ( $i = 1; $i <= 12; $i++ ) {
			ApiAccessLog::record(
				42,
				'read',
				'/swh/v1/tickets/42?call=' . $i,
				array(
					'id'    => 'k_' . $i,
					'label' => 'k' . $i,
				)
			);
		}

		$entries = $this->read_log( 42 );
		$this->assertCount( 10, $entries, 'Must enforce 10-entry FIFO trim.' );
		// Oldest two (k_1, k_2) dropped — first surviving entry should be k_3.
		$this->assertSame( 'k_3', $entries[0]['key_id'] );
		$this->assertSame( 'k_12', $entries[9]['key_id'] );
	}

	public function test_route_query_string_is_stripped(): void {
		$this->set_toggle( true );
		ApiAccessLog::record(
			42,
			'read',
			'/swh/v1/tickets/42?status=open&page=2',
			array(
				'id'    => 'k_abc',
				'label' => 'CI',
			)
		);

		$entries = $this->read_log( 42 );
		$this->assertSame( '/swh/v1/tickets/42', $entries[0]['route'], 'Query string must be stripped from route.' );
	}

	public function test_wp_application_password_attribution_when_no_api_key(): void {
		$this->set_toggle( true );
		ApiAccessLog::record( 42, 'read', '/swh/v1/tickets/42', null );

		$entries = $this->read_log( 42 );
		$this->assertSame( ApiAccessLog::WP_APP_PW_KEY_ID, $entries[0]['key_id'] );
		$this->assertSame( 'admin', $entries[0]['key_label'], 'Falls back to current user login when no key on request.' );
	}

	public function test_api_key_attribution_when_key_on_request(): void {
		$this->set_toggle( true );
		ApiAccessLog::record(
			42,
			'write',
			'/swh/v1/tickets/42',
			array(
				'id'    => 'k_xyz',
				'label' => 'Acme Bot',
			)
		);

		$entries = $this->read_log( 42 );
		$this->assertSame( 'k_xyz', $entries[0]['key_id'] );
		$this->assertSame( 'Acme Bot', $entries[0]['key_label'] );
	}

	public function test_toggle_off_does_not_delete_existing_entries(): void {
		$this->set_toggle( true );
		ApiAccessLog::record(
			42,
			'read',
			'/swh/v1/tickets/42',
			array(
				'id'    => 'k_abc',
				'label' => 'CI',
			)
		);
		$this->assertCount( 1, $this->read_log( 42 ) );

		// Flip the toggle off — idempotency: prior entries must persist.
		$this->set_toggle( false );
		$entries = $this->read_log( 42 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'k_abc', $entries[0]['key_id'] );

		// And no NEW entry gets written while the toggle is off.
		ApiAccessLog::record(
			42,
			'read',
			'/swh/v1/tickets/42',
			array(
				'id'    => 'k_def',
				'label' => 'CI2',
			)
		);
		$this->assertCount( 1, $this->read_log( 42 ), 'No new entries while the toggle is off.' );
	}

	public function test_action_must_be_read_or_write(): void {
		$this->set_toggle( true );
		ApiAccessLog::record(
			42,
			'delete',
			'/swh/v1/tickets/42',
			array(
				'id'    => 'k_abc',
				'label' => 'CI',
			)
		);
		$this->assertSame( array(), $this->read_log( 42 ), 'Unknown action verb must be a no-op.' );
	}

	public function test_non_positive_ticket_id_is_a_no_op(): void {
		$this->set_toggle( true );
		ApiAccessLog::record(
			0,
			'read',
			'/swh/v1/tickets/0',
			array(
				'id'    => 'k_abc',
				'label' => 'CI',
			)
		);
		ApiAccessLog::record(
			-5,
			'read',
			'/swh/v1/tickets/-5',
			array(
				'id'    => 'k_abc',
				'label' => 'CI',
			)
		);

		$this->assertSame( array(), $this->read_log( 0 ) );
		$this->assertSame( array(), $this->read_log( -5 ) );
	}

	public function test_is_enabled_caches_within_a_request(): void {
		// Seed the bag once; then mutate the underlying store directly to
		// confirm subsequent reads come from the cache, not the option store.
		$this->set_toggle( true );
		$this->assertTrue( ApiAccessLog::is_enabled() );

		// Direct mutation — bypasses set_toggle()'s cache reset.
		self::$options['swh_settings_integrations'] = array( 'api_audit_log_enabled' => false );

		$this->assertTrue( ApiAccessLog::is_enabled(), 'Cached value should win until reset_cache_for_tests().' );

		ApiAccessLog::reset_cache_for_tests();
		$this->assertFalse( ApiAccessLog::is_enabled() );
	}

	public function test_meta_key_constant_locked(): void {
		// Lock the meta-key constant — this is part of the data-dictionary
		// contract; renaming it would break readers (export tools etc.).
		$this->assertSame( '_swh_api_access_log', ApiAccessLog::META_KEY );
		$this->assertSame( 10, ApiAccessLog::MAX_ENTRIES );
		$this->assertSame( 'wp_application_password', ApiAccessLog::WP_APP_PW_KEY_ID );
	}
}
