<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SWH\Rest\ApiKeys;
use WP_Mock;

/**
 * Unit tests for SWH\Rest\ApiKeys — the v4.1.0 Phase 1 API key storage layer.
 *
 * Storage shape under test: api keys live INSIDE the `swh_settings_integrations`
 * option bag under the `api_keys` sub-key. ApiKeys is the ONLY writer.
 *
 * Strategy: stub get_option / update_option / get_transient / set_transient
 * with a static in-memory store so the class actually round-trips through its
 * persistence path during each test.
 */
final class ApiKeysTest extends TestCase {

	/** @var array<string,mixed> */
	private static array $options = array();

	/** @var array<string,mixed> */
	private static array $transients = array();

	protected function setUp(): void {
		WP_Mock::setUp();

		self::$options    = array();
		self::$transients = array();

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
			'get_transient',
			array(
				'return' => function ( string $name ) {
					return self::$transients[ $name ] ?? false;
				},
			)
		);
		WP_Mock::userFunction(
			'set_transient',
			array(
				'return' => function ( string $name, $value, int $ttl = 0 ) {
					self::$transients[ $name ] = $value;
					return true;
				},
			)
		);
		WP_Mock::userFunction(
			'wp_generate_password',
			array(
				'return' => function ( int $length, bool $special = false, bool $extra = false ) {
					// Base62-ish; deterministic enough for length assertions.
					return substr( str_repeat( 'aBcD1eFgH2iJkL3mNoP4qRsT5uVwX6yZ', 4 ), 0, $length );
				},
			)
		);
		WP_Mock::userFunction(
			'wp_generate_uuid4',
			array(
				'return' => '11111111-2222-3333-4444-555555555555',
			)
		);
		WP_Mock::userFunction(
			'get_current_user_id',
			array(
				'return' => 7,
			)
		);
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	// --- generate() ---

	public function test_generate_returns_plaintext_and_entry(): void {
		$result = ApiKeys::generate( 'CI integration', 'read' );

		$this->assertArrayHasKey( 'plaintext', $result );
		$this->assertArrayHasKey( 'entry', $result );

		$plaintext = $result['plaintext'];
		$this->assertStringStartsWith( 'swhk_', $plaintext );
		$this->assertSame( 5 + 32, strlen( $plaintext ), 'plaintext is "swhk_" + 32-char random portion' );

		$entry = $result['entry'];
		foreach (
			array(
				'id',
				'label',
				'scope',
				'prefix',
				'created_at',
				'created_by',
				'last_used_at',
				'rate_limit',
				'revoked_at',
			) as $key
		) {
			$this->assertArrayHasKey( $key, $entry, "entry exposes {$key}" );
		}
		$this->assertArrayNotHasKey( 'hash', $entry, 'returned entry omits hash so callers cannot accidentally log it' );
		$this->assertSame( 'CI integration', $entry['label'] );
		$this->assertSame( 'read', $entry['scope'] );
		$this->assertSame( substr( $plaintext, 0, 12 ), $entry['prefix'] );
		$this->assertSame( 0, $entry['last_used_at'] );
		$this->assertNull( $entry['revoked_at'] );
		$this->assertNull( $entry['rate_limit'] );
		$this->assertSame( 7, $entry['created_by'] );
	}

	public function test_generate_persists_inside_integrations_bag(): void {
		ApiKeys::generate( 'CI integration', 'write', 60 );

		$this->assertArrayHasKey( 'swh_settings_integrations', self::$options );
		$bag = self::$options['swh_settings_integrations'];
		$this->assertIsArray( $bag );
		$this->assertArrayHasKey( 'api_keys', $bag );
		$this->assertCount( 1, $bag['api_keys'] );

		$stored = $bag['api_keys'][0];
		$this->assertArrayHasKey( 'hash', $stored, 'persisted entry includes the bcrypt hash' );
		$this->assertStringStartsWith( '$2y$12$', $stored['hash'], 'bcrypt cost 12 hash' );
		$this->assertSame( 'write', $stored['scope'] );
		$this->assertSame( 60, $stored['rate_limit'] );
	}

	public function test_generate_validates_label_empty(): void {
		$this->expectException( InvalidArgumentException::class );
		ApiKeys::generate( '   ', 'read' );
	}

	public function test_generate_validates_label_too_long(): void {
		$this->expectException( InvalidArgumentException::class );
		ApiKeys::generate( str_repeat( 'x', 61 ), 'read' );
	}

	public function test_generate_validates_scope(): void {
		$this->expectException( InvalidArgumentException::class );
		ApiKeys::generate( 'CI integration', 'admin' );
	}

	// --- find_by_plaintext() ---

	public function test_find_by_plaintext_returns_entry_on_match(): void {
		$generated = ApiKeys::generate( 'CI integration', 'write' );
		$plaintext = $generated['plaintext'];

		$found = ApiKeys::find_by_plaintext( $plaintext );

		$this->assertIsArray( $found );
		$this->assertSame( $generated['entry']['id'], $found['id'] );
		$this->assertSame( 'write', $found['scope'] );
	}

	public function test_find_by_plaintext_returns_null_on_miss(): void {
		ApiKeys::generate( 'CI integration', 'read' );

		$result = ApiKeys::find_by_plaintext( 'swhk_obviouslywrongtokenvaluexxxxxxxxxx' );

		$this->assertNull( $result );
	}

	public function test_find_by_plaintext_returns_null_for_short_input(): void {
		$this->assertNull( ApiKeys::find_by_plaintext( 'short' ) );
	}

	public function test_find_by_plaintext_returns_null_on_revoked(): void {
		$generated = ApiKeys::generate( 'CI integration', 'read' );
		$plaintext = $generated['plaintext'];
		$id        = $generated['entry']['id'];

		ApiKeys::revoke( $id );

		$this->assertNull( ApiKeys::find_by_plaintext( $plaintext ) );
	}

	public function test_find_by_plaintext_resolves_correct_entry_when_prefixes_collide(): void {
		// Two entries with the SAME stored prefix (deterministic stub) must still
		// be disambiguated correctly via password_verify. We hand-craft a second
		// entry by directly seeding the bag so we have two known plaintexts that
		// share the first 12 chars but hash differently.
		$plain_a = 'swhk_aBcD1eFgH2iJkL3mNoP4qRsT5uVwX6yZ7';
		$plain_b = 'swhk_aBcD1eFgZZZZZZZZZZZZZZZZZZZZZZZZZ7';
		$hash_a  = password_hash( $plain_a, PASSWORD_BCRYPT, array( 'cost' => 4 ) ); // low cost for test speed
		$hash_b  = password_hash( $plain_b, PASSWORD_BCRYPT, array( 'cost' => 4 ) );

		self::$options['swh_settings_integrations'] = array(
			'api_keys' => array(
				array(
					'id'           => 'k_a',
					'label'        => 'A',
					'scope'        => 'read',
					'prefix'       => substr( $plain_a, 0, 12 ),
					'hash'         => $hash_a,
					'created_at'   => 1,
					'created_by'   => 1,
					'last_used_at' => 0,
					'rate_limit'   => null,
					'revoked_at'   => null,
				),
				array(
					'id'           => 'k_b',
					'label'        => 'B',
					'scope'        => 'write',
					'prefix'       => substr( $plain_b, 0, 12 ),
					'hash'         => $hash_b,
					'created_at'   => 2,
					'created_by'   => 1,
					'last_used_at' => 0,
					'rate_limit'   => null,
					'revoked_at'   => null,
				),
			),
		);

		// Both prefixes start with "swhk_aBcD1e" so they collide on the first 12 chars.
		$this->assertSame( substr( $plain_a, 0, 12 ), substr( $plain_b, 0, 12 ) );

		$found_a = ApiKeys::find_by_plaintext( $plain_a );
		$found_b = ApiKeys::find_by_plaintext( $plain_b );

		$this->assertIsArray( $found_a );
		$this->assertSame( 'k_a', $found_a['id'] );
		$this->assertIsArray( $found_b );
		$this->assertSame( 'k_b', $found_b['id'] );
	}

	// --- revoke() ---

	public function test_revoke_sets_revoked_at(): void {
		$generated = ApiKeys::generate( 'CI integration', 'read' );
		$id        = $generated['entry']['id'];

		$this->assertTrue( ApiKeys::revoke( $id ) );

		$bag    = self::$options['swh_settings_integrations'];
		$stored = $bag['api_keys'][0];
		$this->assertIsInt( $stored['revoked_at'] );
		$this->assertGreaterThan( 0, $stored['revoked_at'] );
	}

	public function test_revoke_returns_false_on_unknown_id(): void {
		ApiKeys::generate( 'CI integration', 'read' );

		$this->assertFalse( ApiKeys::revoke( 'k_does_not_exist' ) );
	}

	public function test_revoke_returns_false_on_already_revoked(): void {
		$generated = ApiKeys::generate( 'CI integration', 'read' );
		$id        = $generated['entry']['id'];

		$this->assertTrue( ApiKeys::revoke( $id ) );
		$this->assertFalse( ApiKeys::revoke( $id ), 'revocation is permanent — second call is a no-op' );
	}

	// --- touch_last_used() ---

	public function test_touch_last_used_writes_first_time(): void {
		$generated = ApiKeys::generate( 'CI integration', 'read' );
		$id        = $generated['entry']['id'];

		$bag_before = self::$options['swh_settings_integrations'];
		$this->assertSame( 0, $bag_before['api_keys'][0]['last_used_at'] );

		ApiKeys::touch_last_used( $id );

		$bag_after = self::$options['swh_settings_integrations'];
		$this->assertIsInt( $bag_after['api_keys'][0]['last_used_at'] );
		$this->assertGreaterThan( 0, $bag_after['api_keys'][0]['last_used_at'] );
		$this->assertArrayHasKey( 'swh_apikey_touch_' . $id, self::$transients );
	}

	public function test_touch_last_used_is_rate_limited(): void {
		$generated = ApiKeys::generate( 'CI integration', 'read' );
		$id        = $generated['entry']['id'];

		ApiKeys::touch_last_used( $id );
		$bag_after_first = self::$options['swh_settings_integrations'];
		$first_ts        = $bag_after_first['api_keys'][0]['last_used_at'];

		// Pretend a second authenticated request comes in instantly. The
		// transient guard should make this a no-op.
		ApiKeys::touch_last_used( $id );

		$bag_after_second = self::$options['swh_settings_integrations'];
		$this->assertSame(
			$first_ts,
			$bag_after_second['api_keys'][0]['last_used_at'],
			'second touch within 5 minutes is a no-op'
		);
	}

	public function test_touch_last_used_no_op_on_unknown_id(): void {
		ApiKeys::generate( 'CI integration', 'read' );

		ApiKeys::touch_last_used( 'k_unknown' );

		$this->assertArrayNotHasKey( 'swh_apikey_touch_k_unknown', self::$transients );
		$bag = self::$options['swh_settings_integrations'];
		$this->assertSame( 0, $bag['api_keys'][0]['last_used_at'] );
	}

	// --- all() ---

	public function test_all_includes_revoked(): void {
		$gen_a = ApiKeys::generate( 'A', 'read' );
		$gen_b = ApiKeys::generate( 'B', 'write' );
		ApiKeys::revoke( $gen_a['entry']['id'] );

		$all = ApiKeys::all();

		$this->assertCount( 2, $all );
		// At least one entry has revoked_at set, one has null.
		$revoked = array_filter( $all, static fn( array $e ) => null !== ( $e['revoked_at'] ?? null ) );
		$active  = array_filter( $all, static fn( array $e ) => null === ( $e['revoked_at'] ?? null ) );
		$this->assertCount( 1, $revoked );
		$this->assertCount( 1, $active );
	}

	public function test_all_returns_empty_when_bag_missing(): void {
		$this->assertSame( array(), ApiKeys::all() );
	}
}
