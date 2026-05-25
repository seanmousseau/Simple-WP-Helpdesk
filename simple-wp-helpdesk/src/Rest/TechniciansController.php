<?php
/**
 * REST controller: list assignable users for the drawer Assignee select (v4.0.2 #434).
 *
 * Endpoint: GET /swh/v1/technicians
 * Capability: edit_posts (any tech / admin can read the list)
 *
 * Returns administrators + swh_technician users so the drawer's Assignee
 * SelectControl has options. Core wp/v2/users would require list_users
 * which technicians lack; this endpoint is the documented assignable-user
 * surface.
 *
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

namespace SWH\Rest;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function current_user_can;
use function get_users;
use function register_rest_route;

/**
 * REST controller: GET /swh/v1/technicians
 *
 * Returns the assignable-user list (administrators + swh_technician role)
 * for the drawer's Assignee SelectControl options. Scoped to edit_posts.
 */
class TechniciansController extends WP_REST_Controller {

	public const NAMESPACE = 'swh/v1';
	public const REST_BASE = 'technicians';

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	public function permissions_check( WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( 'edit_posts' );
	}

	/**
	 * GET /swh/v1/technicians — returns { users: [{ id, name }] }.
	 *
	 * Includes administrators and the swh_technician role. Sorted by
	 * display_name. The drawer prepends an "Unassigned" option client-side.
	 *
	 * @param WP_REST_Request $request REST request (unused).
	 * @return WP_REST_Response
	 */
	public function index( $request ): WP_REST_Response {
		unset( $request );

		$users = get_users(
			array(
				'role__in' => array( 'administrator', 'swh_technician' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'fields'   => array( 'ID', 'display_name' ),
			)
		);

		$out = array();
		foreach ( $users as $u ) {
			$out[] = array(
				'id'   => (int) $u->ID,
				'name' => (string) $u->display_name,
			);
		}

		return new WP_REST_Response( array( 'users' => $out ), 200 );
	}
}

add_action(
	'rest_api_init',
	static function (): void {
		( new TechniciansController() )->register_routes();
	}
);
