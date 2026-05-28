<?php
declare(strict_types=1);

namespace SWH\Rest\V1;

use SWH\Rest\Bootstrap;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;

use function apply_filters;
use function get_users;
use function register_rest_route;
use function swh_rest_require;

/**
 * Public REST controller for `swh/v1/users/technicians`.
 *
 * Phase 5 of the v4.1.0 plan (#364). Read-only listing of users who carry
 * the `edit_posts` capability — i.e. the helpdesk technician pool.
 *
 * **Allowlist response.** The shape returned by {@see from_user()} contains
 * exactly `{ id, display_name, email }` — no `user_login`, no `roles`, no
 * `capabilities`, no raw meta. Adding a field is a public-API change.
 *
 * **Privacy lever.** When the `swh_hide_staff_email` filter returns true
 * (default false), the `email` field is OMITTED from the response objects.
 * Operators who don't want client-scope keys to learn staff emails can
 * `add_filter( 'swh_hide_staff_email', '__return_true' )`.
 *
 * Route:
 *  - GET /swh/v1/users/technicians — list technicians.
 *
 * @since 4.1.0
 */
final class UsersController extends WP_REST_Controller {

	public const REST_BASE = 'users';

	public function __construct() {
		$this->namespace = Bootstrap::NAMESPACE;
		$this->rest_base = self::REST_BASE;
	}

	/**
	 * Register the `/users/technicians` route.
	 */
	public function register_routes(): void {
		register_rest_route(
			Bootstrap::NAMESPACE,
			'/' . $this->rest_base . '/technicians',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => swh_rest_require( 'read' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Locked item schema for a technician object.
	 *
	 * @return array<string,mixed>
	 */
	public function get_item_schema(): array {
		if ( null !== $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'technician',
			'type'       => 'object',
			'properties' => array(
				'id'           => array(
					'description' => 'WP user ID.',
					'type'        => 'integer',
					'readonly'    => true,
				),
				'display_name' => array(
					'description' => 'Display name as configured by the operator.',
					'type'        => 'string',
				),
				'email'        => array(
					'description' => 'User email. Null when `swh_hide_staff_email` filter returns true.',
					'type'        => array( 'string', 'null' ),
					'format'      => 'email',
				),
			),
		);
		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * GET /swh/v1/users/technicians.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function get_items( $request ): WP_REST_Response {
		$users = get_users(
			array(
				'capability' => 'edit_posts',
				'orderby'    => 'display_name',
				'order'      => 'ASC',
				// 'fields' default returns WP_User objects; we project to the
				// locked allowlist below, so no risk of leaking raw meta.
			)
		);

		$hide_email = (bool) apply_filters( 'swh_hide_staff_email', false );

		$items = array();
		foreach ( (array) $users as $u ) {
			if ( $u instanceof WP_User ) {
				$items[] = self::from_user( $u, $hide_email );
			}
		}
		return ResponseDecorator::with_rate_limit_headers(
			new WP_REST_Response( $items, 200 ),
			$request
		);
	}

	/**
	 * Project a WP_User into the locked technician shape.
	 *
	 * @return array<string,mixed>
	 */
	private static function from_user( WP_User $user, bool $hide_email ): array {
		// Always emit the `email` key so the response shape is stable —
		// clients can read `email` without conditional key-existence checks.
		// When hidden, the value is null (documented as nullable in the
		// OpenAPI schema).
		$email = isset( $user->user_email ) ? (string) $user->user_email : '';
		return array(
			'id'           => (int) $user->ID,
			'display_name' => isset( $user->display_name ) ? (string) $user->display_name : '',
			'email'        => $hide_email ? null : $email,
		);
	}
}

// Register the controller with the v4.1.0 namespace bootstrap. Bootstrap's
// own rest_api_init listener (priority 10) dispatches register_routes() on
// every queued controller.
Bootstrap::register_controller( new UsersController() );
