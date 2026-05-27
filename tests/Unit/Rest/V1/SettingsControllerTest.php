<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest\V1;

use PHPUnit\Framework\TestCase;
use SWH\Rest\Bootstrap;
use SWH\Rest\V1\SettingsController;
use WP_Mock;
use WP_REST_Request;

/**
 * Unit tests for {@see \SWH\Rest\V1\SettingsController} — Phase 5 of
 * v4.1.0 (#364), the PUBLIC (no-auth) `swh/v1/settings/public` endpoint.
 *
 * The interesting assertions are about what is ABSENT from the response,
 * not what is present. The drift-detector in
 * {@see PublicSettingsAllowlistTest} guards the source-of-truth file;
 * this suite confirms the controller actually delegates to it and never
 * leaks unrelated or secret-bearing settings.
 */
final class SettingsControllerTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		Bootstrap::reset_for_tests();

		WP_Mock::userFunction( 'rest_url', array( 'return' => function ( string $p = '' ): string {
			return 'https://example.com/wp-json/' . ltrim( $p, '/' );
		} ) );
		require_once SWH_PLUGIN_DIR . 'src/Rest/V1/PublicSettingsAllowlist.php';
		require_once SWH_PLUGIN_DIR . 'src/Rest/V1/SettingsController.php';
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_register_routes_uses_public_permission_callback(): void {
		$registered = array();
		WP_Mock::userFunction( 'register_rest_route', array(
			'return' => function ( string $ns, string $route, array $args ) use ( &$registered ) {
				$registered[] = array( 'ns' => $ns, 'route' => $route, 'args' => $args );
				return true;
			},
		) );

		( new SettingsController() )->register_routes();

		$this->assertCount( 1, $registered );
		$this->assertSame( '/settings/public', $registered[0]['route'] );

		// The route MUST carry the public-permission wrapper from Bootstrap.
		// Phase 7 (#367) introduced a per-IP rate-limit check that wraps the
		// previously-bare __return_true callback. The wrapper still returns
		// true on the happy path (no auth required) and gates only on
		// over-limit — semantically the route is still anonymous-readable.
		$found_method_entry = false;
		foreach ( $registered[0]['args'] as $k => $v ) {
			if ( is_int( $k ) ) {
				$found_method_entry = true;
				$this->assertSame(
					array( \SWH\Rest\Bootstrap::class, 'settings_public_permission' ),
					$v['permission_callback'],
					'settings/public must use Bootstrap::settings_public_permission — public route + per-IP rate limit (Phase 7).'
				);
			}
		}
		$this->assertTrue( $found_method_entry );
	}

	public function test_response_contains_only_allowlisted_keys(): void {
		WP_Mock::userFunction( 'get_option', array(
			'return' => function ( string $key, $default = false ) {
				// A grab-bag of settings the operator might have — including
				// secret-bearing ones — to prove the controller never reads
				// or returns them.
				$map = array(
					'swh_ticket_priorities'   => 'Low, Medium, High',
					'swh_ticket_statuses'     => 'Open, Resolved',
					'swh_sla_warn_hours'      => '4',
					'swh_sla_breach_hours'    => '8',
					'swh_csat_enabled'        => true,
					'swh_ticket_page_id'      => 42,
					'swh_max_upload_count'    => 5,
				);
				return $map[ $key ] ?? $default;
			},
		) );

		$response = ( new SettingsController() )->get_public_settings( new WP_REST_Request( 'GET', '/swh/v1/settings/public' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$keys = array_keys( $data );
		sort( $keys );
		$this->assertSame(
			array( 'features', 'priority_labels', 'sla_thresholds', 'status_labels' ),
			$keys,
			'settings/public must return ONLY the locked allowlist keys.'
		);
	}

	public function test_unrelated_options_are_absent_from_response(): void {
		// Even if get_option returns a "secret" value for an unrelated key,
		// the controller MUST NOT include it. This proves the response is
		// built from the explicit allowlist and not by enumerating options.
		WP_Mock::userFunction( 'get_option', array(
			'return' => function ( string $key, $default = false ) {
				if ( 'swh_inbound_secret' === $key ) {
					return 'DO_NOT_LEAK_THIS_SECRET';
				}
				if ( 'swh_recaptcha_secret_key' === $key ) {
					return 'DO_NOT_LEAK_THIS_EITHER';
				}
				return $default;
			},
		) );

		$response = ( new SettingsController() )->get_public_settings( new WP_REST_Request( 'GET', '/swh/v1/settings/public' ) );
		$flat     = (string) json_encode( $response->get_data() );

		$this->assertStringNotContainsString( 'DO_NOT_LEAK_THIS_SECRET', $flat );
		$this->assertStringNotContainsString( 'DO_NOT_LEAK_THIS_EITHER', $flat );
		$this->assertStringNotContainsString( 'inbound_secret', $flat );
		$this->assertStringNotContainsString( 'recaptcha', $flat );
		$this->assertStringNotContainsString( 'smtp', $flat );
		$this->assertStringNotContainsString( 'api_keys', $flat );
		$this->assertStringNotContainsString( 'email_templates', $flat );
	}

	public function test_features_sub_schema_is_booleans_only(): void {
		WP_Mock::userFunction( 'get_option', array(
			'return' => function ( string $key, $default = false ) {
				$map = array(
					'swh_csat_enabled'     => 'yes',
					'swh_ticket_page_id'   => 0,
					'swh_max_upload_count' => 5,
				);
				return $map[ $key ] ?? $default;
			},
		) );

		$response = ( new SettingsController() )->get_public_settings( new WP_REST_Request( 'GET', '/swh/v1/settings/public' ) );
		$data     = $response->get_data();
		$this->assertIsArray( $data['features'] );
		foreach ( $data['features'] as $key => $v ) {
			$this->assertIsBool( $v, sprintf( 'features.%s must be a boolean.', $key ) );
		}
	}

	public function test_sla_thresholds_locked_keys(): void {
		WP_Mock::userFunction( 'get_option', array( 'return' => '' ) );

		$response = ( new SettingsController() )->get_public_settings( new WP_REST_Request( 'GET', '/swh/v1/settings/public' ) );
		$data     = $response->get_data();
		$this->assertSame(
			array( 'low', 'medium', 'high', 'urgent' ),
			array_keys( $data['sla_thresholds'] )
		);
	}
}
