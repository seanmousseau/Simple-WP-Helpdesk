<?php
/**
 * REST controller for the admin inbox list view (v4.0.0 #349).
 *
 * Endpoint: GET /swh/v1/tickets
 * Capability: edit_posts (matches helpdesk_ticket cap_type).
 *
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

namespace SWH\Rest;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use SWH\Concurrency\TicketVersion;
use function add_action;
use function current_user_can;
use function get_current_user_id;
use function get_post;
use function register_rest_route;
use function swh_get_int_meta;
use function swh_get_string_meta;

/**
 * REST controller: GET /swh/v1/tickets
 *
 * Returns a paginated list of helpdesk tickets for the inbox view.
 * The `view` parameter selects one of the 4 default saved views.
 */
class InboxController extends WP_REST_Controller {

	public const NAMESPACE = 'swh/v1';
	public const REST_BASE = 'tickets';

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'view'        => array(
						'type'              => 'string',
						// Custom: sanitize_key strips colons used by synthetic
						// views like "status:open"; whitelist alpha/digit/_/colon.
						'sanitize_callback' => static function ( $v ) {
							return is_scalar( $v ) ? preg_replace( '/[^a-z0-9_:]/', '', strtolower( (string) $v ) ) : '';
						},
						'default'           => 'unassigned',
					),
					'page'        => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'    => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
					'sort'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => 'last_reply_desc',
					),
					'search'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'range_start' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'range_end'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Check that the current user can access the inbox.
	 *
	 * @param WP_REST_Request $request REST request (unused — capability is global).
	 * @return bool
	 */
	public function permissions_check( WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Return a paginated ticket list for the requested view.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ): WP_REST_Response {
		$view_raw = $request->get_param( 'view' );
		$page_raw = $request->get_param( 'page' );
		$ppp_raw  = $request->get_param( 'per_page' );
		$sort_raw = $request->get_param( 'sort' );

		$view     = is_string( $view_raw ) ? $view_raw : 'unassigned';
		$page     = max( 1, is_numeric( $page_raw ) ? (int) $page_raw : 1 );
		$per_page = min( 200, max( 1, is_numeric( $ppp_raw ) ? (int) $ppp_raw : 50 ) );
		$sort     = is_string( $sort_raw ) ? $sort_raw : 'last_reply_desc';

		$query_args = $this->build_query_args( $view, $sort );

		// Apply search param to WP_Query when present (#349 CR fix: param was
		// registered but never forwarded to the query args).
		$search_raw = $request->get_param( 'search' );
		if ( is_string( $search_raw ) && '' !== $search_raw ) {
			$query_args['s'] = sanitize_text_field( $search_raw );
		}

		// Optional date range constraint — from URL hash drill-down (v4.0.0 #359).
		$created_after  = $request->get_param( 'range_start' );
		$created_before = $request->get_param( 'range_end' );
		if (
			is_string( $created_after ) && '' !== $created_after
			&& preg_match( '/^\d{4}-\d{2}-\d{2}$/', $created_after )
			&& is_string( $created_before ) && '' !== $created_before
			&& preg_match( '/^\d{4}-\d{2}-\d{2}$/', $created_before )
		) {
			$query_args['date_query'] = array(
				array(
					'after'     => $created_after,
					'before'    => $created_before,
					'inclusive' => true,
				),
			);
		}
		$query_args['posts_per_page'] = $per_page;
		$query_args['paged']          = $page;
		$query_args['fields']         = 'ids';

		$result = $this->run_query( $query_args );
		$items  = array();
		foreach ( $result['posts'] as $id ) {
			$row = $this->prepare_row( (int) $id );
			if ( $row ) {
				$items[] = $row;
			}
		}

		return new WP_REST_Response(
			array(
				'tickets'  => $items,
				'total'    => $result['found_posts'],
				'page'     => $page,
				'per_page' => $per_page,
			),
			200
		);
	}

	/**
	 * Execute a WP_Query and return posts + found_posts.
	 *
	 * Extracted so tests can override without hitting the database.
	 *
	 * @param array<string,mixed> $args WP_Query arguments.
	 * @return array{posts:int[],found_posts:int}
	 */
	protected function run_query( array $args ): array {
		$query = new \WP_Query( $args );
		// When fields='ids', WP_Query returns int[]. Filter defensively so the
		// return type stays int[] regardless of PHPStan's mixed inference.
		$ids = array_values(
			array_filter(
				(array) $query->posts,
				static fn( $v ) => is_int( $v )
			)
		);
		return array(
			'posts'       => $ids,
			'found_posts' => (int) $query->found_posts,
		);
	}

	/**
	 * Build WP_Query args for the given view + sort.
	 *
	 * @param string $view View key (unassigned, my_open, high_priority, resolved_this_week).
	 * @param string $sort Sort key (last_reply_desc, last_reply_asc).
	 * @return array<string,mixed>
	 */
	private function build_query_args( string $view, string $sort ): array {
		$base = array(
			'post_type'   => 'helpdesk_ticket',
			'post_status' => 'publish',
			'orderby'     => 'meta_value_num',
			'meta_key'    => '_swh_last_activity_at',
			'order'       => 'last_reply_desc' === $sort ? 'DESC' : 'ASC',
		);

		switch ( $view ) {
			case 'all':
				// No filters. Order by post_date so legacy tickets without
				// _swh_last_activity_at meta still appear (meta_value_num
				// ordering would silently exclude them).
				unset( $base['meta_key'] );
				$base['orderby'] = 'date';
				break;
			case 'unassigned':
				$base['meta_query'] = array(
					array(
						'key'     => '_ticket_status',
						'value'   => array( 'open', 'in_progress' ),
						'compare' => 'IN',
					),
					// Match tickets where _ticket_assigned is empty OR missing
					// entirely — new tickets may not have the meta key set.
					array(
						'relation' => 'OR',
						array(
							'key'     => '_ticket_assigned',
							'value'   => '',
							'compare' => '=',
						),
						array(
							'key'     => '_ticket_assigned',
							'compare' => 'NOT EXISTS',
						),
					),
				);
				break;
			case 'my_open':
				$base['meta_query'] = array(
					array(
						'key'     => '_ticket_status',
						'value'   => array( 'open', 'in_progress' ),
						'compare' => 'IN',
					),
					array(
						'key'   => '_ticket_assigned',
						'value' => (string) get_current_user_id(),
					),
				);
				break;
			case 'high_priority':
				$base['meta_query'] = array(
					array(
						'key'     => '_ticket_status',
						'value'   => array( 'open', 'in_progress' ),
						'compare' => 'IN',
					),
					array(
						'key'     => '_ticket_priority',
						'value'   => array( 'high', 'urgent' ),
						'compare' => 'IN',
					),
				);
				break;
			case 'resolved_this_week':
				$base['meta_query'] = array(
					array(
						'key'   => '_ticket_status',
						'value' => 'resolved',
					),
					array(
						'key'     => '_swh_resolved_at',
						'value'   => time() - WEEK_IN_SECONDS,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				);
				break;
			default:
				// Synthetic views driven by URL hash (v4.0.0 #359):
				// status:open, status:resolved, status:in_progress, status:closed.
				if ( 0 === strpos( $view, 'status:' ) ) {
					$status = sanitize_key( substr( $view, strlen( 'status:' ) ) );
					if ( in_array( $status, array( 'open', 'in_progress', 'resolved', 'closed' ), true ) ) {
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						$base['meta_query'] = array(
							array(
								'key'     => '_ticket_status',
								'value'   => $status,
								'compare' => '=',
							),
						);
					}
				}
				break;
		}

		return $base;
	}

	/**
	 * Build the per-row payload for a single ticket.
	 *
	 * @param int $id Ticket post ID.
	 * @return array<string,mixed>|null Null when post does not exist.
	 */
	private function prepare_row( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		return array(
			'id'            => $id,
			'title'         => $post->post_title,
			'status'        => swh_get_string_meta( $id, '_ticket_status' ),
			'priority'      => swh_get_string_meta( $id, '_ticket_priority' ),
			'assignee'      => swh_get_int_meta( $id, '_ticket_assigned' ),
			'email'         => swh_get_string_meta( $id, '_ticket_email' ),
			'last_activity' => swh_get_int_meta( $id, '_swh_last_activity_at' ),
			'version'       => TicketVersion::current( $id ),
		);
	}
}

// Register on rest_api_init.
add_action(
	'rest_api_init',
	static function (): void {
		( new InboxController() )->register_routes();
	}
);
