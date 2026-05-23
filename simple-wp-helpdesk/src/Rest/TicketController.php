<?php
declare(strict_types=1);

namespace SWH\Rest;

use SWH\Concurrency\TicketVersion;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function current_user_can;
use function get_comment_meta;
use function get_comments;
use function get_post;
use function get_post_meta;
use function get_userdata;
use function register_rest_route;
use function sanitize_key;
use function swh_set_ticket_status;
use function update_post_meta;
use function wp_get_post_terms;
use function wp_get_current_user;
use function wp_insert_comment;
use function wp_set_post_terms;
use function update_comment_meta;

/**
 * REST controller for the v4.0 ticket drawer endpoints.
 *
 * Provides GET /tickets/{id}, PATCH /tickets/{id}, and
 * POST /tickets/{id}/reply — all with optimistic-concurrency
 * version checks via TicketVersion.
 *
 * @package Simple_WP_Helpdesk
 * @since   4.0.0
 */
class TicketController extends WP_REST_Controller {

	public const NAMESPACE = 'swh/v1';
	public const REST_BASE = 'tickets';

	/** {@inheritDoc} */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_item' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'               => array(
						'type'     => 'integer',
						'required' => true,
					),
					'expected_version' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'patch'            => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/(?P<id>\d+)/reply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_reply' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'               => array(
						'type'     => 'integer',
						'required' => true,
					),
					'expected_version' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'body'             => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'wp_kses_post',
					),
					'internal'         => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'mark_resolved'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Check that the current user can access ticket endpoints.
	 *
	 * Global gate: `edit_posts` (technician minimum).
	 * Per-ticket gate: `edit_post` on the specific post ID when present in the
	 * request. This prevents a technician with `edit_posts` but without access to
	 * a specific post (e.g. restricted-to-assigned mode) from reading or mutating
	 * another user's ticket via the REST drawer endpoints.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function permissions_check( WP_REST_Request $request ): bool {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$raw_id = $request->get_param( 'id' );
		if ( null !== $raw_id && is_numeric( $raw_id ) ) {
			$id = (int) $raw_id;
			if ( $id > 0 && ! current_user_can( 'edit_post', $id ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * GET /swh/v1/tickets/{id} — return full drawer payload.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_item( $request ): WP_REST_Response {
		$raw_id = $request->get_param( 'id' );
		$id     = is_numeric( $raw_id ) ? (int) $raw_id : 0;
		$post   = get_post( $id );
		if ( ! $post || 'helpdesk_ticket' !== $post->post_type ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		return new WP_REST_Response(
			array(
				'id'       => $id,
				'title'    => $post->post_title,
				'body'     => $post->post_content,
				'version'  => TicketVersion::current( $id ),
				'created'  => $post->post_date_gmt,
				'status'   => $this->meta_string( $id, '_ticket_status' ),
				'priority' => $this->meta_string( $id, '_ticket_priority' ),
				'assignee' => $this->meta_int( $id, '_ticket_assigned' ),
				'email'    => $this->meta_string( $id, '_ticket_email' ),
				'meta'     => array( 'tags' => $this->prepare_tags( $id ) ),
				'thread'   => $this->prepare_thread( $id ),
			),
			200
		);
	}

	/**
	 * PATCH /swh/v1/tickets/{id} — update meta with version check.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function update_item( $request ): WP_REST_Response {
		$raw_id           = $request->get_param( 'id' );
		$raw_ev           = $request->get_param( 'expected_version' );
		$raw_patch        = $request->get_param( 'patch' );
		$id               = is_numeric( $raw_id ) ? (int) $raw_id : 0;
		$expected_version = is_numeric( $raw_ev ) ? (int) $raw_ev : 0;
		$patch            = is_array( $raw_patch ) ? $raw_patch : array();

		$post = get_post( $id );
		if ( ! $post || 'helpdesk_ticket' !== $post->post_type ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		if ( ! TicketVersion::check( $id, $expected_version ) ) {
			$current = $this->get_item( $request )->get_data();
			return new WP_REST_Response(
				array(
					'error'   => 'version_conflict',
					'current' => $current,
				),
				409
			);
		}

		if ( isset( $patch['status'] ) && is_string( $patch['status'] ) ) {
			swh_set_ticket_status( $id, $patch['status'] );  // bumps via B2.
		}
		if ( array_key_exists( 'assignee', $patch ) ) {
			$assignee = (int) $patch['assignee'];
			update_post_meta( $id, '_ticket_assigned', $assignee > 0 ? $assignee : '' );
			TicketVersion::bump( $id );
		}
		if ( isset( $patch['priority'] ) && is_string( $patch['priority'] ) ) {
			update_post_meta( $id, '_ticket_priority', sanitize_key( $patch['priority'] ) );
			TicketVersion::bump( $id );
		}
		if ( isset( $patch['tags'] ) && is_array( $patch['tags'] ) ) {
			$tags = array_map(
				static function ( mixed $t ): string {
					return sanitize_text_field( is_scalar( $t ) ? (string) $t : '' ); },
				$patch['tags']
			);
			wp_set_post_terms( $id, $tags, 'helpdesk_category', false );
			TicketVersion::bump( $id );
		}

		return $this->get_item( $request );
	}

	/**
	 * POST /swh/v1/tickets/{id}/reply — insert reply with version check.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function create_reply( $request ): WP_REST_Response {
		$raw_id           = $request->get_param( 'id' );
		$raw_ev           = $request->get_param( 'expected_version' );
		$raw_body         = $request->get_param( 'body' );
		$id               = is_numeric( $raw_id ) ? (int) $raw_id : 0;
		$expected_version = is_numeric( $raw_ev ) ? (int) $raw_ev : 0;
		$body             = is_string( $raw_body ) ? $raw_body : '';
		$internal         = (bool) $request->get_param( 'internal' );
		$mark_resolved    = (bool) $request->get_param( 'mark_resolved' );

		$post = get_post( $id );
		if ( ! $post || 'helpdesk_ticket' !== $post->post_type ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		if ( ! TicketVersion::check( $id, $expected_version ) ) {
			$current = $this->get_item( $request )->get_data();
			return new WP_REST_Response(
				array(
					'error'   => 'version_conflict',
					'current' => $current,
				),
				409
			);
		}

		$current_user = wp_get_current_user();
		$comment_id   = wp_insert_comment(
			array(
				'comment_post_ID'      => $id,
				'comment_type'         => 'helpdesk_reply',
				'comment_author'       => $current_user->display_name,
				'comment_author_email' => $current_user->user_email,
				'user_id'              => $current_user->ID,
				'comment_content'      => $body,
				'comment_approved'     => 1,
			)
		);

		// Guard: wp_insert_comment() returns false on failure. Do not bump version
		// or report success if the comment was not actually persisted.
		if ( false === $comment_id || 0 === $comment_id ) {
			return new WP_REST_Response(
				array( 'error' => 'comment_insert_failed' ),
				500
			);
		}

		if ( $internal ) {
			update_comment_meta( $comment_id, '_is_internal_note', '1' );
		}

		// REST path inserts directly; bump explicitly (B2 hooks cover PHP-side
		// sites, not this REST route).
		TicketVersion::bump( $id );

		if ( $mark_resolved ) {
			swh_set_ticket_status( $id, 'resolved' );
		}

		return $this->get_item( $request );
	}

	/**
	 * Get a string post meta value.
	 *
	 * @param int    $id  Post ID.
	 * @param string $key Meta key.
	 * @return string
	 */
	protected function meta_string( int $id, string $key ): string {
		$v = get_post_meta( $id, $key, true );
		return is_string( $v ) ? $v : '';
	}

	/**
	 * Get an integer post meta value.
	 *
	 * @param int    $id  Post ID.
	 * @param string $key Meta key.
	 * @return int
	 */
	protected function meta_int( int $id, string $key ): int {
		$v = get_post_meta( $id, $key, true );
		return is_numeric( $v ) ? (int) $v : 0;
	}

	/**
	 * Return tag names for the ticket.
	 *
	 * @param int $id Post ID.
	 * @return string[]
	 */
	protected function prepare_tags( int $id ): array {
		$terms = wp_get_post_terms( $id, 'helpdesk_category', array( 'fields' => 'names' ) );
		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Build the thread array from helpdesk_reply comments.
	 *
	 * @param int $id Post ID.
	 * @return array<int,array<string,mixed>>
	 */
	protected function prepare_thread( int $id ): array {
		$comments = get_comments(
			array(
				'post_id' => $id,
				'type'    => 'helpdesk_reply',
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
			)
		);

		$out = array();
		if ( ! is_array( $comments ) ) {
			return $out;
		}

		foreach ( $comments as $c ) {
			if ( ! ( $c instanceof \WP_Comment ) ) {
				continue;
			}
			$author_id   = (int) $c->user_id;
			$author      = $author_id > 0 ? get_userdata( $author_id ) : null;
			$comment_id  = (int) $c->comment_ID;
			$meta_raw    = get_comment_meta( $comment_id, '_is_internal_note', true );
			$is_internal = is_string( $meta_raw ) && '1' === $meta_raw;

			$out[] = array(
				'id'           => $comment_id,
				'author_name'  => $author ? $author->display_name : $c->comment_author,
				'author_email' => $author ? $author->user_email : $c->comment_author_email,
				'is_staff'     => $author_id > 0,
				'is_internal'  => $is_internal,
				'date_gmt'     => $c->comment_date_gmt,
				'content'      => $c->comment_content,
			);
		}
		return $out;
	}
}

add_action(
	'rest_api_init',
	static function (): void {
		( new TicketController() )->register_routes();
	}
);
