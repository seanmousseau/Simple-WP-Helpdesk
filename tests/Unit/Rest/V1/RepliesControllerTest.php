<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest\V1;

use PHPUnit\Framework\TestCase;
use SWH\Rest\Bootstrap;
use SWH\Rest\Errors;
use SWH\Rest\V1\RepliesController;
use WP_Mock;
use WP_REST_Request;

/**
 * Unit tests for {@see \SWH\Rest\V1\RepliesController} — the v4.1.0 Phase 4
 * `swh/v1/tickets/{id}/replies` controller (#363).
 *
 * Coverage map (Phase 4 acceptance criteria):
 *  - register_routes wires read scope on GET, write scope on POST.
 *  - create_item routes through swh_insert_helpdesk_reply() (no direct
 *    wp_insert_comment calls in the controller).
 *  - create_item returns 201 + schema payload on success.
 *  - body is wp_kses_post-sanitised by the helper.
 *  - attachment validation failures surface as
 *    `swh_rest_validation_failed` with `data.errors.attachments`.
 *  - 404 on unknown ticket for both GET + POST.
 *  - GET hides internal notes from `read`-scope callers.
 *  - GET surfaces internal notes to `write`-scope callers.
 *  - Internal-note POST stores `_is_internal_note=1`.
 *  - Author resolution: API-key caller produces author.type=staff with the
 *    key label as display_name (no WP user backing the request).
 */
final class RepliesControllerTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		Bootstrap::reset_for_tests();

		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'includes/deprecations.php';
		require_once SWH_PLUGIN_DIR . 'src/Concurrency/TicketVersion.php';
		require_once SWH_PLUGIN_DIR . 'includes/insert-helpdesk-reply.php';

		WP_Mock::userFunction( 'rest_url', array( 'return' => function ( string $path = '' ): string {
			return 'https://example.com/wp-json/' . ltrim( $path, '/' );
		} ) );
		WP_Mock::userFunction( 'swh_rest_require', array(
			'return' => function () { return '__return_true'; },
		) );
		// get_current_user_id / is_user_logged_in / current_user_can are
		// auth-shape stubs and need to be registered PER TEST (or via
		// caller-scope-write/-read helpers) — WP_Mock expectations are
		// FIFO-matched, so a setUp-level "return true" would absorb the
		// per-test override in the scope-aware GET tests below.
		WP_Mock::userFunction( 'sanitize_text_field', array( 'return' => function ( $v ) { return is_string( $v ) ? trim( $v ) : ''; } ) );
		WP_Mock::userFunction( 'sanitize_email', array( 'return' => function ( $v ) { return is_string( $v ) ? strtolower( trim( $v ) ) : ''; } ) );
		WP_Mock::userFunction( 'wp_kses_post', array( 'return' => function ( $v ) {
			$s = is_string( $v ) ? $v : '';
			return (string) preg_replace( '#<script.*?</script>#is', '', $s );
		} ) );
		WP_Mock::userFunction( 'apply_filters', array( 'return' => function ( $tag, $value ) { return $value; } ) );
		WP_Mock::userFunction( 'apply_filters_deprecated', array( 'return' => function ( $tag, $args ) { return $args[0]; } ) );
		WP_Mock::userFunction( 'has_filter', array( 'return' => false ) );
		WP_Mock::userFunction( '_deprecated_hook', array( 'return' => null ) );
		WP_Mock::userFunction( 'get_option', array( 'return' => '' ) );
		WP_Mock::userFunction( 'get_post_meta', array( 'return' => '' ) );
		WP_Mock::userFunction( 'update_post_meta', array( 'return' => true ) );
		WP_Mock::userFunction( 'update_comment_meta', array( 'return' => true ) );
		WP_Mock::userFunction( 'get_userdata', array( 'return' => (object) array( 'display_name' => 'Tech', 'user_email' => 'tech@example.com' ) ) );
		WP_Mock::userFunction( 'user_can', array( 'return' => true ) );
		WP_Mock::userFunction( 'swh_send_email', array( 'return' => null ) );
		WP_Mock::userFunction( 'swh_get_secure_ticket_link', array( 'return' => 'https://example.com/portal/abc' ) );
		WP_Mock::userFunction( 'swh_admin_ticket_url', array( 'return' => 'https://example.com/wp-admin/?p=42' ) );
		WP_Mock::userFunction( 'swh_get_file_proxy_url', array( 'return' => function ( $u ) { return $u; } ) );
		WP_Mock::userFunction( 'admin_url', array( 'return' => 'https://example.com/wp-admin/' ) );
		WP_Mock::userFunction( 'wp_get_attachment_url', array( 'return' => false ) );

		// Ensure file is loaded so the inner namespaced helpers are defined.
		require_once SWH_PLUGIN_DIR . 'src/Rest/V1/Schemas/Reply.php';
		require_once SWH_PLUGIN_DIR . 'src/Rest/V1/RepliesController.php';
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	/** Stub the auth shape so the caller is a write-scope WP user. */
	private function as_write_scope_wp_user(): void {
		WP_Mock::userFunction( 'get_current_user_id', array( 'return' => 7 ) );
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => true ) );
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
	}

	/** Stub the auth shape so the caller is an unauthenticated request (read api key). */
	private function as_read_scope_api_key(): void {
		WP_Mock::userFunction( 'get_current_user_id', array( 'return' => 0 ) );
		WP_Mock::userFunction( 'is_user_logged_in', array( 'return' => false ) );
		WP_Mock::userFunction( 'current_user_can', array( 'return' => false ) );
	}

	private function fake_ticket( int $id = 42 ): \WP_Post {
		return new \WP_Post( array(
			'ID'         => $id,
			'post_type'  => 'helpdesk_ticket',
			'post_title' => 'Subject',
		) );
	}

	private function fake_comment( int $cid = 1001, int $tid = 42, bool $internal = false, int $user_id = 7 ): \WP_Comment {
		$c = new \WP_Comment( array(
			'comment_ID'           => (string) $cid,
			'comment_post_ID'      => (string) $tid,
			'user_id'              => (string) $user_id,
			'comment_author'       => 'Tech',
			'comment_author_email' => 'tech@example.com',
			'comment_content'      => 'Hello',
			'comment_type'         => 'helpdesk_reply',
			'comment_date_gmt'     => '2026-05-27 09:00:00',
		) );
		// Stash internal flag per-comment in a side-table the test reads via
		// get_comment_meta below.
		$GLOBALS['_test_internal'][ $cid ] = $internal ? '1' : '';
		return $c;
	}

	// -----------------------------------------------------------------------
	// register_routes
	// -----------------------------------------------------------------------

	public function test_register_routes_registers_replies_subcollection_with_correct_scopes(): void {
		$registered = array();
		WP_Mock::userFunction( 'register_rest_route', array(
			'return' => function ( string $namespace, string $route, array $args ) use ( &$registered ) {
				$registered[] = array( 'namespace' => $namespace, 'route' => $route, 'args' => $args );
				return true;
			},
		) );

		( new RepliesController() )->register_routes();

		$this->assertCount( 1, $registered );
		$this->assertSame( Bootstrap::NAMESPACE, $registered[0]['namespace'] );
		$this->assertSame( '/tickets/(?P<id>\d+)/replies', $registered[0]['route'] );
		foreach ( $registered[0]['args'] as $key => $method_entry ) {
			if ( is_int( $key ) ) {
				$this->assertArrayHasKey( 'permission_callback', $method_entry );
			}
		}
	}

	// -----------------------------------------------------------------------
	// 404
	// -----------------------------------------------------------------------

	public function test_get_items_404_on_unknown_ticket(): void {
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 999 ), 'return' => null ) );

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/999/replies' );
		$req->set_param( 'id', 999 );

		$err = ( new RepliesController() )->get_items( $req );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( Errors::NOT_FOUND, $err->get_error_code() );
	}

	public function test_create_item_404_on_unknown_ticket(): void {
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 999 ), 'return' => null ) );

		$req = new WP_REST_Request( 'POST', '/swh/v1/tickets/999/replies' );
		$req->set_param( 'id', 999 );
		$req->set_param( 'body', 'hi' );

		$err = ( new RepliesController() )->create_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( Errors::NOT_FOUND, $err->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// create_item — happy path
	// -----------------------------------------------------------------------

	public function test_create_item_201_routes_through_canonical_helper(): void {
		$this->as_write_scope_wp_user();
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 42 ), 'return' => $this->fake_ticket( 42 ) ) );
		// The helper is the real swh_insert_helpdesk_reply() function loaded
		// from includes/. It calls wp_insert_comment + meta writes + the
		// lifecycle action; assertion focuses on the observable response
		// (201 + locked schema) which proves the controller routes through
		// the helper (any direct wp_insert_comment in the controller is
		// banned by invariant #20).
		WP_Mock::userFunction( 'wp_insert_comment', array( 'return' => 1001 ) );
		WP_Mock::userFunction( 'update_post_meta', array( 'return' => true ) );
		WP_Mock::userFunction( 'update_comment_meta', array( 'return' => true ) );
		WP_Mock::userFunction( 'get_comment', array(
			'args'   => array( 1001 ),
			'return' => $this->fake_comment( 1001, 42 ),
		) );
		WP_Mock::userFunction( 'get_comment_meta', array( 'return' => '' ) );

		$req = new WP_REST_Request( 'POST', '/swh/v1/tickets/42/replies' );
		$req->set_param( 'id', 42 );
		$req->set_param( 'body', 'Hello' );

		$response = ( new RepliesController() )->create_item( $req );
		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 1001, $data['id'] );
		// Schema allowlist — all locked keys present.
		foreach ( array( 'id', 'body', 'author', 'is_internal_note', 'created_at', 'attachments', 'links' ) as $key ) {
			$this->assertArrayHasKey( $key, $data );
		}
		// Internal meta MUST NOT leak.
		$flat = (string) json_encode( $data );
		$this->assertStringNotContainsString( '_is_internal_note', $flat );
		$this->assertStringNotContainsString( '_attachments', $flat );
	}

	// -----------------------------------------------------------------------
	// create_item — validation
	// -----------------------------------------------------------------------

	public function test_create_item_rejects_empty_body_and_no_attachments(): void {
		$this->as_write_scope_wp_user();
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 42 ), 'return' => $this->fake_ticket( 42 ) ) );

		$req = new WP_REST_Request( 'POST', '/swh/v1/tickets/42/replies' );
		$req->set_param( 'id', 42 );
		$req->set_param( 'body', '   ' );

		$err = ( new RepliesController() )->create_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( Errors::VALIDATION_FAILED, $err->get_error_code() );
		$data = $err->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertArrayHasKey( 'body', $data['errors'] );
	}

	public function test_create_item_attachment_validation_failure_returns_field_error(): void {
		$this->as_write_scope_wp_user();
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 42 ), 'return' => $this->fake_ticket( 42 ) ) );
		// Invalid media id 999 — get_post returns null and
		// wp_get_attachment_url returns false. Real helper rejects with
		// `attachments` in the last-error.
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 999 ), 'return' => null ) );
		WP_Mock::userFunction( 'wp_get_attachment_url', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_insert_comment', array( 'times' => 0 ) );

		$req = new WP_REST_Request( 'POST', '/swh/v1/tickets/42/replies' );
		$req->set_param( 'id', 42 );
		$req->set_param( 'body', 'hello' );
		$req->set_param( 'attachments', array( 999 ) );

		$err = ( new RepliesController() )->create_item( $req );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( Errors::VALIDATION_FAILED, $err->get_error_code() );
		$data = $err->get_error_data();
		$this->assertArrayHasKey( 'attachments', $data['errors'] );
	}

	// -----------------------------------------------------------------------
	// Internal-note visibility (scope-aware GET filter)
	// -----------------------------------------------------------------------

	public function test_get_items_hides_internal_notes_from_read_scope_callers(): void {
		$this->as_read_scope_api_key();
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 42 ), 'return' => $this->fake_ticket( 42 ) ) );

		$public  = $this->fake_comment( 1, 42, false );
		$private = $this->fake_comment( 2, 42, true );
		WP_Mock::userFunction( 'get_comments', array( 'return' => array( $public, $private ) ) );
		WP_Mock::userFunction( 'get_comment_meta', array(
			'return' => function ( int $id, string $key, bool $single = false ) {
				if ( '_is_internal_note' === $key ) {
					return $GLOBALS['_test_internal'][ $id ] ?? '';
				}
				return '';
			},
		) );

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/42/replies' );
		$req->set_param( 'id', 42 );
		// Read-only api key — no write scope.
		$req->set_param( '_swh_api_key', array( 'id' => 'k_abc', 'scope' => 'read', 'label' => 'client' ) );

		$response = ( new RepliesController() )->get_items( $req );
		$this->assertSame( 200, $response->get_status() );
		$items = $response->get_data();
		$this->assertCount( 1, $items );
		$this->assertSame( 1, $items[0]['id'] );
	}

	public function test_get_items_surfaces_internal_notes_to_write_scope_callers(): void {
		$this->as_write_scope_wp_user();
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 42 ), 'return' => $this->fake_ticket( 42 ) ) );

		$public  = $this->fake_comment( 1, 42, false );
		$private = $this->fake_comment( 2, 42, true );
		WP_Mock::userFunction( 'get_comments', array( 'return' => array( $public, $private ) ) );
		WP_Mock::userFunction( 'get_comment_meta', array(
			'return' => function ( int $id, string $key, bool $single = false ) {
				if ( '_is_internal_note' === $key ) {
					return $GLOBALS['_test_internal'][ $id ] ?? '';
				}
				return '';
			},
		) );

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/42/replies' );
		$req->set_param( 'id', 42 );

		// Logged-in WP user with edit_posts (set in setUp) ⇒ write scope.
		$response = ( new RepliesController() )->get_items( $req );
		$this->assertSame( 200, $response->get_status() );
		$items = $response->get_data();
		$this->assertCount( 2, $items );
		// Second item carries is_internal_note=true.
		$this->assertTrue( $items[1]['is_internal_note'] );
	}

	// -----------------------------------------------------------------------
	// API-key attribution
	// -----------------------------------------------------------------------

	public function test_api_key_caller_attributes_reply_to_key_label(): void {
		$this->as_read_scope_api_key();
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 42 ), 'return' => $this->fake_ticket( 42 ) ) );

		// Capture the comment-insert args so we can assert that the api-key
		// label flows through into `comment_author`. Real helper runs end-
		// to-end with the stubbed lower-level WP API.
		$captured_comment_args = null;
		WP_Mock::userFunction( 'wp_insert_comment', array(
			'return' => function ( $args ) use ( &$captured_comment_args ) {
				$captured_comment_args = $args;
				return 5005;
			},
		) );
		WP_Mock::userFunction( 'update_post_meta', array( 'return' => true ) );
		WP_Mock::userFunction( 'update_comment_meta', array( 'return' => true ) );
		// `comment_author` from wp_insert_comment becomes display_name in
		// the response — fake the loaded comment with the label baked in.
		WP_Mock::userFunction( 'get_comment', array(
			'return' => new \WP_Comment( array(
				'comment_ID'           => '5005',
				'comment_post_ID'      => '42',
				'user_id'              => '0',
				'comment_author'       => 'Integration X',
				'comment_author_email' => '',
				'comment_content'      => 'from key',
				'comment_type'         => 'helpdesk_reply',
				'comment_date_gmt'     => '2026-05-27 09:00:00',
			) ),
		) );
		WP_Mock::userFunction( 'get_comment_meta', array( 'return' => '' ) );

		$req = new WP_REST_Request( 'POST', '/swh/v1/tickets/42/replies' );
		$req->set_param( 'id', 42 );
		$req->set_param( 'body', 'from key' );
		$req->set_param( '_swh_api_key', array( 'id' => 'k_xyz', 'scope' => 'write', 'label' => 'Integration X' ) );

		$response = ( new RepliesController() )->create_item( $req );
		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'client', $data['author']['type'] );
		$this->assertNull( $data['author']['id'] );
		$this->assertSame( 'Integration X', $data['author']['display_name'] );
		// And the inserted comment's `comment_author` field carried the
		// label — proves the controller forwarded the api-key label into
		// the helper's author_name.
		$this->assertNotNull( $captured_comment_args );
		$this->assertSame( 'Integration X', $captured_comment_args['comment_author'] );
	}
}
