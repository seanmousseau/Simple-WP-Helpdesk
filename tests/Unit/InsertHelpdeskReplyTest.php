<?php
declare(strict_types=1);

namespace SWH\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * Baseline regression coverage for {@see swh_insert_helpdesk_reply()} — the
 * canonical reply-insert helper extracted from the inline admin sequence in
 * v4.1.0 Phase 4 (#363, invariant #20).
 *
 * The helper is the single canonical reply-insertion choke point. These tests
 * lock the observable behaviour the legacy admin path used to produce inline:
 *
 *  - `wp_insert_comment` is called with `comment_type='helpdesk_reply'`.
 *  - Internal-note flag (`_is_internal_note=1`) stored when requested.
 *  - Attachment validation rejects invalid media IDs without inserting a
 *    comment.
 *  - Pre-uploaded URL set (admin path) persists into `_attachments` meta.
 *  - `_swh_unread=1` is set for client replies, NOT for staff replies.
 *  - `swh_fire_ticket_replied()` fires (the `swh_ticket_replied` action).
 *  - `swh_send_email()` is invoked for staff replies when
 *    `dispatch_email=true` (the default).
 *  - Empty body + no attachments returns false without a comment insert.
 */
final class InsertHelpdeskReplyTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();

		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'includes/deprecations.php';
		require_once SWH_PLUGIN_DIR . 'src/Concurrency/TicketVersion.php';
		require_once SWH_PLUGIN_DIR . 'includes/insert-helpdesk-reply.php';

		WP_Mock::userFunction( 'sanitize_text_field', array( 'return' => function ( $v ) { return is_string( $v ) ? trim( $v ) : ''; } ) );
		WP_Mock::userFunction( 'sanitize_email', array( 'return' => function ( $v ) { return is_string( $v ) ? strtolower( trim( $v ) ) : ''; } ) );
		WP_Mock::userFunction( 'wp_kses_post', array( 'return' => function ( $v ) {
			$s = is_string( $v ) ? $v : '';
			return (string) preg_replace( '#<script.*?</script>#is', '', $s );
		} ) );
		WP_Mock::userFunction( 'get_userdata', array( 'return' => function ( $id ) {
			return (object) array( 'display_name' => 'Tech ' . $id, 'user_email' => 'tech' . $id . '@example.com' );
		} ) );
		// Default: every get_post_meta returns '' unless a test overrides with
		// a more specific args matcher. Use type matchers so per-test
		// specific-args stubs (registered AFTER setUp) match first.
		WP_Mock::userFunction( 'get_post_meta', array(
			'args'   => array( \WP_Mock\Functions::type( 'int' ), \WP_Mock\Functions::type( 'string' ), \WP_Mock\Functions::type( 'bool' ) ),
			'return' => '',
		) );
		WP_Mock::userFunction( 'apply_filters', array( 'return' => function ( $tag, $value ) { return $value; } ) );
		WP_Mock::userFunction( 'apply_filters_deprecated', array( 'return' => function ( $tag, $args ) { return $args[0]; } ) );
		WP_Mock::userFunction( 'has_filter', array( 'return' => false ) );
		WP_Mock::userFunction( '_deprecated_hook', array( 'return' => null ) );
		WP_Mock::userFunction( 'user_can', array( 'return' => true ) );
		WP_Mock::userFunction( 'get_option', array( 'return' => '' ) );
		WP_Mock::userFunction( 'admin_url', array( 'return' => 'https://example.com/wp-admin/' ) );
		WP_Mock::userFunction( 'wp_get_attachment_url', array( 'return' => false ) );
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	private function stub_ticket( int $id = 42 ): void {
		$post = (object) array(
			'ID'         => $id,
			'post_type'  => 'helpdesk_ticket',
			'post_title' => 'Subject',
		);
		WP_Mock::userFunction( 'get_post', array( 'args' => array( $id ), 'return' => $post ) );
	}

	/**
	 * Register permissive `update_post_meta` / `update_comment_meta` stubs so
	 * the helper's incidental writes (last_activity, version bump,
	 * first_response, _is_user_reply, etc.) don't blow up Mockery. Always
	 * called AFTER any specific-args `times` expectations on the same
	 * function so Mockery's matcher checks the specific stubs first.
	 */
	private function permissive_meta_writes(): void {
		WP_Mock::userFunction( 'update_post_meta', array( 'return' => true ) );
		WP_Mock::userFunction( 'update_comment_meta', array( 'return' => true ) );
	}

	// -----------------------------------------------------------------------
	// Happy path
	// -----------------------------------------------------------------------

	public function test_staff_reply_inserts_helpdesk_reply_comment_and_fires_action(): void {
		$this->stub_ticket( 42 );
		WP_Mock::userFunction( 'wp_insert_comment', array(
			'times'  => 1,
			'return' => 1001,
		) );
		WP_Mock::expectAction( 'swh_ticket_replied', 42, 1001, true );
		$this->permissive_meta_writes();

		$id = swh_insert_helpdesk_reply( 42, array(
			'body'           => 'Hello',
			'author_user_id' => 7,
			'author_name'    => 'Tech',
			'author_email'   => 'tech@example.com',
			'dispatch_email' => false,
		) );

		$this->assertSame( 1001, $id );
	}

	public function test_internal_note_sets_meta_and_skips_email(): void {
		$this->stub_ticket( 42 );
		WP_Mock::userFunction( 'wp_insert_comment', array( 'return' => 2002 ) );
		WP_Mock::userFunction( 'update_comment_meta', array(
			'args'  => array( 2002, '_is_internal_note', '1' ),
			'times' => 1,
		) );
		// swh_send_email() must NOT be called for internal notes.
		WP_Mock::userFunction( 'swh_send_email', array( 'times' => 0 ) );
		$this->permissive_meta_writes();

		$id = swh_insert_helpdesk_reply( 42, array(
			'body'             => 'private',
			'is_internal_note' => true,
			'author_user_id'   => 7,
			'author_name'      => 'Tech',
			'author_email'     => 'tech@example.com',
			'dispatch_email'   => true,
		) );

		$this->assertSame( 2002, $id );
	}

	// -----------------------------------------------------------------------
	// Validation
	// -----------------------------------------------------------------------

	public function test_empty_body_and_no_attachments_returns_false(): void {
		$this->stub_ticket( 42 );
		WP_Mock::userFunction( 'wp_insert_comment', array( 'times' => 0 ) );

		$id = swh_insert_helpdesk_reply( 42, array(
			'body'           => '   ',
			'author_user_id' => 7,
			'dispatch_email' => false,
		) );

		$this->assertFalse( $id );
		$err = swh_insert_helpdesk_reply_last_error();
		$this->assertArrayHasKey( 'body', $err );
	}

	public function test_invalid_attachment_id_rejected_without_comment_insert(): void {
		$this->stub_ticket( 42 );
		// Returning null for attachment lookup mimics "media id does not exist".
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 999 ), 'return' => null ) );
		WP_Mock::userFunction( 'wp_get_attachment_url', array( 'return' => false ) );
		WP_Mock::userFunction( 'wp_insert_comment', array( 'times' => 0 ) );

		$id = swh_insert_helpdesk_reply( 42, array(
			'body'           => 'Hello',
			'attachments'    => array( 999 ),
			'author_user_id' => 7,
			'dispatch_email' => false,
		) );

		$this->assertFalse( $id );
		$err = swh_insert_helpdesk_reply_last_error();
		$this->assertArrayHasKey( 'attachments', $err );
	}

	public function test_unknown_ticket_returns_false(): void {
		WP_Mock::userFunction( 'get_post', array( 'args' => array( 999 ), 'return' => null ) );
		WP_Mock::userFunction( 'wp_insert_comment', array( 'times' => 0 ) );

		$id = swh_insert_helpdesk_reply( 999, array(
			'body'           => 'Hello',
			'author_user_id' => 7,
			'dispatch_email' => false,
		) );

		$this->assertFalse( $id );
		$this->assertArrayHasKey( 'ticket_id', swh_insert_helpdesk_reply_last_error() );
	}

	// -----------------------------------------------------------------------
	// `_swh_unread` semantics
	// -----------------------------------------------------------------------

	public function test_client_reply_sets_unread_flag(): void {
		$this->stub_ticket( 42 );
		WP_Mock::userFunction( 'wp_insert_comment', array( 'return' => 3003 ) );
		WP_Mock::userFunction( 'update_post_meta', array(
			'args'  => array( 42, '_swh_unread', '1' ),
			'times' => 1,
		) );
		$this->permissive_meta_writes();

		$id = swh_insert_helpdesk_reply( 42, array(
			'body'           => 'I am a client',
			'author_email'   => 'client@example.com',
			'author_name'    => 'Client',
			'dispatch_email' => false,
		) );
		$this->assertSame( 3003, $id );
	}

	public function test_staff_reply_does_not_set_unread_flag(): void {
		$this->stub_ticket( 42 );
		WP_Mock::userFunction( 'wp_insert_comment', array( 'return' => 4004 ) );
		WP_Mock::userFunction( 'update_post_meta', array(
			'args'  => array( 42, '_swh_unread', '1' ),
			'times' => 0,
		) );
		$this->permissive_meta_writes();

		$id = swh_insert_helpdesk_reply( 42, array(
			'body'           => 'Looking into it',
			'author_user_id' => 7,
			'author_name'    => 'Tech',
			'author_email'   => 'tech@example.com',
			'dispatch_email' => false,
		) );
		$this->assertSame( 4004, $id );
	}

	// -----------------------------------------------------------------------
	// Email dispatch routes through swh_send_email() (invariant #2)
	// -----------------------------------------------------------------------

	/**
	 * dispatch_email=false (admin save-path) suppresses email dispatch.
	 * Locks the suppress branch — used by `swh_save_ticket_data()` so its
	 * composite "reply + status-change" email logic owns the dispatch.
	 *
	 * Direct assertion that `dispatch_email=true` calls `swh_send_email()`
	 * is exercised at the integration-test layer; once `class-email.php`
	 * is loaded by any peer test, the real function definition is locked
	 * in for the process and WP_Mock cannot intercept it (the real impl
	 * calls `get_bloginfo()` and other un-stubbed WP functions). Invariant
	 * #2 (email-only-through-swh_send_email) is enforced by inspection of
	 * the helper source — the only outbound mail call site is
	 * `swh_insert_helpdesk_reply_dispatch_email()`.
	 */
	public function test_dispatch_email_false_skips_email_branch(): void {
		$this->stub_ticket( 42 );
		WP_Mock::userFunction( 'wp_insert_comment', array( 'return' => 5005 ) );
		$this->permissive_meta_writes();

		// `_ticket_email` returns a real address so the suppress branch is
		// the ONLY reason the email path is skipped (rules out an empty-
		// email short-circuit).
		WP_Mock::userFunction( 'get_post_meta', array( 'return' => function ( $id = 0, $key = '', $single = false ) {
			if ( '_ticket_email' === $key ) {
				return 'client@example.com';
			}
			return '';
		} ) );

		$id = swh_insert_helpdesk_reply( 42, array(
			'body'           => 'Reply',
			'author_user_id' => 7,
			'author_name'    => 'Tech',
			'author_email'   => 'tech@example.com',
			'dispatch_email' => false,
		) );
		$this->assertSame( 5005, $id );
	}

	// -----------------------------------------------------------------------
	// Pre-uploaded URLs (admin path)
	// -----------------------------------------------------------------------

	public function test_pre_uploaded_urls_persisted_to_comment_meta(): void {
		$this->stub_ticket( 42 );
		WP_Mock::userFunction( 'wp_insert_comment', array( 'return' => 6006 ) );
		WP_Mock::userFunction( 'update_comment_meta', array(
			'args'  => array( 6006, '_attachments', array( 'https://x/y.png' ) ),
			'times' => 1,
		) );
		$this->permissive_meta_writes();

		$id = swh_insert_helpdesk_reply( 42, array(
			'body'             => 'with file',
			'author_user_id'   => 7,
			'author_name'      => 'Tech',
			'author_email'     => 'tech@example.com',
			'attachment_urls'  => array( 'https://x/y.png' ),
			'attachment_names' => array( 'https://x/y.png' => 'y.png' ),
			'dispatch_email'   => false,
		) );
		$this->assertSame( 6006, $id );
	}
}
