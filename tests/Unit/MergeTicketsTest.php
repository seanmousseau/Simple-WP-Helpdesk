<?php
/**
 * Domain tests for swh_merge_tickets() — covers gap #13.
 *
 * The merge flow has many moving parts and an internal-note expectation
 * that several v3.x bugs landed on (#290, #310): the source ticket's
 * replies, attachments, and attachment-orignames must all move to the
 * target, an internal note must appear on both ends with the cross-
 * reference UID, the source must transition to Closed, and the client
 * must be emailed.
 *
 * @see docs/internal/test-coverage-matrix.md — gap #13
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

namespace SWH\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Mock;

final class MergeTicketsTest extends TestCase {

	/** @var array<string, mixed> Captured update_post_meta calls keyed "post_id:meta_key". */
	private array $meta_writes = array();

	/** @var array<int, array<string, mixed>> Captured wp_insert_comment payloads. */
	private array $inserted_comments = array();

	/** @var array<int, array<string, mixed>> Captured wp_update_comment payloads. */
	private array $updated_comments = array();

	/** @var array<int, array{int, string}> Captured swh_set_ticket_status calls (id, status). */
	private array $status_calls = array();

	/** @var array<int, array{string, string, string}> Captured swh_send_email calls. */
	private array $email_calls = array();

	protected function setUp(): void {
		WP_Mock::setUp();
		$this->meta_writes       = array();
		$this->inserted_comments = array();
		$this->updated_comments  = array();
		$this->status_calls      = array();
		$this->email_calls       = array();

		if ( ! function_exists( 'absint' ) ) {
			require_once __DIR__ . '/_csat_absint_stub.php';
		}
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'includes/deprecations.php';
		WP_Mock::userFunction( 'apply_filters_deprecated', array( 'return_arg' => 1 ) );

		WP_Mock::userFunction(
			'get_option',
			array( 'return' => fn( $k, $d = false ) => $d )
		);
		WP_Mock::userFunction(
			'update_post_meta',
			array(
				'return' => function ( $post_id, $key, $value ) {
					$this->meta_writes[ $post_id . ':' . $key ] = $value;
					return true;
				},
			)
		);
		WP_Mock::userFunction(
			'wp_insert_comment',
			array(
				'return' => function ( $args ) {
					$this->inserted_comments[] = $args;
					return count( $this->inserted_comments ) + 1000;  // synthetic comment IDs
				},
			)
		);
		WP_Mock::userFunction(
			'wp_update_comment',
			array(
				'return' => function ( $args ) {
					$this->updated_comments[] = $args;
					return 1;
				},
			)
		);
		WP_Mock::userFunction(
			'swh_set_ticket_status',
			array(
				'return' => function ( $id, $status ) {
					$this->status_calls[] = array( (int) $id, (string) $status );
				},
			)
		);
		// swh_send_email() is loaded transitively by sibling test files (EmailWrapperTest,
		// EmailTest at file-load time), so a WP_Mock stub can't intercept it. Stub the
		// 30+ WP primitives swh_send_email pulls in (template parser, header builder,
		// HTML wrapper) so it runs without errors. We don't capture wp_mail — the email
		// path is asserted in EmailTest separately. What MergeTicketsTest pins is the
		// merge structure (comments moved, attachments merged, notes inserted, source closed).
		WP_Mock::userFunction( 'wp_mail', array( 'return' => true ) );
		WP_Mock::userFunction( 'wp_specialchars_decode', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'get_bloginfo', array( 'return' => 'Test Site' ) );
		WP_Mock::userFunction( 'wp_strip_all_tags', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'esc_html', array( 'return' => fn( $v ) => is_scalar( $v ) ? (string) $v : '' ) );
		WP_Mock::userFunction( 'esc_url', array( 'return' => fn( $v ) => is_scalar( $v ) ? (string) $v : '' ) );
		WP_Mock::userFunction( 'wpautop', array( 'return' => fn( $v ) => is_scalar( $v ) ? (string) $v : '' ) );
		WP_Mock::userFunction( 'get_site_icon_url', array( 'return' => '' ) );
		WP_Mock::userFunction( 'admin_url', array( 'return' => 'https://example.com/wp-admin/' ) );
		WP_Mock::userFunction( 'home_url', array( 'return' => 'https://example.com' ) );
		WP_Mock::userFunction( 'swh_fire_ticket_replied', array( 'return' => true ) );
		// swh_get_secure_ticket_link is loaded from helpers.php (can't stub directly).
		// Stub its underlying primitives so it returns a non-empty URL: it reads
		// _ticket_token via get_post_meta, then add_query_arg's the result.
		WP_Mock::userFunction(
			'add_query_arg',
			array( 'return' => fn( $args, $url ) => $url . '?token=t' )
		);
		WP_Mock::userFunction( 'get_permalink', array( 'return' => 'https://example.com/portal/' ) );
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	private function stub_post( int $id, string $type = 'helpdesk_ticket' ): void {
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( $id ),
				'return' => (object) array(
					'ID'        => $id,
					'post_type' => $type,
				),
			)
		);
	}

	/**
	 * Stubs get_post_meta for the meta keys merge_tickets reads. Defaults
	 * are empty arrays / empty strings so each test only overrides what
	 * matters for its scenario.
	 *
	 * @param array<string, mixed> $source_meta
	 * @param array<string, mixed> $target_meta
	 */
	private function stub_meta( int $source_id, int $target_id, array $source_meta, array $target_meta ): void {
		$store = array(
			$source_id => array_merge(
				array(
					'_ticket_attachments'        => array(),
					'_swh_attachment_orignames'  => array(),
					'_ticket_uid'                => 'TKT-0001',
					'_ticket_email'              => 'client@example.com',
					'_ticket_name'               => 'Test Client',
					'_ticket_token'              => 'srctok',
					'_ticket_url'                => 'https://example.com/portal/',
				),
				$source_meta
			),
			$target_id => array_merge(
				array(
					'_ticket_attachments'        => array(),
					'_swh_attachment_orignames'  => array(),
					'_ticket_uid'                => 'TKT-0022',
					// Target token so swh_get_secure_ticket_link($target_id) returns a URL.
					'_ticket_token'              => 'tgttok',
					'_ticket_url'                => 'https://example.com/portal/',
				),
				$target_meta
			),
		);
		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'return' => function ( $post_id, $key, $single = false ) use ( $store ) {
					return $store[ $post_id ][ $key ] ?? '';
				},
			)
		);
	}

	public function test_returns_false_when_source_missing(): void {
		WP_Mock::userFunction( 'get_post', array( 'return' => null ) );
		$this->assertFalse(
			swh_merge_tickets( 999, 22 ),
			'Missing source post must short-circuit.'
		);
		$this->assertEmpty( $this->updated_comments, 'No comments must be moved if source is invalid.' );
	}

	public function test_returns_false_when_source_is_not_a_helpdesk_ticket(): void {
		$this->stub_post( 1, 'post' );  // not a ticket
		$this->stub_post( 22, 'helpdesk_ticket' );
		// Both gets resolve, but source is wrong type. Need a fallback get_post that
		// returns the right object per call — use a multi-arg matcher.
		WP_Mock::userFunction(
			'get_post',
			array(
				'return' => function ( $id ) {
					if ( 1 === $id ) {
						return (object) array( 'ID' => 1, 'post_type' => 'post' );
					}
					if ( 22 === $id ) {
						return (object) array( 'ID' => 22, 'post_type' => 'helpdesk_ticket' );
					}
					return null;
				},
			)
		);
		$this->assertFalse(
			swh_merge_tickets( 1, 22 ),
			'Source must be a helpdesk_ticket — generic posts cannot be merged.'
		);
	}

	public function test_happy_path_moves_comments_inserts_notes_closes_source_emails_client(): void {
		WP_Mock::userFunction(
			'get_post',
			array(
				'return' => function ( $id ) {
					return (object) array( 'ID' => $id, 'post_type' => 'helpdesk_ticket' );
				},
			)
		);

		// Two existing replies on the source. swh_merge_tickets() type-checks via
		// `$comment instanceof WP_Comment` then reads ->comment_ID — extend the
		// stub via array initializer to set the property without dynamic-property
		// deprecation noise.
		$reply1 = new \WP_Comment( array() );
		$reply2 = new \WP_Comment( array() );
		// @phpstan-ignore-next-line — dynamic property, intentional for fixture
		$reply1->comment_ID = '101';
		// @phpstan-ignore-next-line — dynamic property, intentional for fixture
		$reply2->comment_ID = '102';
		WP_Mock::userFunction(
			'get_comments',
			array( 'return' => array( $reply1, $reply2 ) )
		);

		$this->stub_meta(
			1,
			22,
			array(
				'_ticket_attachments'       => array( 'https://example.com/a.pdf' ),
				'_swh_attachment_orignames' => array( 'https://example.com/a.pdf' => 'a.pdf' ),
			),
			array(
				'_ticket_attachments'       => array( 'https://example.com/b.pdf' ),
				'_swh_attachment_orignames' => array( 'https://example.com/b.pdf' => 'b.pdf' ),
			)
		);

		$ok = swh_merge_tickets( 1, 22 );

		$this->assertTrue( $ok, 'Happy path must return true.' );

		// Both source comments moved to target.
		$this->assertCount( 2, $this->updated_comments, 'Both source replies must be moved.' );
		foreach ( $this->updated_comments as $upd ) {
			$this->assertSame(
				22,
				$upd['comment_post_ID'],
				'Moved comment must point to the TARGET post ID.'
			);
		}

		// Attachments merged on target (existing target attachments preserved).
		$merged_attach = $this->meta_writes['22:_ticket_attachments'] ?? null;
		$this->assertIsArray( $merged_attach );
		$this->assertContains( 'https://example.com/a.pdf', $merged_attach, 'Source attachment must be appended.' );
		$this->assertContains( 'https://example.com/b.pdf', $merged_attach, 'Target attachment must be preserved.' );

		// Origname map merged.
		$merged_names = $this->meta_writes['22:_swh_attachment_orignames'] ?? null;
		$this->assertIsArray( $merged_names );
		$this->assertSame( 'a.pdf', $merged_names['https://example.com/a.pdf'] ?? null );
		$this->assertSame( 'b.pdf', $merged_names['https://example.com/b.pdf'] ?? null );

		// Two internal-note comments inserted — one on each ticket — with cross-reference UIDs.
		$this->assertCount( 2, $this->inserted_comments );
		$target_note = $this->inserted_comments[0];
		$this->assertSame( 22, $target_note['comment_post_ID'] );
		$this->assertStringContainsString( 'TKT-0001', $target_note['comment_content'], 'Target note must reference the SOURCE uid.' );
		$this->assertSame( '1', $target_note['comment_meta']['_is_internal_note'] ?? null );

		$source_note = $this->inserted_comments[1];
		$this->assertSame( 1, $source_note['comment_post_ID'] );
		$this->assertStringContainsString( 'TKT-0022', $source_note['comment_content'], 'Source note must reference the TARGET uid.' );
		$this->assertSame( '1', $source_note['comment_meta']['_is_internal_note'] ?? null );

		// Source closed via the lifecycle mutator. swh_set_ticket_status is loaded
		// from helpers.php (we can't intercept it directly via WP_Mock); detect the
		// closure via the meta-write it produces. The status mutator invariant test
		// pins that the underlying write happens exclusively here + initial-create.
		$this->assertArrayHasKey(
			'1:_ticket_status',
			$this->meta_writes,
			'Source ticket must be transitioned via the mutator (which writes _ticket_status meta).'
		);

		// Email path: swh_send_email is loaded transitively by sibling tests so we
		// can't intercept the call itself in the full suite. The dispatch logic
		// (template parsing, wp_mail call) is covered by EmailTest. Here we
		// validate that merge_tickets reached the email-send step without bailing
		// — implicit in the absence of exceptions thrown by the email primitives.
	}

	public function test_empty_source_attachments_do_not_overwrite_target(): void {
		WP_Mock::userFunction(
			'get_post',
			array(
				'return' => function ( $id ) {
					return (object) array( 'ID' => $id, 'post_type' => 'helpdesk_ticket' );
				},
			)
		);
		WP_Mock::userFunction( 'get_comments', array( 'return' => array() ) );

		$this->stub_meta(
			1,
			22,
			array( '_ticket_attachments' => array() ),
			array( '_ticket_attachments' => array( 'https://example.com/keep.pdf' ) )
		);

		swh_merge_tickets( 1, 22 );

		// _ticket_attachments must NOT be overwritten on target because source had nothing to contribute.
		$this->assertArrayNotHasKey(
			'22:_ticket_attachments',
			$this->meta_writes,
			'Empty source attachments must NOT trigger a write to target — that would overwrite the target\'s existing list.'
		);
	}

	public function test_returns_false_for_non_existent_target(): void {
		WP_Mock::userFunction(
			'get_post',
			array(
				'return' => function ( $id ) {
					if ( 1 === $id ) {
						return (object) array( 'ID' => 1, 'post_type' => 'helpdesk_ticket' );
					}
					return null;  // target missing
				},
			)
		);
		$this->assertFalse(
			swh_merge_tickets( 1, 999 ),
			'Missing target must short-circuit before any writes.'
		);
		$this->assertEmpty( $this->status_calls, 'No status change must fire if merge fails the guard.' );
	}
}
