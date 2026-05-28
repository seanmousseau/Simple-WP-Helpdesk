<?php
declare(strict_types=1);

namespace SWH\Rest\V1;

use SWH\Admin\ApiAccessLog;
use SWH\Rest\Bootstrap;
use SWH\Rest\Errors;
use SWH\Rest\V1\Schemas\Ticket as TicketSchema;
use WP_Post;
use WP_Query;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function get_post;
use function get_current_user_id;
use function register_rest_route;
use function sanitize_email;
use function sanitize_text_field;
use function swh_rest_require;
use function swh_update_ticket_fields;
use function wp_insert_post;
use function wp_kses_post;

/**
 * Public REST controller for `swh/v1/tickets`.
 *
 * Phase 3 of the v4.1.0 plan. Implements CRUD over the helpdesk_ticket CPT
 * with the locked schema from {@see TicketSchema}. Every route is gated by
 * {@see swh_rest_require()}; mutations route through
 * {@see swh_update_ticket_fields()} (invariant #19, design-document.md).
 *
 * Routes:
 *
 *  - GET    /swh/v1/tickets         — list (filters + pagination headers)
 *  - GET    /swh/v1/tickets/{id}    — single ticket / 404
 *  - POST   /swh/v1/tickets         — create (201)
 *  - PATCH  /swh/v1/tickets/{id}    — update (200) / 404
 *
 * @since 4.1.0
 */
final class TicketsController extends WP_REST_Controller {

	/**
	 * Public REST namespace for this controller.
	 *
	 * Mirrors {@see Bootstrap::NAMESPACE} so consumers (and static-analysis tools)
	 * can reference the namespace without traversing the bootstrap. Used by the
	 * cross-class namespace-isolation assertion in
	 * SWH\Tests\Unit\Rest\InboxControllerTest (PR #519 follow-up).
	 */
	public const NAMESPACE = Bootstrap::NAMESPACE;

	public const REST_BASE = 'tickets';

	/** Maximum allowed `per_page` for list requests. */
	private const PER_PAGE_MAX = 100;

	/** Default `per_page` for list requests. */
	private const PER_PAGE_DEFAULT = 20;

	public function __construct() {
		$this->namespace = Bootstrap::NAMESPACE;
		$this->rest_base = self::REST_BASE;
	}

	/**
	 * Register `/tickets` collection + `/tickets/{id}` singular routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			Bootstrap::NAMESPACE,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => swh_rest_require( 'read' ),
					'args'                => $this->get_collection_params(),
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

		register_rest_route(
			Bootstrap::NAMESPACE,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				'args'   => array(
					'id' => array(
						'description' => 'Ticket ID.',
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => swh_rest_require( 'read' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => swh_rest_require( 'write' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Locked item schema — single source of truth via {@see TicketSchema::schema()}.
	 *
	 * @return array<string,mixed>
	 */
	public function get_item_schema(): array {
		if ( null !== $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$this->schema = TicketSchema::schema();
		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Query parameters for the list collection.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_collection_params(): array {
		return array(
			'status'   => array(
				'description' => 'Filter by one or more status labels.',
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
			),
			'priority' => array(
				'description' => 'Filter by priority label.',
				'type'        => 'string',
			),
			'assignee' => array(
				'description' => 'Filter by assigned user ID.',
				'type'        => 'integer',
			),
			'category' => array(
				'description' => 'Filter by helpdesk_category term ID.',
				'type'        => 'integer',
			),
			'after'    => array(
				'description' => 'Filter to tickets created at or after this ISO-8601 timestamp.',
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'before'   => array(
				'description' => 'Filter to tickets created at or before this ISO-8601 timestamp.',
				'type'        => 'string',
				'format'      => 'date-time',
			),
			'search'   => array(
				'description' => 'Full-text search over subject + body.',
				'type'        => 'string',
			),
			'page'     => array(
				'description' => 'Page of results.',
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
			'per_page' => array(
				'description' => 'Maximum items per page (clamped to ' . self::PER_PAGE_MAX . ').',
				'type'        => 'integer',
				'default'     => self::PER_PAGE_DEFAULT,
				'minimum'     => 1,
				'maximum'     => self::PER_PAGE_MAX,
			),
		);
	}

	/**
	 * GET /swh/v1/tickets — paginated list.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$page_raw = $request->get_param( 'page' );
		$page     = max( 1, is_numeric( $page_raw ) ? (int) $page_raw : 1 );
		$pp_raw   = $request->get_param( 'per_page' );
		$per_page = is_numeric( $pp_raw ) ? (int) $pp_raw : 0;
		if ( $per_page <= 0 ) {
			$per_page = self::PER_PAGE_DEFAULT;
		}
		if ( $per_page > self::PER_PAGE_MAX ) {
			$per_page = self::PER_PAGE_MAX;
		}

		$query_args = array(
			'post_type'      => 'helpdesk_ticket',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Status[] filter.
		$status = $request->get_param( 'status' );
		if ( is_array( $status ) && ! empty( $status ) ) {
			$query_args['meta_query'][] = array(
				'key'     => '_ticket_status',
				'value'   => array_values( array_map( 'sanitize_text_field', array_filter( $status, 'is_string' ) ) ),
				'compare' => 'IN',
			);
		}

		// Priority filter.
		$priority = $request->get_param( 'priority' );
		if ( is_string( $priority ) && '' !== $priority ) {
			$query_args['meta_query'][] = array(
				'key'   => '_ticket_priority',
				'value' => sanitize_text_field( $priority ),
			);
		}

		// Assignee filter.
		$assignee = $request->get_param( 'assignee' );
		if ( is_numeric( $assignee ) && (int) $assignee > 0 ) {
			$query_args['meta_query'][] = array(
				'key'   => '_ticket_assigned_to',
				'value' => (int) $assignee,
			);
		}

		// Category filter (term ID).
		$category = $request->get_param( 'category' );
		if ( is_numeric( $category ) && (int) $category > 0 ) {
			$query_args['tax_query'][] = array(
				'taxonomy' => 'helpdesk_category',
				'field'    => 'term_id',
				'terms'    => (int) $category,
			);
		}

		// After/before filters (ISO-8601 timestamps).
		$after      = $request->get_param( 'after' );
		$before     = $request->get_param( 'before' );
		$date_query = array();
		if ( is_string( $after ) && '' !== $after ) {
			$date_query['after']     = $after;
			$date_query['inclusive'] = true;
		}
		if ( is_string( $before ) && '' !== $before ) {
			$date_query['before']    = $before;
			$date_query['inclusive'] = true;
		}
		if ( ! empty( $date_query ) ) {
			$query_args['date_query'] = array( $date_query );
		}

		// Free-text search filter.
		$search = $request->get_param( 'search' );
		if ( is_string( $search ) && '' !== $search ) {
			$query_args['s'] = sanitize_text_field( $search );
		}

		$query = new WP_Query( $query_args );
		$items = array();
		foreach ( (array) $query->posts as $p ) {
			if ( $p instanceof WP_Post ) {
				$items[] = TicketSchema::from_post( $p );
			}
		}

		$total       = (int) $query->found_posts;
		$total_pages = (int) ceil( $total / $per_page );

		$response = new WP_REST_Response( $items, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );
		return ResponseDecorator::with_rate_limit_headers( $response, $request );
	}

	/**
	 * GET /swh/v1/tickets/{id}.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function get_item( $request ) {
		$id_raw = $request->get_param( 'id' );
		$id     = is_numeric( $id_raw ) ? (int) $id_raw : 0;
		$post   = $this->resolve_ticket( $id );
		if ( null === $post ) {
			return Errors::respond( Errors::NOT_FOUND, 404, __( 'Ticket not found.', 'simple-wp-helpdesk' ) );
		}
		$response = ResponseDecorator::with_rate_limit_headers(
			new WP_REST_Response( TicketSchema::from_post( $post ), 200 ),
			$request
		);
		$this->record_access( (int) $post->ID, 'read', $request );
		return $response;
	}

	/**
	 * POST /swh/v1/tickets — create.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function create_item( $request ) {
		$subject = $request->get_param( 'subject' );
		$body    = $request->get_param( 'body' );
		$email   = $request->get_param( 'requester_email' );

		$errors = array();
		if ( ! is_string( $subject ) || '' === trim( $subject ) ) {
			$errors['subject'] = __( 'Subject is required.', 'simple-wp-helpdesk' );
		}
		if ( ! is_string( $email ) || '' === sanitize_email( $email ) ) {
			$errors['requester_email'] = __( 'A valid requester_email is required.', 'simple-wp-helpdesk' );
		}
		if ( ! empty( $errors ) ) {
			return $this->validation_error( $errors );
		}

		$insert_result = wp_insert_post(
			array(
				'post_type'    => 'helpdesk_ticket',
				'post_status'  => 'publish',
				'post_title'   => sanitize_text_field( is_string( $subject ) ? $subject : '' ),
				'post_content' => wp_kses_post( is_string( $body ) ? $body : '' ),
			),
			true
		);
		if ( is_wp_error( $insert_result ) || ! is_numeric( $insert_result ) || (int) $insert_result <= 0 ) {
			return Errors::respond( Errors::VALIDATION_FAILED, 400, __( 'Failed to create ticket.', 'simple-wp-helpdesk' ) );
		}
		$ticket_id = (int) $insert_result;

		// Apply optional fields through the canonical helper.
		$fields = $this->build_field_patch( $request );
		if ( ! empty( $fields ) ) {
			$result = swh_update_ticket_fields( $ticket_id, $fields, $this->context_for( $request ) );
			if ( ! $result['ok'] ) {
				return $this->validation_error( $result['errors'] );
			}
		}

		$post = get_post( $ticket_id );
		if ( ! $post instanceof WP_Post ) {
			return Errors::respond( Errors::NOT_FOUND, 404, __( 'Ticket not found after create.', 'simple-wp-helpdesk' ) );
		}
		$response = ResponseDecorator::with_rate_limit_headers(
			new WP_REST_Response( TicketSchema::from_post( $post ), 201 ),
			$request
		);
		$this->record_access( $ticket_id, 'write', $request );
		return $response;
	}

	/**
	 * PATCH /swh/v1/tickets/{id} — update.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function update_item( $request ) {
		$id_raw = $request->get_param( 'id' );
		$id     = is_numeric( $id_raw ) ? (int) $id_raw : 0;
		$post   = $this->resolve_ticket( $id );
		if ( null === $post ) {
			return Errors::respond( Errors::NOT_FOUND, 404, __( 'Ticket not found.', 'simple-wp-helpdesk' ) );
		}

		$fields = $this->build_field_patch( $request );
		if ( empty( $fields ) ) {
			// Nothing to do — return the current ticket. Audit-log this as a
			// `write` attempt: the caller used a write-scope route, even if
			// the patch was empty.
			$response = ResponseDecorator::with_rate_limit_headers(
				new WP_REST_Response( TicketSchema::from_post( $post ), 200 ),
				$request
			);
			$this->record_access( $id, 'write', $request );
			return $response;
		}

		$result = swh_update_ticket_fields( $id, $fields, $this->context_for( $request ) );
		if ( ! $result['ok'] ) {
			return $this->validation_error( $result['errors'] );
		}

		$post = get_post( $id );
		if ( ! $post instanceof WP_Post ) {
			return Errors::respond( Errors::NOT_FOUND, 404, __( 'Ticket not found after update.', 'simple-wp-helpdesk' ) );
		}
		$response = ResponseDecorator::with_rate_limit_headers(
			new WP_REST_Response( TicketSchema::from_post( $post ), 200 ),
			$request
		);
		$this->record_access( $id, 'write', $request );
		return $response;
	}

	/**
	 * Fire-and-forget audit-log write. No-op when the toggle is off. Called
	 * AFTER the response has been built but BEFORE returning it on the success
	 * path of every single-ticket-scoped route. Collection GETs (`get_items`)
	 * deliberately do NOT call this — see Phase 9 contract.
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
	 * Build the helper field patch from a REST request body. Only keys
	 * recognised by {@see swh_update_ticket_fields()} are forwarded.
	 *
	 * @return array<string,mixed>
	 */
	private function build_field_patch( WP_REST_Request $request ): array {
		$fields = array();
		foreach ( array( 'status', 'priority', 'assignee', 'category', 'subject', 'body', 'requester_email', 'requester_name', 'cc_emails' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$fields[ $key ] = $value;
			}
		}
		return $fields;
	}

	/**
	 * Build the context array passed to {@see swh_update_ticket_fields()}.
	 * Phase 9's audit log will consume this — pass the API key entry that
	 * {@see \SWH\Rest\Auth::check_request()} stashed on the request, when
	 * present, so the audit attribution can name the key.
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
	 * Resolve a post ID to a `helpdesk_ticket` post, or return null when not
	 * found or wrong type.
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
	 * Build a `swh_rest_validation_failed` error response with the helper's
	 * field-keyed error array attached as `data.errors`.
	 *
	 * @param array<string,string> $errors
	 */
	private function validation_error( array $errors ): \WP_Error {
		$err = Errors::respond( Errors::VALIDATION_FAILED, 400, __( 'Validation failed.', 'simple-wp-helpdesk' ) );
		// WP_Error stores data array — append fields. The Errors helper stored
		// `status` already; preserve it.
		$data = $err->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = array( 'status' => 400 );
		}
		$data['errors'] = $errors;
		// WP_Error has no setter for data in WP core but supports add_data;
		// the stub used in tests exposes the data array via the constructor —
		// re-create the error with the merged data for portability.
		return new \WP_Error( Errors::VALIDATION_FAILED, $err->get_error_message(), $data );
	}
}

// Register the controller with the v4.1.0 namespace bootstrap. Bootstrap's
// own rest_api_init listener (priority 10) dispatches register_routes() on
// every queued controller.
Bootstrap::register_controller( new TicketsController() );
