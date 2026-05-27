<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use SWH\Rest\Bootstrap;
use SWH\Rest\Errors;
use WP_Error;
use WP_Mock;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Unit tests for {@see \SWH\Rest\Bootstrap} — focused on Phase 7 (#367):
 *
 *  - `settings_public_permission()` — per-IP rate limit wrapper for the
 *    `settings/public` route.
 *  - `attach_rate_limit_headers()` — rest_post_dispatch filter that emits
 *    `X-RateLimit-*` (and `Retry-After` on 429) for responses whose request
 *    carries rate-limit state.
 *
 * Bucket-name assertions verify the IP is hash_hmac'd with `wp_salt('nonce')`
 * before being used as part of the transient key — invariant locked in
 * security-model.md (no raw IPs in transient keys, ever).
 */
final class BootstrapTest extends TestCase {

	/** @var array<string,mixed> */
	private array $transients = array();

	/** @var array<string,mixed> */
	private array $options = array();

	protected function setUp(): void {
		WP_Mock::setUp();
		$this->transients = array();
		$this->options    = array();

		WP_Mock::userFunction(
			'get_transient',
			array(
				'return' => function ( string $name ) {
					return $this->transients[ $name ] ?? false;
				},
			)
		);
		WP_Mock::userFunction(
			'set_transient',
			array(
				'return' => function ( string $name, $value, int $ttl = 0 ) {
					$this->transients[ $name ] = $value;
					return true;
				},
			)
		);
		WP_Mock::userFunction(
			'delete_transient',
			array( 'return' => true )
		);
		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => function ( string $name, $default = null ) {
					return $this->options[ $name ] ?? $default;
				},
			)
		);
		WP_Mock::userFunction(
			'update_option',
			array(
				'return' => function ( string $name, $value ) {
					$this->options[ $name ] = $value;
					return true;
				},
			)
		);
		WP_Mock::userFunction(
			'delete_option',
			array( 'return' => true )
		);
		WP_Mock::userFunction(
			'wp_salt',
			array( 'return' => 'test-salt-value-for-rate-limit-tests' )
		);
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_public_permission_allows_first_request(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		$request                = new WP_REST_Request();
		$result                 = Bootstrap::settings_public_permission( $request );
		$this->assertTrue( $result );

		$state = $request->get_param( '_swh_rate_limit_state' );
		$this->assertIsArray( $state );
		$this->assertSame( 20, $state['limit'] );
		$this->assertSame( 19, $state['remaining'] );
	}

	public function test_public_permission_blocks_after_20_requests(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		for ( $i = 0; $i < 20; $i++ ) {
			$req = new WP_REST_Request();
			$this->assertTrue( Bootstrap::settings_public_permission( $req ) );
		}
		$req    = new WP_REST_Request();
		$result = Bootstrap::settings_public_permission( $req );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Errors::RATE_LIMITED, $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );

		$state = $req->get_param( '_swh_rate_limit_state' );
		$this->assertIsArray( $state );
		$this->assertFalse( $state['allowed'] );
	}

	public function test_ip_is_hashed_before_being_used_as_bucket_key(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$request                = new WP_REST_Request();
		Bootstrap::settings_public_permission( $request );

		// Bucket name must contain the HMAC, never the raw IP.
		$expected_hash = hash_hmac( 'sha256', '198.51.100.7', 'test-salt-value-for-rate-limit-tests' );
		$bucket_key    = 'swh_rl_ip_' . $expected_hash;
		$this->assertArrayHasKey( $bucket_key, $this->transients, 'HMAC of IP is the bucket key' );

		foreach ( array_keys( $this->transients ) as $name ) {
			$this->assertStringNotContainsString(
				'198.51.100.7',
				$name,
				'Raw IP must never appear in a transient key'
			);
		}
	}

	public function test_missing_remote_addr_falls_back_to_default_bucket(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		$request = new WP_REST_Request();
		$result  = Bootstrap::settings_public_permission( $request );
		$this->assertTrue( $result, 'Missing IP must not error — uses 0.0.0.0 fallback bucket' );
	}

	public function test_attach_headers_skips_when_state_absent(): void {
		$request  = new WP_REST_Request();
		$response = new WP_REST_Response( null, 200 );
		$out      = Bootstrap::attach_rate_limit_headers( $response, null, $request );
		$this->assertSame( $response, $out );
		$this->assertSame( array(), $response->get_headers() );
	}

	public function test_attach_headers_writes_x_ratelimit_on_200(): void {
		$request = new WP_REST_Request();
		$request->set_param(
			'_swh_rate_limit_state',
			array(
				'allowed'   => true,
				'remaining' => 7,
				'reset'     => 1779900000,
				'limit'     => 20,
			)
		);
		$response = new WP_REST_Response( null, 200 );
		Bootstrap::attach_rate_limit_headers( $response, null, $request );

		$h = $response->get_headers();
		$this->assertSame( '20', $h['x-ratelimit-limit'] );
		$this->assertSame( '7', $h['x-ratelimit-remaining'] );
		$this->assertSame( '1779900000', $h['x-ratelimit-reset'] );
		$this->assertArrayNotHasKey( 'retry-after', $h );
	}

	public function test_attach_headers_emits_retry_after_on_429(): void {
		$request = new WP_REST_Request();
		$request->set_param(
			'_swh_rate_limit_state',
			array(
				'allowed'   => false,
				'remaining' => 0,
				'reset'     => time() + 30,
				'limit'     => 20,
			)
		);
		$response = new WP_REST_Response( null, 429 );
		Bootstrap::attach_rate_limit_headers( $response, null, $request );

		$h = $response->get_headers();
		$this->assertArrayHasKey( 'retry-after', $h );
		$this->assertGreaterThanOrEqual( 1, (int) $h['retry-after'] );
	}
}
