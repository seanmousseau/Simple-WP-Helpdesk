<?php
/**
 * Domain tests for swh_submit_csat_ajax() — closes the v4.0.0 stub bug.
 *
 * The earlier CSAT coverage in TicketTest tested the "building blocks" of
 * the AJAX handler but skipped the handler itself because wp_send_json_*
 * calls exit. We work around that by stubbing wp_send_json_error /
 * wp_send_json_success to throw a typed exception we can catch and assert
 * against. That lets us run the real handler end-to-end and confirm:
 *
 *   - Invalid request paths emit a 400 envelope and DO NOT touch meta.
 *   - Non-closed-status tickets are rejected (the v3.7 #218 guard).
 *   - Valid submissions write _ticket_csat AND fire swh_csat_submitted
 *     with the documented signature (int $ticket_id, int $rating).
 *
 * @see docs/internal/test-coverage-matrix.md — gap #18 (CSAT real path)
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

namespace SWH\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * Marker exception used by stubbed wp_send_json_* functions so the handler's
 * `exit;` short-circuit becomes observable in tests.
 */
final class CsatStopException extends \RuntimeException {
	public bool $is_success;
	/** @var array<string,mixed> */
	public array $payload;
	public int $status;
	/**
	 * @param array<string, mixed> $payload Payload passed to wp_send_json_*.
	 */
	public function __construct( bool $is_success, array $payload, int $status ) {
		parent::__construct( $is_success ? 'csat_success' : 'csat_error' );
		$this->is_success = $is_success;
		$this->payload    = $payload;
		$this->status     = $status;
	}
}

final class CsatAjaxTest extends TestCase {

	/**
	 * @var array<string, mixed> Captured update_post_meta calls keyed by "post_id:meta_key".
	 */
	private array $meta_writes = array();

	protected function setUp(): void {
		WP_Mock::setUp();
		$this->meta_writes = array();

		// absint() is a WP global — define a stub if running without WP.
		if ( ! function_exists( 'absint' ) ) {
			require_once __DIR__ . '/_csat_absint_stub.php';
		}

		// Load helpers.php FIRST so its real function definitions land before
		// class-ticket.php and before WP_Mock's userFunction() tries to
		// register a same-named stub (eval-defined stubs collide with later
		// require_once of helpers.php in sibling tests).
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'includes/class-ticket.php';

		// Stub wp_send_json_* to surface as catchable exceptions.
		WP_Mock::userFunction(
			'wp_send_json_error',
			array(
				'return' => function ( $payload, $status = 200 ) {
					throw new CsatStopException( false, (array) $payload, (int) $status );
				},
			)
		);
		WP_Mock::userFunction(
			'wp_send_json_success',
			array(
				'return' => function ( $payload = null, $status = 200 ) {
					throw new CsatStopException( true, (array) ( $payload ?? array() ), (int) $status );
				},
			)
		);

		// Sanitisation helpers used by the handler.
		WP_Mock::userFunction( 'wp_unslash', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction(
			'sanitize_text_field',
			array( 'return' => fn( $v ) => is_string( $v ) ? trim( strip_tags( $v ) ) : '' )
		);
		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );

		// swh_get_defaults() is loaded from helpers.php above; no stub needed.
		// swh_get_string_option() reads get_option() internally — return 'Closed'
		// for any key so the closed-status guard sees a closed ticket.
		WP_Mock::userFunction(
			'get_option',
			array( 'return' => 'Closed' )
		);

		// Capture meta writes.
		WP_Mock::userFunction(
			'update_post_meta',
			array(
				'return' => function ( $post_id, $key, $value ) {
					$this->meta_writes[ $post_id . ':' . $key ] = $value;
					return true;
				},
			)
		);

		// Hook firing is asserted via WP_Mock::expectAction() in each test —
		// no userFunction stub needed for do_action.
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		$_POST = array();
	}

	/**
	 * Invokes the handler and either returns the captured stop or rethrows on
	 * unexpected exceptions.
	 */
	private function invoke(): CsatStopException {
		try {
			swh_submit_csat_ajax();
		} catch ( CsatStopException $e ) {
			return $e;
		}
		$this->fail( 'Handler did not call wp_send_json_* — control reached the end without exit.' );
	}

	public function test_invalid_nonce_rejected_with_400_envelope(): void {
		$_POST = array(
			'ticket_id' => '42',
			'rating'    => '5',
			'nonce'     => 'bad-nonce',
		);
		WP_Mock::userFunction( 'wp_verify_nonce', array( 'return' => false ) );

		$stop = $this->invoke();
		$this->assertFalse( $stop->is_success );
		$this->assertSame( 400, $stop->status );
		$this->assertSame( 'Invalid request.', $stop->payload['message'] );
		$this->assertEmpty( $this->meta_writes, 'Rejected requests must not write _ticket_csat.' );
		// (No expectAction call — WP_Mock fails the test if swh_csat_submitted fires.)
	}

	public function test_rating_below_range_rejected(): void {
		$_POST = array(
			'ticket_id' => '42',
			'rating'    => '0',
			'nonce'     => 'good-nonce',
		);
		WP_Mock::userFunction( 'wp_verify_nonce', array( 'return' => 1 ) );

		$stop = $this->invoke();
		$this->assertFalse( $stop->is_success );
		$this->assertSame( 400, $stop->status );
		$this->assertSame( 'Invalid request.', $stop->payload['message'] );
	}

	public function test_rating_above_range_rejected(): void {
		$_POST = array(
			'ticket_id' => '42',
			'rating'    => '6',
			'nonce'     => 'good-nonce',
		);
		WP_Mock::userFunction( 'wp_verify_nonce', array( 'return' => 1 ) );

		$stop = $this->invoke();
		$this->assertFalse( $stop->is_success );
		$this->assertSame( 400, $stop->status );
	}

	public function test_missing_ticket_returns_400_envelope(): void {
		$_POST = array(
			'ticket_id' => '99',
			'rating'    => '5',
			'nonce'     => 'good-nonce',
		);
		WP_Mock::userFunction( 'wp_verify_nonce', array( 'return' => 1 ) );
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 99 ),
				'return' => null,
			)
		);

		$stop = $this->invoke();
		$this->assertFalse( $stop->is_success );
		$this->assertSame( 'Invalid ticket.', $stop->payload['message'] );
	}

	public function test_non_helpdesk_post_type_rejected(): void {
		$_POST = array(
			'ticket_id' => '7',
			'rating'    => '5',
			'nonce'     => 'good-nonce',
		);
		WP_Mock::userFunction( 'wp_verify_nonce', array( 'return' => 1 ) );
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 7 ),
				'return' => (object) array(
					'ID'        => 7,
					'post_type' => 'post',
				),
			)
		);

		$stop = $this->invoke();
		$this->assertSame( 'Invalid ticket.', $stop->payload['message'] );
	}

	public function test_non_closed_ticket_rejected(): void {
		// The v3.7 #218 guard: CSAT only collected on closed tickets.
		$_POST = array(
			'ticket_id' => '42',
			'rating'    => '4',
			'nonce'     => 'good-nonce',
		);
		WP_Mock::userFunction( 'wp_verify_nonce', array( 'return' => 1 ) );
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 42 ),
				'return' => (object) array(
					'ID'        => 42,
					'post_type' => 'helpdesk_ticket',
				),
			)
		);
		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'args'   => array( 42, '_ticket_status', true ),
				'return' => 'Open',
			)
		);

		$stop = $this->invoke();
		$this->assertFalse( $stop->is_success );
		$this->assertSame( 'Ticket is not closed.', $stop->payload['message'] );
		$this->assertEmpty( $this->meta_writes, 'Non-closed ticket must not record CSAT.' );
	}

	public function test_valid_submission_records_meta_and_fires_hook(): void {
		// The full happy path that the v4.0.0 stub never covered: valid
		// nonce + rating + ticket + closed-status → _ticket_csat is written
		// AND swh_csat_submitted fires with the documented (int, int) signature.
		$_POST = array(
			'ticket_id' => '42',
			'rating'    => '5',
			'nonce'     => 'good-nonce',
		);
		WP_Mock::userFunction( 'wp_verify_nonce', array( 'return' => 1 ) );
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 42 ),
				'return' => (object) array(
					'ID'        => 42,
					'post_type' => 'helpdesk_ticket',
				),
			)
		);
		// swh_get_string_meta('_ticket_status') reads get_post_meta internally;
		// stub the underlying call to return the closed-status sentinel.
		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'args'   => array( 42, '_ticket_status', true ),
				'return' => 'Closed',
			)
		);

		// Hook must fire exactly once with documented signature (int, int).
		WP_Mock::expectAction( 'swh_csat_submitted', 42, 5 );

		$stop = $this->invoke();
		$this->assertTrue( $stop->is_success, 'Valid submission must hit wp_send_json_success.' );

		$this->assertSame(
			5,
			$this->meta_writes['42:_ticket_csat'] ?? null,
			'_ticket_csat must be set to the submitted rating.'
		);
	}
}
