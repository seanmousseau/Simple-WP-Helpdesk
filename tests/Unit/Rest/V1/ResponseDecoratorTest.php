<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest\V1;

use PHPUnit\Framework\TestCase;
use SWH\Rest\V1\ResponseDecorator;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Unit tests for {@see \SWH\Rest\V1\ResponseDecorator}.
 *
 * Phase 7 of the v4.1.0 plan (#367). The decorator is the single place every
 * v4.1 controller calls to add `X-RateLimit-*` headers to a success response
 * — so testing it once covers the success path for all six controllers.
 */
final class ResponseDecoratorTest extends TestCase {

	public function test_emits_three_headers_when_state_present(): void {
		$request = new WP_REST_Request();
		$request->set_param(
			'_swh_rate_limit_state',
			array(
				'allowed'   => true,
				'remaining' => 42,
				'reset'     => 1779800000,
				'limit'     => 60,
			)
		);

		$response = new WP_REST_Response( array( 'ok' => true ), 200 );
		$decorated = ResponseDecorator::with_rate_limit_headers( $response, $request );

		$headers = $decorated->get_headers();
		$this->assertSame( '60', $headers['x-ratelimit-limit'] );
		$this->assertSame( '42', $headers['x-ratelimit-remaining'] );
		$this->assertSame( '1779800000', $headers['x-ratelimit-reset'] );
	}

	public function test_returns_response_unchanged_when_state_missing(): void {
		$request  = new WP_REST_Request();
		$response = new WP_REST_Response( array( 'ok' => true ), 200 );

		$decorated = ResponseDecorator::with_rate_limit_headers( $response, $request );
		$this->assertSame( array(), $decorated->get_headers() );
	}

	public function test_skips_when_state_is_malformed(): void {
		$request = new WP_REST_Request();
		// Missing required keys.
		$request->set_param( '_swh_rate_limit_state', array( 'allowed' => true ) );

		$response  = new WP_REST_Response();
		$decorated = ResponseDecorator::with_rate_limit_headers( $response, $request );
		$this->assertSame( array(), $decorated->get_headers() );
	}

	public function test_zero_remaining_emits_zero(): void {
		$request = new WP_REST_Request();
		$request->set_param(
			'_swh_rate_limit_state',
			array(
				'allowed'   => false,
				'remaining' => 0,
				'reset'     => 1779800099,
				'limit'     => 60,
			)
		);

		$response  = new WP_REST_Response( null, 429 );
		$decorated = ResponseDecorator::with_rate_limit_headers( $response, $request );
		$headers   = $decorated->get_headers();
		$this->assertSame( '0', $headers['x-ratelimit-remaining'] );
	}
}
