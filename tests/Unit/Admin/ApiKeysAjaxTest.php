<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SWH\Admin\ApiKeysAjax;
use WP_Mock;

/**
 * Unit tests for SWH\Admin\ApiKeysAjax — the v4.1.0 Phase 6 admin-AJAX surface.
 *
 * Tests cover the nonce + manage_options gate, input validation, and the
 * happy-path round-trip into Phase 1's {@see \SWH\Rest\ApiKeys} storage class.
 *
 * Approach: stub wp_send_json_* to throw a typed marker exception so the
 * handler's implicit exit becomes observable, matching the precedent set by
 * CsatAjaxTest (see tests/Unit/CsatAjaxTest.php).
 */
final class ApiKeyAjaxStopException extends RuntimeException {
	public bool $is_success;
	/** @var array<string,mixed> */
	public array $payload;
	public int $status;

	/**
	 * @param array<string,mixed> $payload
	 */
	public function __construct( bool $is_success, array $payload, int $status ) {
		parent::__construct( $is_success ? 'apikey_success' : 'apikey_error' );
		$this->is_success = $is_success;
		$this->payload    = $payload;
		$this->status     = $status;
	}
}

final class ApiKeysAjaxTest extends TestCase {

	/** @var array<string,mixed> */
	private static array $options = array();

	/** @var array<string,mixed> */
	private static array $transients = array();

	protected function setUp(): void {
		WP_Mock::setUp();

		self::$options    = array();
		self::$transients = array();
		$_POST            = array();

		// ApiKeys storage — round-trip through the in-memory store.
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
			array( 'return' => 7 )
		);

		// Sanitisation helpers used by the handler.
		WP_Mock::userFunction( 'wp_unslash', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction(
			'sanitize_text_field',
			array( 'return' => fn( $v ) => is_string( $v ) ? trim( strip_tags( $v ) ) : '' )
		);
		WP_Mock::userFunction( 'esc_html__', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );

		// Surface wp_send_json_* as catchable exceptions.
		WP_Mock::userFunction(
			'wp_send_json_success',
			array(
				'return' => function ( $payload = null, $status = 200 ) {
					throw new ApiKeyAjaxStopException( true, (array) ( $payload ?? array() ), (int) $status );
				},
			)
		);
		WP_Mock::userFunction(
			'wp_send_json_error',
			array(
				'return' => function ( $payload = null, $status = 200 ) {
					throw new ApiKeyAjaxStopException( false, (array) ( $payload ?? array() ), (int) $status );
				},
			)
		);
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		$_POST = array();
	}

	/**
	 * Stub the nonce + capability checks. Defaults: both succeed.
	 */
	private function gate( bool $nonce_ok = true, bool $cap_ok = true ): void {
		WP_Mock::userFunction(
			'check_ajax_referer',
			array(
				'return' => $nonce_ok ? 1 : false,
			)
		);
		WP_Mock::userFunction(
			'current_user_can',
			array(
				'return' => $cap_ok,
			)
		);
	}

	/**
	 * Invoke a handler and return the captured exit envelope.
	 */
	private function invoke( callable $fn ): ApiKeyAjaxStopException {
		try {
			$fn();
		} catch ( ApiKeyAjaxStopException $e ) {
			return $e;
		}
		$this->fail( 'Handler did not call wp_send_json_*.' );
	}

	// --- generate() ---

	public function test_generate_rejects_missing_nonce(): void {
		$this->gate( false, true );
		$_POST = array( 'label' => 'CI', 'scope' => 'read' );

		$stop = $this->invoke( array( ApiKeysAjax::class, 'handle_generate' ) );

		$this->assertFalse( $stop->is_success );
		$this->assertSame( 403, $stop->status );
		$this->assertSame( 'swh_apikey_bad_nonce', $stop->payload['code'] );
	}

	public function test_generate_rejects_user_without_manage_options(): void {
		$this->gate( true, false );
		$_POST = array( 'label' => 'CI', 'scope' => 'read' );

		$stop = $this->invoke( array( ApiKeysAjax::class, 'handle_generate' ) );

		$this->assertFalse( $stop->is_success );
		$this->assertSame( 403, $stop->status );
		$this->assertSame( 'swh_apikey_forbidden', $stop->payload['code'] );
	}

	public function test_generate_validates_label_required(): void {
		$this->gate();
		$_POST = array( 'label' => '   ', 'scope' => 'read' );

		$stop = $this->invoke( array( ApiKeysAjax::class, 'handle_generate' ) );

		$this->assertFalse( $stop->is_success );
		$this->assertSame( 400, $stop->status );
		$this->assertSame( 'swh_apikey_label_required', $stop->payload['code'] );
		$this->assertSame( 'label', $stop->payload['field'] );
	}

	public function test_generate_validates_label_max_length(): void {
		$this->gate();
		$_POST = array( 'label' => str_repeat( 'A', 61 ), 'scope' => 'read' );

		$stop = $this->invoke( array( ApiKeysAjax::class, 'handle_generate' ) );

		$this->assertFalse( $stop->is_success );
		$this->assertSame( 400, $stop->status );
		$this->assertSame( 'swh_apikey_label_too_long', $stop->payload['code'] );
		$this->assertSame( 'label', $stop->payload['field'] );
	}

	public function test_generate_validates_scope_enum(): void {
		$this->gate();
		$_POST = array( 'label' => 'CI', 'scope' => 'admin' );

		$stop = $this->invoke( array( ApiKeysAjax::class, 'handle_generate' ) );

		$this->assertFalse( $stop->is_success );
		$this->assertSame( 400, $stop->status );
		$this->assertSame( 'swh_apikey_scope_invalid', $stop->payload['code'] );
	}

	public function test_generate_validates_rate_limit_below_min(): void {
		$this->gate();
		$_POST = array( 'label' => 'CI', 'scope' => 'read', 'rate_limit' => '0' );

		$stop = $this->invoke( array( ApiKeysAjax::class, 'handle_generate' ) );

		$this->assertFalse( $stop->is_success );
		$this->assertSame( 400, $stop->status );
		$this->assertSame( 'swh_apikey_rate_limit_out_of_range', $stop->payload['code'] );
	}

	public function test_generate_validates_rate_limit_above_max(): void {
		$this->gate();
		$_POST = array( 'label' => 'CI', 'scope' => 'read', 'rate_limit' => '10001' );

		$stop = $this->invoke( array( ApiKeysAjax::class, 'handle_generate' ) );

		$this->assertFalse( $stop->is_success );
		$this->assertSame( 400, $stop->status );
		$this->assertSame( 'swh_apikey_rate_limit_out_of_range', $stop->payload['code'] );
	}

	public function test_generate_happy_path_returns_plaintext_and_entry_no_hash(): void {
		$this->gate();
		$_POST = array(
			'label'      => 'CI integration',
			'scope'      => 'read',
			'rate_limit' => '',
		);

		$stop = $this->invoke( array( ApiKeysAjax::class, 'handle_generate' ) );

		$this->assertTrue( $stop->is_success );
		$this->assertSame( 200, $stop->status );
		$this->assertArrayHasKey( 'plaintext', $stop->payload );
		$this->assertStringStartsWith( 'swhk_', $stop->payload['plaintext'] );
		$this->assertArrayHasKey( 'entry', $stop->payload );
		$entry = $stop->payload['entry'];
		$this->assertArrayNotHasKey( 'hash', $entry, 'Hash MUST NOT leak in the AJAX response.' );
		$this->assertSame( 'CI integration', $entry['label'] );
		$this->assertSame( 'read', $entry['scope'] );
	}

	public function test_generate_happy_path_honours_rate_limit_override(): void {
		$this->gate();
		$_POST = array(
			'label'      => 'High-traffic worker',
			'scope'      => 'write',
			'rate_limit' => '300',
		);

		$stop = $this->invoke( array( ApiKeysAjax::class, 'handle_generate' ) );

		$this->assertTrue( $stop->is_success );
		$this->assertSame( 300, $stop->payload['entry']['rate_limit'] );
		$this->assertSame( 'write', $stop->payload['entry']['scope'] );
	}

	// --- revoke() ---

	public function test_revoke_rejects_missing_nonce(): void {
		$this->gate( false, true );
		$_POST = array( 'id' => 'k_11111111-2222-3333-4444-555555555555' );

		$stop = $this->invoke( array( ApiKeysAjax::class, 'handle_revoke' ) );

		$this->assertFalse( $stop->is_success );
		$this->assertSame( 403, $stop->status );
		$this->assertSame( 'swh_apikey_bad_nonce', $stop->payload['code'] );
	}

	public function test_revoke_rejects_user_without_manage_options(): void {
		$this->gate( true, false );
		$_POST = array( 'id' => 'k_11111111-2222-3333-4444-555555555555' );

		$stop = $this->invoke( array( ApiKeysAjax::class, 'handle_revoke' ) );

		$this->assertFalse( $stop->is_success );
		$this->assertSame( 403, $stop->status );
		$this->assertSame( 'swh_apikey_forbidden', $stop->payload['code'] );
	}

	public function test_revoke_validates_id_format(): void {
		$this->gate();
		$_POST = array( 'id' => 'not-a-key-id' );

		$stop = $this->invoke( array( ApiKeysAjax::class, 'handle_revoke' ) );

		$this->assertFalse( $stop->is_success );
		$this->assertSame( 400, $stop->status );
		$this->assertSame( 'swh_apikey_id_invalid', $stop->payload['code'] );
	}

	public function test_revoke_returns_error_on_unknown_id(): void {
		$this->gate();
		$_POST = array( 'id' => 'k_aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' );

		$stop = $this->invoke( array( ApiKeysAjax::class, 'handle_revoke' ) );

		$this->assertFalse( $stop->is_success );
		$this->assertSame( 400, $stop->status );
		$this->assertSame( 'swh_apikey_revoke_failed', $stop->payload['code'] );
	}

	public function test_revoke_happy_path_returns_revoked_at(): void {
		$this->gate();

		// Generate a key first to give the revoke a real id to hit.
		$_POST = array( 'label' => 'CI integration', 'scope' => 'read' );
		try {
			ApiKeysAjax::handle_generate();
		} catch ( ApiKeyAjaxStopException $e ) {
			$entry = $e->payload['entry'];
		}

		$_POST = array( 'id' => $entry['id'] );
		$stop  = $this->invoke( array( ApiKeysAjax::class, 'handle_revoke' ) );

		$this->assertTrue( $stop->is_success );
		$this->assertSame( 200, $stop->status );
		$this->assertArrayHasKey( 'revoked_at', $stop->payload );
		$this->assertIsInt( $stop->payload['revoked_at'] );
	}
}
