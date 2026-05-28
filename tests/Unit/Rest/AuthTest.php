<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use SWH\Rest\Auth;
use SWH\Rest\Errors;
use WP_Error;
use WP_Mock;
use WP_REST_Request;

/**
 * Unit tests for {@see \SWH\Rest\Auth} — the v4.1.0 Phase 2 permission helper.
 *
 * Every row of the capability matrix (logged-in vs API key, read vs write) is
 * covered alongside every error case in the locked enum
 * (see docs/internal/plan_v4.1.0.md Phase 2).
 *
 * Strategy:
 *   - Stub `is_user_logged_in` and `current_user_can` via WP_Mock for the
 *     logged-in user path tests.
 *   - Inject a deterministic `$api_key_lookup` callable for the API-key path
 *     tests so we don't need to round-trip through `ApiKeys` storage. The
 *     test seam is documented on `Auth::check_request()`.
 *   - Track `ApiKeys::touch_last_used()` calls via the integrations bag
 *     option stub (write-rate-limited transient guard makes it observable).
 */
final class AuthTest extends TestCase {

	/** @var array<string,mixed> */
	private static array $options = array();

	/** @var array<string,mixed> */
	private static array $transients = array();

	protected function setUp(): void {
		WP_Mock::setUp();

		self::$options    = array();
		self::$transients = array();

		// Option/transient stubs power ApiKeys::touch_last_used() observation.
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
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	// -----------------------------------------------------------------------
	// Logged-in WP user path (matrix rows 1 + 2)
	// -----------------------------------------------------------------------

	public function test_logged_in_user_with_edit_posts_passes_read(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => true ) );
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );

		$request = new WP_REST_Request();
		$this->assertTrue( Auth::check_request( $request, 'read' ) );
	}

	public function test_logged_in_user_with_edit_posts_passes_write(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => true ) );
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );

		$request = new WP_REST_Request();
		$this->assertTrue( Auth::check_request( $request, 'write' ) );
	}

	public function test_logged_in_user_without_edit_posts_gets_capability_required(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => true ) );
		WP_Mock::userFunction( 'current_user_can', array( 'return' => false ) );

		$request = new WP_REST_Request();
		$result  = Auth::check_request( $request, 'read' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Errors::CAPABILITY_REQUIRED, $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	// -----------------------------------------------------------------------
	// Missing / malformed header (Errors::AUTH_REQUIRED)
	// -----------------------------------------------------------------------

	public function test_no_header_not_logged_in_gets_auth_required(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$request = new WP_REST_Request();
		$result  = Auth::check_request( $request, 'read' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Errors::AUTH_REQUIRED, $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_malformed_header_token_scheme_gets_auth_required(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Token swhk_anything' );
		$result = Auth::check_request( $request, 'read' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Errors::AUTH_REQUIRED, $result->get_error_code() );
	}

	public function test_bearer_with_empty_token_gets_auth_required(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer    ' );
		$result = Auth::check_request( $request, 'read' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Errors::AUTH_REQUIRED, $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// Invalid token (Errors::AUTH_INVALID)
	// -----------------------------------------------------------------------

	public function test_bearer_with_wrong_prefix_gets_auth_invalid(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer notakey-completely-wrong' );
		$result = Auth::check_request( $request, 'read' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Errors::AUTH_INVALID, $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_bearer_with_correct_prefix_but_unknown_token_gets_auth_invalid(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer swhk_unknownunknownunknownunknownunkn' );
		// Lookup returns null — simulates a non-existent or revoked key
		// (both code paths collapse here per ApiKeys::find_by_plaintext).
		$lookup = static fn ( string $p ): ?array => null;
		$result = Auth::check_request( $request, 'read', $lookup );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Errors::AUTH_INVALID, $result->get_error_code() );
	}

	public function test_revoked_key_lookup_returns_null_so_auth_invalid(): void {
		// Documents that revoked keys collapse into AUTH_INVALID by design:
		// ApiKeys::find_by_plaintext() returns null for revoked entries, so
		// the Auth layer never even sees the entry.
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer swhk_revokedrevokedrevokedrevokedrevok' );
		$lookup = static fn ( string $p ): ?array => null;
		$result = Auth::check_request( $request, 'read', $lookup );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Errors::AUTH_INVALID, $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// Scope checks (Errors::SCOPE_INSUFFICIENT)
	// -----------------------------------------------------------------------

	public function test_valid_read_key_on_read_route_succeeds_and_touches_last_used(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		// Seed the integrations bag so touch_last_used has something to find.
		$entry = $this->make_entry( 'k_read', 'read' );
		self::$options['swh_settings_integrations'] = array( 'api_keys' => array( $entry ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer swhk_validreadkeyplaintext0000000000000' );

		$touched = false;
		$lookup  = function ( string $p ) use ( $entry, &$touched ): ?array {
			$touched = true;
			return $entry;
		};

		$result = Auth::check_request( $request, 'read', $lookup );

		$this->assertTrue( $result );
		$this->assertTrue( $touched, 'lookup was invoked' );
		$this->assertArrayHasKey(
			'swh_apikey_touch_k_read',
			self::$transients,
			'touch_last_used() ran and set its rate-limit guard'
		);
	}

	public function test_valid_write_key_on_read_route_succeeds(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$entry = $this->make_entry( 'k_write', 'write' );
		self::$options['swh_settings_integrations'] = array( 'api_keys' => array( $entry ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer swhk_validwritekey0000000000000000000000' );
		$lookup = static fn ( string $p ): ?array => $entry;

		$this->assertTrue( Auth::check_request( $request, 'read', $lookup ) );
	}

	public function test_valid_write_key_on_write_route_succeeds(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$entry = $this->make_entry( 'k_write', 'write' );
		self::$options['swh_settings_integrations'] = array( 'api_keys' => array( $entry ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer swhk_validwritekey0000000000000000000000' );
		$lookup = static fn ( string $p ): ?array => $entry;

		$this->assertTrue( Auth::check_request( $request, 'write', $lookup ) );
	}

	public function test_valid_read_key_on_write_route_gets_scope_insufficient(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$entry = $this->make_entry( 'k_read', 'read' );
		self::$options['swh_settings_integrations'] = array( 'api_keys' => array( $entry ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer swhk_validreadkeyplaintext0000000000000' );
		$lookup = static fn ( string $p ): ?array => $entry;

		$result = Auth::check_request( $request, 'write', $lookup );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Errors::SCOPE_INSUFFICIENT, $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	// -----------------------------------------------------------------------
	// Attribution (Phase 9 audit log relies on this)
	// -----------------------------------------------------------------------

	public function test_successful_api_key_auth_stashes_entry_on_request(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$entry = $this->make_entry( 'k_attrib', 'write' );
		self::$options['swh_settings_integrations'] = array( 'api_keys' => array( $entry ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer swhk_attribkey00000000000000000000000000' );
		$lookup = static fn ( string $p ): ?array => $entry;

		$this->assertTrue( Auth::check_request( $request, 'read', $lookup ) );
		$this->assertSame(
			$entry,
			$request->get_param( '_swh_api_key' ),
			'_swh_api_key request param carries the full entry for Phase 9 attribution'
		);
	}

	// -----------------------------------------------------------------------
	// Rate limiting (Phase 7 #367)
	// -----------------------------------------------------------------------

	public function test_successful_auth_stashes_rate_limit_state_on_request(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$entry = $this->make_entry( 'k_rl_state', 'read' );
		self::$options['swh_settings_integrations'] = array( 'api_keys' => array( $entry ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer swhk_rlstatekey0000000000000000000000000' );
		$lookup = static fn ( string $p ): ?array => $entry;

		$this->assertTrue( Auth::check_request( $request, 'read', $lookup ) );

		$state = $request->get_param( '_swh_rate_limit_state' );
		$this->assertIsArray( $state );
		$this->assertTrue( $state['allowed'] );
		$this->assertSame( 60, $state['limit'], 'Default key gets the 60/min default budget' );
		$this->assertSame( 59, $state['remaining'] );
	}

	public function test_per_key_override_replaces_default_limit(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$entry               = $this->make_entry( 'k_rl_override', 'read' );
		$entry['rate_limit'] = 10;
		self::$options['swh_settings_integrations'] = array( 'api_keys' => array( $entry ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer swhk_rloverridekey00000000000000000000000' );
		$lookup = static fn ( string $p ): ?array => $entry;

		$this->assertTrue( Auth::check_request( $request, 'read', $lookup ) );
		$state = $request->get_param( '_swh_rate_limit_state' );
		$this->assertSame( 10, $state['limit'] );
		$this->assertSame( 9, $state['remaining'] );
	}

	public function test_over_limit_returns_rate_limited_error_with_state_on_request(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$entry               = $this->make_entry( 'k_rl_burn', 'read' );
		$entry['rate_limit'] = 2;
		self::$options['swh_settings_integrations'] = array( 'api_keys' => array( $entry ) );

		$lookup = static fn ( string $p ): ?array => $entry;

		// Burn the budget.
		for ( $i = 0; $i < 2; $i++ ) {
			$req = new WP_REST_Request();
			$req->set_header( 'authorization', 'Bearer swhk_rlburnkey00000000000000000000000000' );
			$this->assertTrue( Auth::check_request( $req, 'read', $lookup ) );
		}

		// Next request — over limit.
		$req = new WP_REST_Request();
		$req->set_header( 'authorization', 'Bearer swhk_rlburnkey00000000000000000000000000' );
		$result = Auth::check_request( $req, 'read', $lookup );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Errors::RATE_LIMITED, $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( 'retry_after', $result->get_error_data() );

		// State is still stashed so the rest_post_dispatch filter can read it.
		$state = $req->get_param( '_swh_rate_limit_state' );
		$this->assertIsArray( $state );
		$this->assertFalse( $state['allowed'] );
		$this->assertSame( 0, $state['remaining'] );
	}

	// -----------------------------------------------------------------------
	// Per-request idempotency (v4.1.0 fix — rest_send_allow_header re-fires)
	// -----------------------------------------------------------------------

	public function test_check_request_only_increments_rate_limit_once_per_request(): void {
		// Regression: WP REST's rest_send_allow_header() re-invokes the
		// permission_callback once per handler on the matched route to build
		// the `Allow:` header. Without per-request idempotency, every HTTP
		// request would double- or triple-count against the rate limit.
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$entry = $this->make_entry( 'k_idem_count', 'read' );
		self::$options['swh_settings_integrations'] = array( 'api_keys' => array( $entry ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer swhk_idemcountkey0000000000000000000000' );

		$lookup_calls = 0;
		$lookup       = function ( string $p ) use ( $entry, &$lookup_calls ): ?array {
			++$lookup_calls;
			return $entry;
		};

		// Three calls, same request — simulates rest_send_allow_header walking
		// the route's handlers (GET + Allow-header build).
		$this->assertTrue( Auth::check_request( $request, 'read', $lookup ) );
		$this->assertTrue( Auth::check_request( $request, 'read', $lookup ) );
		$this->assertTrue( Auth::check_request( $request, 'read', $lookup ) );

		$this->assertSame( 1, $lookup_calls, 'lookup (and therefore bcrypt + rate-limit) ran exactly once' );

		// Rate-limit state must reflect a single increment.
		$state = $request->get_param( '_swh_rate_limit_state' );
		$this->assertIsArray( $state );
		$this->assertSame( 60, $state['limit'] );
		$this->assertSame( 59, $state['remaining'], 'Exactly one decrement, not three' );
	}

	public function test_check_request_scope_cache_is_scope_aware(): void {
		// A single route can bind handlers with different required scopes (e.g.
		// GET wants read, PATCH wants write). The Allow-header walk hits both.
		// Each scope answer must compute independently — read should pass on a
		// read key, write should fail with SCOPE_INSUFFICIENT — but each scope
		// answer is only computed once.
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$entry = $this->make_entry( 'k_scope_cache', 'read' );
		self::$options['swh_settings_integrations'] = array( 'api_keys' => array( $entry ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer swhk_scopecachekey000000000000000000000' );

		$lookup_calls = 0;
		$lookup       = function ( string $p ) use ( $entry, &$lookup_calls ): ?array {
			++$lookup_calls;
			return $entry;
		};

		// First scope: read — passes.
		$this->assertTrue( Auth::check_request( $request, 'read', $lookup ) );
		// Second scope: write — fails (scope insufficient on a read key).
		$write_result = Auth::check_request( $request, 'write', $lookup );
		$this->assertInstanceOf( WP_Error::class, $write_result );
		$this->assertSame( Errors::SCOPE_INSUFFICIENT, $write_result->get_error_code() );

		// Re-invoke both scopes — must hit their caches, no extra lookups.
		$this->assertTrue( Auth::check_request( $request, 'read', $lookup ) );
		$this->assertInstanceOf( WP_Error::class, Auth::check_request( $request, 'write', $lookup ) );

		$this->assertSame( 2, $lookup_calls, 'Exactly one lookup per scope (read + write), regardless of repeat calls' );
	}

	public function test_check_request_only_runs_password_verify_once_per_request(): void {
		// The bcrypt verify is the perf hot path (cost 12 ≈ 80ms). Asserted
		// indirectly via the lookup-call counter — lookup is what invokes the
		// hash check inside ApiKeys::find_by_plaintext in production.
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$entry = $this->make_entry( 'k_bcrypt', 'write' );
		self::$options['swh_settings_integrations'] = array( 'api_keys' => array( $entry ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer swhk_bcryptkey0000000000000000000000000' );

		$lookup_calls = 0;
		$lookup       = function ( string $p ) use ( $entry, &$lookup_calls ): ?array {
			++$lookup_calls;
			return $entry;
		};

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( Auth::check_request( $request, 'write', $lookup ) );
		}

		$this->assertSame( 1, $lookup_calls, 'bcrypt-bearing lookup fires exactly once per request' );
	}

	public function test_bucket_key_uses_swh_rl_api_prefix_with_key_id(): void {
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );

		$entry = $this->make_entry( 'k_bucket_id', 'read' );
		self::$options['swh_settings_integrations'] = array( 'api_keys' => array( $entry ) );

		$request = new WP_REST_Request();
		$request->set_header( 'authorization', 'Bearer swhk_bucketidkey0000000000000000000000000' );
		$lookup = static fn ( string $p ): ?array => $entry;

		$this->assertTrue( Auth::check_request( $request, 'read', $lookup ) );
		$this->assertArrayHasKey(
			'swh_rl_api_k_bucket_id',
			self::$transients,
			'RateLimiter wrote to the swh_rl_api_<id> transient — bucket is per-key'
		);
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Build an `ApiKeys`-shaped entry, including the hash, so the integrations
	 * bag round-trips correctly through ApiKeys::touch_last_used().
	 *
	 * @return array<string,mixed>
	 */
	private function make_entry( string $id, string $scope ): array {
		return array(
			'id'           => $id,
			'label'        => 'test',
			'scope'        => $scope,
			'prefix'       => 'swhk_xxxxxx',
			'hash'         => '$2y$04$irrelevantirrelevantirrelevantirrelev',
			'created_at'   => 1,
			'created_by'   => 1,
			'last_used_at' => 0,
			'rate_limit'   => null,
			'revoked_at'   => null,
		);
	}
}
