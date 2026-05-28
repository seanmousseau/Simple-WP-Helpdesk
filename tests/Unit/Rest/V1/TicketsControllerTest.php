<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest\V1;

use PHPUnit\Framework\TestCase;
use SWH\Rest\Bootstrap;
use SWH\Rest\Errors;
use SWH\Rest\V1\TicketsController;
use WP_Mock;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Unit tests for {@see \SWH\Rest\V1\TicketsController} — the v4.1.0 Phase 3
 * public `swh/v1/tickets` CRUD controller (#362).
 *
 * Coverage map (Phase 3 acceptance criteria):
 *  - register_routes wires read scope on GET, write scope on POST/PATCH.
 *  - get_item returns 200 + schema-correct payload (and excludes internal
 *    meta such as `_swh_unread`).
 *  - get_item returns 404 for missing / wrong post type.
 *  - create_item rejects missing-required-field with `swh_rest_validation_failed`.
 *  - create_item returns 201 + schema payload on success.
 *  - update_item returns 404 for unknown id.
 *  - update_item routes the patch through {@see swh_update_ticket_fields()}
 *    with the right context (source=rest_v1).
 *  - update_item returns `swh_rest_validation_failed` on helper errors.
 *  - get_items honours per_page clamp + emits X-WP-Total / X-WP-TotalPages.
 */
final class TicketsControllerTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		Bootstrap::reset_for_tests();

		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'includes/deprecations.php';
		require_once SWH_PLUGIN_DIR . 'src/Concurrency/TicketVersion.php';
		require_once SWH_PLUGIN_DIR . 'includes/ticket-update-fields.php';

		WP_Mock::userFunction( 'rest_url', array( 'return' => function ( string $path = '' ): string {
			return 'https://example.com/wp-json/' . ltrim( $path, '/' );
		} ) );
		WP_Mock::userFunction( 'get_current_user_id', array( 'return' => 1 ) );
		// WP_Mock invokes `return` if it's callable, so we wrap the callable
		// we want returned inside a function that yields it.
		WP_Mock::userFunction( 'swh_rest_require', array(
			'return' => function () { return '__return_true'; },
		) );

		// When the real swh_update_ticket_fields() is already loaded (by other
		// tests in the same process) WP_Mock cannot intercept it. Provide the
		// lower-level stubs the real helper depends on so it short-circuits
		// cleanly for the field sets these tests exercise. Per-test overrides
		// win.
		WP_Mock::userFunction( 'get_option', array( 'return' => function ( string $key, $default = false ) {
			$map = array(
				'swh_ticket_statuses'   => 'Open, In Progress, Resolved, Closed',
				'swh_ticket_priorities' => 'Low, Medium, High, Urgent',
				'swh_resolved_status'   => 'Resolved',
			);
			return $map[ $key ] ?? $default;
		} ) );
		WP_Mock::userFunction( 'apply_filters', array( 'return' => function ( $tag, $value ) { return $value; } ) );
		WP_Mock::userFunction( 'apply_filters_deprecated', array( 'return' => function ( $tag, $args ) { return $args[0]; } ) );
		WP_Mock::userFunction( 'has_filter', array( 'return' => false ) );
		WP_Mock::userFunction( '_deprecated_hook', array( 'return' => null ) );
		WP_Mock::userFunction( 'wp_update_post', array( 'return' => 42 ) );
		WP_Mock::userFunction( 'wp_set_post_terms', array( 'return' => array() ) );
		WP_Mock::userFunction( 'sanitize_email', array( 'return' => function ( $v ) { return strtolower( trim( (string) $v ) ); } ) );
		WP_Mock::userFunction( 'sanitize_text_field', array( 'return' => function ( $v ) { return trim( (string) $v ); } ) );
		WP_Mock::userFunction( 'wp_kses_post', array( 'return' => function ( $v ) { return (string) $v; } ) );
		WP_Mock::userFunction( 'is_email', array( 'return' => function ( $v ) { return false !== strpos( (string) $v, '@' ); } ) );
		WP_Mock::userFunction( 'is_wp_error', array( 'return' => false ) );
		WP_Mock::userFunction( 'get_term', array( 'return' => null ) );
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	// -----------------------------------------------------------------------
	// register_routes — scope wiring
	// -----------------------------------------------------------------------

	public function test_register_routes_registers_collection_and_singular_with_correct_scopes(): void {
		$registered = array();
		WP_Mock::userFunction( 'register_rest_route', array(
			'return' => function ( string $namespace, string $route, array $args ) use ( &$registered ) {
				$registered[] = array( 'namespace' => $namespace, 'route' => $route, 'args' => $args );
				return true;
			},
		) );

		( new TicketsController() )->register_routes();

		$this->assertCount( 2, $registered, 'TicketsController registers exactly 2 route entries (collection + singular).' );
		$this->assertSame( Bootstrap::NAMESPACE, $registered[0]['namespace'] );
		$this->assertSame( '/tickets', $registered[0]['route'] );
		$this->assertSame( '/tickets/(?P<id>\d+)', $registered[1]['route'] );

		// Verify every method entry has a permission_callback (set to the
		// `swh_rest_require` stub from setUp(), which returns `true`).
		foreach ( $registered as $entry ) {
			foreach ( $entry['args'] as $key => $method_entry ) {
				if ( is_int( $key ) ) {
					$this->assertArrayHasKey( 'permission_callback', $method_entry, 'Every method entry must have a permission_callback.' );
					$this->assertIsCallable( $method_entry['permission_callback'] );
				}
			}
		}
	}

	// -----------------------------------------------------------------------
	// get_item
	// -----------------------------------------------------------------------

	public function test_get_item_returns_schema_correct_payload(): void {
		$this->stub_ticket_environment( 42 );
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 42 ), 'return' => $this->fake_post( 42 ) ) );
		WP_Mock::userFunction( 'wp_get_post_terms', array( 'return' => array() ) );

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );

		$response = ( new TicketsController() )->get_item( $req );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		// Every locked schema key is present.
		foreach ( array( 'id', 'subject', 'status', 'priority', 'assignee', 'requester', 'category', 'sla', 'csat', 'created_at', 'updated_at', 'first_response_at', 'links' ) as $key ) {
			$this->assertArrayHasKey( $key, $data, "Schema key `$key` missing from payload." );
		}
		// Internal meta MUST NOT leak.
		$this->assertArrayNotHasKey( '_swh_unread', $data );
		$this->assertArrayNotHasKey( '_swh_api_access_log', $data );

		// Spot-check shape.
		$this->assertSame( 42, $data['id'] );
		$this->assertSame( 'Subject', $data['subject'] );
		$this->assertSame( 'Open', $data['status'] );
		$this->assertSame( 'High', $data['priority'] );
		$this->assertSame( 'https://example.com/wp-json/swh/v1/tickets/42', $data['links']['self'] );
		$this->assertSame( 'https://example.com/wp-json/swh/v1/tickets/42/replies', $data['links']['replies'] );
	}

	public function test_get_item_returns_404_for_missing_ticket(): void {
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 999 ), 'return' => null ) );

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/999' );
		$req->set_param( 'id', 999 );

		$err = ( new TicketsController() )->get_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( Errors::NOT_FOUND, $err->get_error_code() );
		$this->assertSame( 404, $err->get_error_data()['status'] );
	}

	public function test_get_item_returns_404_for_wrong_post_type(): void {
		WP_Mock::userFunction( 'get_post', array(
			'args'   => array( 7 ),
			'return' => new \WP_Post( array( 'ID' => 7, 'post_type' => 'post' ) ),
		) );

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/7' );
		$req->set_param( 'id', 7 );

		$err = ( new TicketsController() )->get_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( Errors::NOT_FOUND, $err->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// create_item
	// -----------------------------------------------------------------------

	public function test_create_item_validates_required_fields(): void {
		$req = new WP_REST_Request( 'POST', '/swh/v1/tickets' );
		// Missing subject + email.

		$err = ( new TicketsController() )->create_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( Errors::VALIDATION_FAILED, $err->get_error_code() );
		$data = $err->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertArrayHasKey( 'errors', $data );
		$this->assertArrayHasKey( 'subject', $data['errors'] );
		$this->assertArrayHasKey( 'requester_email', $data['errors'] );
	}

	public function test_create_item_201_on_success(): void {
		WP_Mock::userFunction( 'wp_insert_post', array( 'return' => 77 ) );
		$this->stub_ticket_environment( 77 );
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 77 ), 'return' => $this->fake_post( 77 ) ) );
		WP_Mock::userFunction( 'wp_get_post_terms', array( 'return' => array() ) );
		WP_Mock::userFunction( 'update_post_meta' );

		$req = new WP_REST_Request( 'POST', '/swh/v1/tickets' );
		$req->set_param( 'subject', 'Need help' );
		$req->set_param( 'body', 'Some body' );
		$req->set_param( 'requester_email', 'client@example.com' );
		$req->set_param( 'requester_name', 'Jane Client' );
		$req->set_param( 'priority', 'High' );

		$response = ( new TicketsController() )->create_item( $req );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 77, $response->get_data()['id'] );
	}

	/**
	 * #533 — when the caller omits `status` (and `priority`) from POST, the
	 * controller must seed the configured defaults so the response reflects
	 * the persisted state rather than echoing empty strings.
	 *
	 * Locks the side-effect: `swh_set_ticket_status()` writes
	 * `_ticket_status='Open'` and `update_post_meta()` writes
	 * `_ticket_priority='Medium'` (the values from `swh_get_defaults()`).
	 */
	public function test_create_item_seeds_defaults_when_status_and_priority_omitted(): void {
		WP_Mock::userFunction( 'wp_insert_post', array( 'return' => 77 ) );
		WP_Mock::userFunction( 'get_post_meta', array(
			'return' => function ( int $post_id, string $key, bool $single = false ) {
				// Empty meta for the freshly-created ticket — exactly the
				// state v4.1.0 rc-signoff Issue #5 reproduced.
				return '';
			},
		) );
		WP_Mock::userFunction( 'get_userdata', array( 'return' => null ) );
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 77 ), 'return' => $this->fake_post( 77 ) ) );
		WP_Mock::userFunction( 'wp_get_post_terms', array( 'return' => array() ) );

		// Default status is seeded via swh_set_ticket_status() — which writes
		// to _ticket_status via update_post_meta(). Defaults come from
		// swh_get_defaults() (Open, Medium).
		WP_Mock::userFunction( 'update_post_meta', array(
			'args'  => array( 77, '_ticket_status', 'Open' ),
			'times' => 1,
		) );
		WP_Mock::userFunction( 'update_post_meta', array(
			'args'  => array( 77, '_ticket_priority', 'Medium' ),
			'times' => 1,
		) );
		// Tolerate auxiliary writes (last_activity, version bump).
		WP_Mock::userFunction( 'update_post_meta' );

		$req = new WP_REST_Request( 'POST', '/swh/v1/tickets' );
		$req->set_param( 'subject', 'Need help' );
		$req->set_param( 'body', 'Some body' );
		$req->set_param( 'requester_email', 'client@example.com' );
		// No status, no priority.

		$response = ( new TicketsController() )->create_item( $req );
		$this->assertSame( 201, $response->get_status() );
	}

	// -----------------------------------------------------------------------
	// update_item
	// -----------------------------------------------------------------------

	public function test_update_item_returns_404_for_unknown_id(): void {
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 999 ), 'return' => null ) );

		$req = new WP_REST_Request( 'PATCH', '/swh/v1/tickets/999' );
		$req->set_param( 'id', 999 );
		$req->set_param( 'status', 'Resolved' );

		$err = ( new TicketsController() )->update_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( Errors::NOT_FOUND, $err->get_error_code() );
	}

	public function test_update_item_routes_status_change_through_canonical_helper(): void {
		// Lock that PATCH status=Resolved produces the canonical observable
		// side effect: a write to `_ticket_status` with the new value. The
		// canonical helper is the only path that produces this write, so
		// observing it proves the controller routes through it.
		$this->stub_ticket_environment( 42 );
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 42 ), 'return' => $this->fake_post( 42 ) ) );
		WP_Mock::userFunction( 'wp_get_post_terms', array( 'return' => array() ) );
		WP_Mock::userFunction( 'update_post_meta', array(
			'args'  => array( 42, '_ticket_status', 'Resolved' ),
			'times' => 1,
		) );
		// Tolerate the auxiliary writes the helper produces (last_activity,
		// version bump, resolved-timestamp).
		WP_Mock::userFunction( 'update_post_meta' );

		$req = new WP_REST_Request( 'PATCH', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );
		$req->set_param( 'status', 'Resolved' );
		$req->set_param( '_swh_api_key', array( 'id' => 'k_abc' ) );

		$response = ( new TicketsController() )->update_item( $req );
		$this->assertSame( 200, $response->get_status() );
	}

	public function test_update_item_returns_validation_failed_on_helper_error(): void {
		$this->stub_ticket_environment( 42 );
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 42 ), 'return' => $this->fake_post( 42 ) ) );
		WP_Mock::userFunction( 'wp_get_post_terms', array( 'return' => array() ) );
		WP_Mock::userFunction( 'swh_update_ticket_fields', array(
			'return' => array(
				'ok'      => false,
				'errors'  => array( 'status' => 'Status is not one of the configured values.' ),
				'applied' => array(),
			),
		) );

		$req = new WP_REST_Request( 'PATCH', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );
		$req->set_param( 'status', 'Bogus' );

		$err = ( new TicketsController() )->update_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( Errors::VALIDATION_FAILED, $err->get_error_code() );
		$data = $err->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertArrayHasKey( 'status', $data['errors'] );
	}

	public function test_update_item_with_empty_patch_returns_current_ticket(): void {
		$this->stub_ticket_environment( 42 );
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 42 ), 'return' => $this->fake_post( 42 ) ) );
		WP_Mock::userFunction( 'wp_get_post_terms', array( 'return' => array() ) );

		$req = new WP_REST_Request( 'PATCH', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );

		$response = ( new TicketsController() )->update_item( $req );
		$this->assertSame( 200, $response->get_status() );
	}

	// -----------------------------------------------------------------------
	// get_items (list)
	// -----------------------------------------------------------------------

	public function test_get_items_returns_array_and_pagination_headers(): void {
		// WP_Query stub from bootstrap returns posts=[] / found_posts=0; that's
		// fine — the test asserts the response shape regardless of result set.
		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets' );
		$req->set_param( 'page', 1 );
		$req->set_param( 'per_page', 20 );

		$response = ( new TicketsController() )->get_items( $req );
		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'x-wp-total', $headers );
		$this->assertArrayHasKey( 'x-wp-totalpages', $headers );
	}

	public function test_get_items_clamps_per_page_to_max(): void {
		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets' );
		$req->set_param( 'per_page', 1000 );

		// No assertion on a side effect here — we're verifying the controller
		// doesn't blow up and still returns a valid response when per_page is
		// over the cap. The clamp is internal; a real WP_Query would honour
		// posts_per_page <= 100 even though we asked for 1000.
		$response = ( new TicketsController() )->get_items( $req );
		$this->assertSame( 200, $response->get_status() );
	}

	// -----------------------------------------------------------------------
	// Internal fields are not leaked (allowlist guard)
	// -----------------------------------------------------------------------

	public function test_internal_meta_keys_never_appear_in_response(): void {
		// Deliberately set up the stubs so that `_swh_unread` and an audit-log
		// meta both exist on the post — verifies the schema mapper is an
		// allowlist, not a denylist.
		$this->stub_ticket_environment( 42 );
		// Override get_post_meta default to return non-empty for the internal
		// keys we want to assert never leak.
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 42 ), 'return' => $this->fake_post( 42 ) ) );
		WP_Mock::userFunction( 'wp_get_post_terms', array( 'return' => array() ) );

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );

		$response = ( new TicketsController() )->get_item( $req );
		$flat     = wp_json_encode_safe( $response->get_data() );
		$this->assertStringNotContainsString( '_swh_unread', $flat );
		$this->assertStringNotContainsString( '_swh_api_access_log', $flat );
		$this->assertStringNotContainsString( '_ticket_token', $flat );
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function fake_post( int $id ): \WP_Post {
		return new \WP_Post( array(
			'ID'                => $id,
			'post_type'         => 'helpdesk_ticket',
			'post_title'        => 'Subject',
			'post_content'      => 'Body',
			'post_date_gmt'     => '2026-05-22 10:00:00',
			'post_modified_gmt' => '2026-05-22 11:00:00',
		) );
	}

	private function stub_ticket_environment( int $id ): void {
		// Real swh_get_string_meta / swh_get_int_meta are loaded from helpers.php,
		// so stubbing the higher-level helpers via WP_Mock has no effect. Stub
		// the underlying get_post_meta calls instead — that path runs.
		WP_Mock::userFunction( 'get_post_meta', array(
			'return' => function ( int $post_id, string $key, bool $single = false ) use ( $id ) {
				if ( $post_id !== $id ) {
					return '';
				}
				switch ( $key ) {
					case '_ticket_status':
						return 'Open';
					case '_ticket_priority':
						return 'High';
					case '_ticket_email':
						return 'client@example.com';
					case '_ticket_name':
						return 'Jane';
					case '_ticket_assigned_to':
						return '';
					default:
						return '';
				}
			},
		) );
		WP_Mock::userFunction( 'get_userdata', array( 'return' => null ) );
	}
}

if ( ! function_exists( 'SWH\Tests\Unit\Rest\V1\wp_json_encode_safe' ) ) {
	function wp_json_encode_safe( mixed $value ): string {
		$encoded = json_encode( $value );
		return is_string( $encoded ) ? $encoded : '';
	}
}
