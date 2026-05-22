<?php
/**
 * REST controller for the ⌘K command palette suggestions (v4.0.0 #351).
 *
 * Endpoint: GET /swh/v1/palette/suggest
 * Capability: edit_posts
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
use function get_post_meta;
use function get_posts;
use function register_rest_route;
use function sanitize_text_field;

/**
 * REST controller: GET /swh/v1/palette/suggest
 *
 * Returns up to 8 ticket suggestions for the ⌘K palette.
 * Empty query → recent by last_activity. Non-empty query → WP search.
 */
class PaletteController extends WP_REST_Controller {

	public const NAMESPACE = 'swh/v1';
	public const REST_BASE = 'palette/suggest';

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'suggest' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'q' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Check that the current user can access palette suggestions.
	 *
	 * @param WP_REST_Request $request REST request (unused — capability is global).
	 * @return bool
	 */
	public function permissions_check( WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Return ticket suggestions for the palette.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function suggest( $request ): WP_REST_Response {
		$raw_q = $request->get_param( 'q' );
		$q     = trim( is_scalar( $raw_q ) ? (string) $raw_q : '' );

		$args = array(
			'post_type'      => 'helpdesk_ticket',
			'post_status'    => 'publish',
			'orderby'        => 'meta_value_num',
			'meta_key'       => '_swh_last_activity_at',
			'order'          => 'DESC',
			'posts_per_page' => 8,
		);
		if ( '' !== $q ) {
			$args['s'] = $q;
		}
		$posts = get_posts( $args );

		$tickets = array();
		foreach ( $posts as $p ) {
			$post_id   = is_scalar( $p->ID ) ? (int) $p->ID : 0;
			$tickets[] = array(
				'id'     => $post_id,
				'title'  => is_scalar( $p->post_title ) ? (string) $p->post_title : '',
				'status' => is_scalar( get_post_meta( $post_id, '_ticket_status', true ) )
					? (string) get_post_meta( $post_id, '_ticket_status', true )
					: '',
			);
		}

		return new WP_REST_Response( array( 'tickets' => $tickets ), 200 );
	}
}

add_action(
	'rest_api_init',
	static function (): void {
		( new PaletteController() )->register_routes();
	}
);
