<?php
/**
 * REST controller for bulk ticket actions (v4.0.0 #353).
 *
 * Endpoint: POST /swh/v1/bulk-action
 * Capability: edit_posts
 *
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

namespace SWH\Rest;

use SWH\Concurrency\TicketVersion;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function current_user_can;
use function register_rest_route;
use function update_post_meta;
use function wp_set_post_terms;

/**
 * REST controller: POST /swh/v1/bulk-action
 *
 * Applies status/assign/priority/tag/close across selected tickets.
 * Per-ticket optimistic concurrency via TicketVersion::check().
 * Returns { succeeded[], conflicts: [{id, version_now}] }.
 * Cap: 200 tickets per request.
 */
class BulkController extends WP_REST_Controller {

	public const NAMESPACE   = 'swh/v1';
	public const REST_BASE   = 'bulk-action';
	public const MAX_TICKETS = 200;

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_action' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'action'      => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'status', 'assign', 'priority', 'tag', 'close' ),
					),
					'params'      => array( 'type' => 'object', 'required' => true ),
					'ticket_ids'  => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'integer' ) ),
					'version_map' => array( 'type' => 'object', 'required' => true ),
				),
			)
		);
	}

	/**
	 * Check that the current user can perform bulk ticket operations.
	 *
	 * @param WP_REST_Request $request REST request (unused — capability is global).
	 * @return bool
	 */
	public function permissions_check( WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Apply a bulk action across selected tickets.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function bulk_action( $request ): WP_REST_Response {
		$action      = (string) $request->get_param( 'action' );
		$params      = (array) $request->get_param( 'params' );
		$ticket_ids  = array_map( 'intval', (array) $request->get_param( 'ticket_ids' ) );
		$version_map = (array) $request->get_param( 'version_map' );

		if ( count( $ticket_ids ) > self::MAX_TICKETS ) {
			return new WP_REST_Response(
				array( 'error' => 'too_many_tickets', 'max' => self::MAX_TICKETS ),
				400
			);
		}

		$succeeded = array();
		$conflicts = array();

		foreach ( $ticket_ids as $id ) {
			$expected = isset( $version_map[ $id ] ) ? (int) $version_map[ $id ] : 0;
			$current  = TicketVersion::current( $id );
			if ( $current !== $expected ) {
				$conflicts[] = array( 'id' => $id, 'version_now' => $current );
				continue;
			}
			$this->apply( $action, $id, $params );
			$succeeded[] = $id;
		}

		return new WP_REST_Response(
			array( 'succeeded' => $succeeded, 'conflicts' => $conflicts ),
			200
		);
	}

	/**
	 * Apply a single action to a single ticket.
	 *
	 * @param string  $action Action key.
	 * @param int     $id     Ticket post ID.
	 * @param mixed[] $params Action parameters.
	 */
	protected function apply( string $action, int $id, array $params ): void {
		switch ( $action ) {
			case 'status':
				if ( isset( $params['status'] ) && is_string( $params['status'] ) ) {
					swh_set_ticket_status( $id, $params['status'] );  // bumps version internally
				}
				return;
			case 'close':
				swh_set_ticket_status( $id, 'closed' );
				return;
			case 'assign':
				$assignee = isset( $params['assignee'] ) ? (int) $params['assignee'] : 0;
				update_post_meta( $id, '_ticket_assigned', $assignee > 0 ? $assignee : '' );
				TicketVersion::bump( $id );
				return;
			case 'priority':
				if ( isset( $params['priority'] ) && is_string( $params['priority'] ) ) {
					update_post_meta( $id, '_ticket_priority', sanitize_key( $params['priority'] ) );
					TicketVersion::bump( $id );
				}
				return;
			case 'tag':
				$tags   = isset( $params['tags'] ) ? array_map( 'sanitize_text_field', (array) $params['tags'] ) : array();
				$append = ! empty( $params['append'] );
				wp_set_post_terms( $id, $tags, 'helpdesk_category', $append );
				TicketVersion::bump( $id );
				return;
		}
	}
}

add_action(
	'rest_api_init',
	static function (): void {
		( new BulkController() )->register_routes();
	}
);
