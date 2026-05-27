<?php
declare(strict_types=1);

namespace SWH\Rest\V1;

use SWH\Admin\ApiAccessLog;
use SWH\Rest\Bootstrap;
use SWH\Rest\Errors;
use SWH\Rest\V1\Schemas\Reply as ReplySchema;
use WP_Comment;
use WP_Post;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function current_user_can;
use function get_comment;
use function get_comment_meta;
use function get_comments;
use function get_current_user_id;
use function get_post;
use function is_user_logged_in;
use function register_rest_route;
use function swh_insert_helpdesk_reply;
use function swh_insert_helpdesk_reply_last_error;
use function swh_rest_require;

/**
 * Public REST controller for `swh/v1/tickets/{id}/replies`.
 *
 * Phase 4 of the v4.1.0 plan (#363). Implements list + create over the
 * `helpdesk_reply` comments attached to a `helpdesk_ticket` post, with the
 * locked schema from {@see ReplySchema}. Every route is gated by
 * {@see swh_rest_require()}; insertions route through
 * {@see swh_insert_helpdesk_reply()} (invariant #20, design-document.md).
 *
 * Routes:
 *
 *  - GET  /swh/v1/tickets/{id}/replies — list replies on a ticket.
 *  - POST /swh/v1/tickets/{id}/replies — append a reply (or internal note).
 *
 * **Internal-note visibility.** Internal notes carry `_is_internal_note=1`
 * on the comment meta. The `GET` handler hides them from `read`-scope
 * callers (clients) and surfaces them to `write`-scope callers (staff /
 * trusted integrations). Note: today the v4.1.0 surface has only `read`
 * and `write` scopes — there is no separate "client" scope as #363
 * originally described.
 *
 * **Helper-only writes.** The controller never calls `wp_insert_comment`
 * directly. Every insertion goes through {@see swh_insert_helpdesk_reply()}
 * which owns body sanitisation (`wp_kses_post`), attachment validation,
 * `_swh_unread` semantics, the `swh_ticket_replied` lifecycle action, and
 * email dispatch via `swh_send_email()` (invariant #2).
 *
 * @since 4.1.0
 */
final class RepliesController extends WP_REST_Controller {

	public const REST_BASE = 'tickets';

	public function __construct() {
		$this->namespace = Bootstrap::NAMESPACE;
		$this->rest_base = self::REST_BASE;
	}

	/**
	 * Register `/tickets/{id}/replies` routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/replies',
			array(
				'args'   => array(
					'id' => array(
						'description' => 'Ticket ID.',
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => swh_rest_require( 'read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => swh_rest_require( 'write' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Locked item schema — single source of truth via {@see ReplySchema::schema()}.
	 *
	 * @return array<string,mixed>
	 */
	public function get_item_schema(): array {
		if ( null !== $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$this->schema = ReplySchema::schema();
		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * GET /swh/v1/tickets/{id}/replies.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function get_items( $request ) {
		$id_raw = $request->get_param( 'id' );
		$id     = is_numeric( $id_raw ) ? (int) $id_raw : 0;
		$post   = $this->resolve_ticket( $id );
		if ( null === $post ) {
			return Errors::respond( Errors::NOT_FOUND, 404, __( 'Ticket not found.', 'simple-wp-helpdesk' ) );
		}

		$comments = get_comments(
			array(
				'post_id' => $id,
				'type'    => 'helpdesk_reply',
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
			)
		);

		$can_see_internal = $this->caller_has_write_scope( $request );
		$items            = array();
		foreach ( (array) $comments as $c ) {
			if ( ! $c instanceof WP_Comment ) {
				continue;
			}
			if ( ! $can_see_internal ) {
				$flag = self::comment_meta_value( (int) $c->comment_ID, '_is_internal_note' );
				if ( '1' === $flag ) {
					continue;
				}
			}
			$items[] = ReplySchema::from_comment( $c );
		}

		$response = ResponseDecorator::with_rate_limit_headers(
			new WP_REST_Response( $items, 200 ),
			$request
		);
		$this->record_access( $id, 'read', $request );
		return $response;
	}

	/**
	 * POST /swh/v1/tickets/{id}/replies — insert reply via canonical helper.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function create_item( $request ) {
		$id_raw = $request->get_param( 'id' );
		$id     = is_numeric( $id_raw ) ? (int) $id_raw : 0;
		$post   = $this->resolve_ticket( $id );
		if ( null === $post ) {
			return Errors::respond( Errors::NOT_FOUND, 404, __( 'Ticket not found.', 'simple-wp-helpdesk' ) );
		}

		$body_raw       = $request->get_param( 'body' );
		$is_internal    = (bool) $request->get_param( 'is_internal_note' );
		$attachments    = $request->get_param( 'attachments' );
		$attachment_ids = array();
		if ( is_array( $attachments ) ) {
			foreach ( $attachments as $att ) {
				if ( is_numeric( $att ) ) {
					$attachment_ids[] = (int) $att;
				}
			}
		}

		$body = is_string( $body_raw ) ? $body_raw : '';
		if ( '' === trim( $body ) && empty( $attachment_ids ) ) {
			return $this->validation_error( array( 'body' => __( 'A reply body or at least one attachment is required.', 'simple-wp-helpdesk' ) ) );
		}

		// Author resolution — WP user when logged in, API-key label otherwise.
		$author_user_id = is_user_logged_in() ? (int) get_current_user_id() : 0;
		$author_email   = '';
		$author_name    = '';
		if ( 0 === $author_user_id ) {
			$api_key = $request->get_param( '_swh_api_key' );
			if ( is_array( $api_key ) ) {
				$author_name = isset( $api_key['label'] ) && is_string( $api_key['label'] )
					? $api_key['label']
					: ( isset( $api_key['id'] ) && is_string( $api_key['id'] ) ? $api_key['id'] : '' );
			}
		}

		$payload = array(
			'body'             => $body,
			'is_internal_note' => $is_internal,
			'attachments'      => $attachment_ids,
			'author_user_id'   => $author_user_id,
			'author_email'     => $author_email,
			'author_name'      => $author_name,
			'dispatch_email'   => true,
		);

		$comment_id = swh_insert_helpdesk_reply( $id, $payload, $this->context_for( $request ) );
		if ( false === $comment_id || 0 === $comment_id ) {
			$last = swh_insert_helpdesk_reply_last_error();
			// Attachment validation failures are field-level; surface them with
			// data.errors.attachments so callers can pinpoint the bad ID.
			if ( isset( $last['attachments'] ) ) {
				return $this->validation_error( array( 'attachments' => __( 'One or more attachment IDs are invalid.', 'simple-wp-helpdesk' ) ) );
			}
			$messages = array();
			foreach ( $last as $field => $v ) {
				$messages[ (string) $field ] = is_string( $v ) ? $v : __( 'Invalid value.', 'simple-wp-helpdesk' );
			}
			if ( empty( $messages ) ) {
				$messages = array( 'reply' => __( 'Failed to insert reply.', 'simple-wp-helpdesk' ) );
			}
			return $this->validation_error( $messages );
		}

		$comment = self::resolve_comment( (int) $comment_id );
		if ( ! $comment instanceof WP_Comment ) {
			return Errors::respond( Errors::NOT_FOUND, 404, __( 'Reply not found after create.', 'simple-wp-helpdesk' ) );
		}
		$response = ResponseDecorator::with_rate_limit_headers(
			new WP_REST_Response( ReplySchema::from_comment( $comment ), 201 ),
			$request
		);
		$this->record_access( $id, 'write', $request );
		return $response;
	}

	/**
	 * Fire-and-forget audit-log write. No-op when the toggle is off. Called
	 * AFTER the response has been built on every success path of the per-ticket
	 * replies endpoint. Both list-replies and create-reply are
	 * single-ticket-scoped, so both audit.
	 *
	 * @param int             $ticket_id Resolved ticket post id.
	 * @param string          $action    'read' or 'write'.
	 * @param WP_REST_Request $request   Current request (for key attribution + route).
	 */
	private function record_access( int $ticket_id, string $action, WP_REST_Request $request ): void {
		$api_key = $request->get_param( '_swh_api_key' );
		ApiAccessLog::record(
			$ticket_id,
			$action,
			(string) $request->get_route(),
			is_array( $api_key ) ? $api_key : null
		);
	}

	/**
	 * Return true when the caller's authentication grants `write` scope.
	 * Logged-in WP users with `edit_posts` are treated as `write`; API key
	 * callers expose their key's `scope` field via the request stash.
	 */
	private function caller_has_write_scope( WP_REST_Request $request ): bool {
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return true;
		}
		$api_key = $request->get_param( '_swh_api_key' );
		if ( is_array( $api_key ) && isset( $api_key['scope'] ) && 'write' === $api_key['scope'] ) {
			return true;
		}
		return false;
	}

	/**
	 * Build the context array passed to {@see swh_insert_helpdesk_reply()}.
	 *
	 * @return array<string,mixed>
	 */
	private function context_for( WP_REST_Request $request ): array {
		$context = array(
			'source'   => 'rest_v1',
			'actor_id' => (int) get_current_user_id(),
		);
		$api_key = $request->get_param( '_swh_api_key' );
		if ( is_array( $api_key ) && isset( $api_key['id'] ) && is_string( $api_key['id'] ) ) {
			$context['api_key_id'] = $api_key['id'];
		}
		return $context;
	}

	/**
	 * Resolve a post ID to a `helpdesk_ticket` post or null.
	 */
	private function resolve_ticket( int $id ): ?WP_Post {
		if ( $id <= 0 ) {
			return null;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || 'helpdesk_ticket' !== $post->post_type ) {
			return null;
		}
		return $post;
	}

	/**
	 * Single-value comment meta accessor.
	 *
	 * @param int    $comment_id Comment ID.
	 * @param string $key        Meta key.
	 */
	private static function comment_meta_value( int $comment_id, string $key ): string {
		$v = \get_comment_meta( $comment_id, $key, true );
		return is_scalar( $v ) ? (string) $v : '';
	}

	/**
	 * Wrapper around `get_comment()` returning a WP_Comment or null.
	 *
	 * @param int $comment_id Comment ID.
	 */
	private static function resolve_comment( int $comment_id ): ?WP_Comment {
		$c = \get_comment( $comment_id );
		return $c instanceof WP_Comment ? $c : null;
	}

	/**
	 * Build a `swh_rest_validation_failed` error with field-keyed details.
	 *
	 * @param array<string,string> $errors
	 */
	private function validation_error( array $errors ): \WP_Error {
		return new \WP_Error(
			Errors::VALIDATION_FAILED,
			__( 'Validation failed.', 'simple-wp-helpdesk' ),
			array(
				'status' => 400,
				'errors' => $errors,
			)
		);
	}
}

// Register the controller with the v4.1.0 namespace bootstrap. Bootstrap's
// own rest_api_init listener (priority 10) dispatches register_routes() on
// every queued controller.
Bootstrap::register_controller( new RepliesController() );
