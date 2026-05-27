<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use SWH\Rest\Errors;
use WP_Error;

/**
 * Unit tests for {@see \SWH\Rest\Errors} — the unified v4.1.0 REST error envelope.
 *
 * Verifies the WP_Error → JSON envelope shape locked in plan_v4.1.0.md Phase 2:
 *
 * ```json
 * { "code": "swh_rest_<reason>", "message": "...", "data": { "status": <int> } }
 * ```
 *
 * WP core's `WP_REST_Server::error_to_response()` copies `data.status` into the
 * HTTP response status, so the envelope test only needs to verify the
 * WP_Error data carries `status` for every code in the enum.
 */
final class ErrorsTest extends TestCase {

	public function test_respond_returns_wp_error_with_locked_envelope_fields(): void {
		$err = Errors::respond( Errors::AUTH_REQUIRED, 401, 'Authentication required.' );

		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'swh_rest_auth_required', $err->get_error_code() );
		$this->assertSame( 'Authentication required.', $err->get_error_message() );
		$this->assertSame( array( 'status' => 401 ), $err->get_error_data() );
	}

	/**
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function code_enum_provider(): array {
		return array(
			'auth_required'       => array( Errors::AUTH_REQUIRED, 401 ),
			'auth_invalid'        => array( Errors::AUTH_INVALID, 401 ),
			'scope_insufficient'  => array( Errors::SCOPE_INSUFFICIENT, 403 ),
			'capability_required' => array( Errors::CAPABILITY_REQUIRED, 403 ),
			'rate_limited'        => array( Errors::RATE_LIMITED, 429 ),
			'not_found'           => array( Errors::NOT_FOUND, 404 ),
			'validation_failed'   => array( Errors::VALIDATION_FAILED, 400 ),
		);
	}

	/**
	 * @dataProvider code_enum_provider
	 */
	public function test_every_enum_code_passes_status_through( string $code, int $status ): void {
		$err = Errors::respond( $code, $status, 'msg' );

		$data = $err->get_error_data();
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( $status, $data['status'] );
	}

	public function test_enum_codes_have_locked_string_values(): void {
		// Locked by docs/internal/plan_v4.1.0.md Phase 2 — controllers and
		// integrators key off these exact strings. Anything that bumps a
		// constant value is a breaking change.
		$this->assertSame( 'swh_rest_auth_required', Errors::AUTH_REQUIRED );
		$this->assertSame( 'swh_rest_auth_invalid', Errors::AUTH_INVALID );
		$this->assertSame( 'swh_rest_scope_insufficient', Errors::SCOPE_INSUFFICIENT );
		$this->assertSame( 'swh_rest_capability_required', Errors::CAPABILITY_REQUIRED );
		$this->assertSame( 'swh_rest_rate_limited', Errors::RATE_LIMITED );
		$this->assertSame( 'swh_rest_not_found', Errors::NOT_FOUND );
		$this->assertSame( 'swh_rest_validation_failed', Errors::VALIDATION_FAILED );
	}
}
