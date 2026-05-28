<?php
declare(strict_types=1);

namespace SWH\Rest;

use WP_Error;

/**
 * Unified REST error envelope for the v4.1.0 `swh/v1` namespace.
 *
 * Every Phase 2+ permission_callback and route callback returns a `WP_Error`
 * shaped via {@see Errors::respond()} so the JSON body produced by
 * `WP_REST_Server::error_to_response()` is always:
 *
 * ```json
 * { "code": "swh_rest_<reason>", "message": "Human readable", "data": { "status": <int> } }
 * ```
 *
 * WP core copies `data.status` from the `$data` arg of the `WP_Error`
 * constructor into the HTTP response status, so the helper passes `status`
 * as the only entry in `$data`.
 *
 * Error code enum (locked across all v4.1.0 phases):
 *
 * | Constant                 | Status | Code string                    |
 * |--------------------------|--------|--------------------------------|
 * | AUTH_REQUIRED            | 401    | swh_rest_auth_required         |
 * | AUTH_INVALID             | 401    | swh_rest_auth_invalid          |
 * | SCOPE_INSUFFICIENT       | 403    | swh_rest_scope_insufficient    |
 * | CAPABILITY_REQUIRED      | 403    | swh_rest_capability_required   |
 * | RATE_LIMITED             | 429    | swh_rest_rate_limited          |
 * | NOT_FOUND                | 404    | swh_rest_not_found             |
 * | VALIDATION_FAILED        | 400    | swh_rest_validation_failed     |
 *
 * @since 4.1.0
 */
final class Errors {

	public const AUTH_REQUIRED       = 'swh_rest_auth_required';
	public const AUTH_INVALID        = 'swh_rest_auth_invalid';
	public const SCOPE_INSUFFICIENT  = 'swh_rest_scope_insufficient';
	public const CAPABILITY_REQUIRED = 'swh_rest_capability_required';
	public const RATE_LIMITED        = 'swh_rest_rate_limited';
	public const NOT_FOUND           = 'swh_rest_not_found';
	public const VALIDATION_FAILED   = 'swh_rest_validation_failed';

	/**
	 * Build a `WP_Error` whose JSON envelope matches the locked v4.1.0 shape.
	 *
	 * @param string $code    Error code string (use one of the class constants).
	 * @param int    $status  HTTP status code (passed through to the response).
	 * @param string $message Human-readable message. Translation is the caller's responsibility.
	 * @return WP_Error
	 */
	public static function respond( string $code, int $status, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
