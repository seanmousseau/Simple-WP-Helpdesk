<?php
declare(strict_types=1);

namespace SWH\Rest;

use SWH\Views\ViewsRepository;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function add_action;
use function current_user_can;
use function get_current_user_id;
use function register_rest_route;
use function sanitize_text_field;

/**
 * REST controller for saved views.
 *
 * GET/POST/PATCH/DELETE /swh/v1/views
 *
 * @since 4.0.0 (#352)
 */
class ViewsController extends WP_REST_Controller {

	public const NAMESPACE = 'swh/v1';
	public const REST_BASE = 'views';

	/**
	 * Storage layer for saved views.
	 *
	 * @var ViewsRepository
	 */
	private ViewsRepository $repo;

	public function __construct( ?ViewsRepository $repo = null ) {
		$this->repo = $repo ?? new ViewsRepository();
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_views' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_view' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'name'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'scope'   => array(
						'type'    => 'string',
						'default' => 'personal',
						'enum'    => array( 'personal', 'global' ),
					),
					'filters' => array(
						'type'    => 'object',
						'default' => array(),
					),
					'sort'    => array(
						'type'    => 'string',
						'default' => 'last_reply_desc',
					),
					'columns' => array(
						'type'    => 'array',
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/(?P<id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_view' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'scope' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'personal', 'global' ),
					),
					'patch' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/' . self::REST_BASE . '/(?P<id>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_view' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'scope' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'personal', 'global' ),
					),
				),
			)
		);
	}

	/**
	 * Check that the current user can access view endpoints.
	 *
	 * Global-scope create/update/delete requires `manage_options`; personal-scope
	 * and read operations require only `edit_posts`. The scope is checked per-request
	 * inside each handler to return the right HTTP status; this gate just filters
	 * out unauthenticated callers early.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function permissions_check( WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Return the merged global + personal view list for the current user.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function list_views( $request ): WP_REST_Response {
		unset( $request );
		$views = $this->repo->get_all_for_user( get_current_user_id() );
		return new WP_REST_Response( array( 'views' => $views ), 200 );
	}

	/**
	 * Create a new personal or global saved view.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function create_view( $request ): WP_REST_Response {
		$raw_scope = $request->get_param( 'scope' );
		$scope     = is_scalar( $raw_scope ) ? (string) $raw_scope : 'personal';
		$view      = $this->build_view_array( $request );

		if ( 'global' === $scope ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
			}
			$saved = $this->repo->save_global( $view );
			if ( null === $saved ) {
				return new WP_REST_Response( array( 'error' => 'save_failed' ), 500 );
			}
			$saved['scope'] = 'global';
			return new WP_REST_Response( array( 'view' => $saved ), 201 );
		}

		$saved          = $this->repo->save_personal( get_current_user_id(), $view );
		$saved['scope'] = 'personal';
		return new WP_REST_Response( array( 'view' => $saved ), 201 );
	}

	/**
	 * Update a saved view by id and scope.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function update_view( $request ): WP_REST_Response {
		$raw_id    = $request->get_param( 'id' );
		$raw_scope = $request->get_param( 'scope' );
		$raw_patch = $request->get_param( 'patch' );
		$id        = is_scalar( $raw_id ) ? (string) $raw_id : '';
		$scope     = is_scalar( $raw_scope ) ? (string) $raw_scope : 'personal';
		$patch     = $this->to_string_keyed_array( $raw_patch );

		if ( 'global' === $scope ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
			}
			$updated = $this->repo->update_global( $id, $patch );
			if ( null === $updated ) {
				return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
			}
			$updated['scope'] = 'global';
			return new WP_REST_Response( array( 'view' => $updated ), 200 );
		}

		$updated = $this->repo->update_personal( get_current_user_id(), $id, $patch );
		if ( null === $updated ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}
		$updated['scope'] = 'personal';
		return new WP_REST_Response( array( 'view' => $updated ), 200 );
	}

	/**
	 * Delete a saved view by id and scope.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function delete_view( $request ): WP_REST_Response {
		$raw_id    = $request->get_param( 'id' );
		$raw_scope = $request->get_param( 'scope' );
		$id        = is_scalar( $raw_id ) ? (string) $raw_id : '';
		$scope     = is_scalar( $raw_scope ) ? (string) $raw_scope : 'personal';

		if ( 'global' === $scope ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
			}
			$ok = $this->repo->delete_global( $id );
			return new WP_REST_Response(
				array( 'deleted' => $ok ),
				$ok ? 200 : 404
			);
		}

		$ok = $this->repo->delete_personal( get_current_user_id(), $id );
		return new WP_REST_Response(
			array( 'deleted' => $ok ),
			$ok ? 200 : 404
		);
	}

	/**
	 * Build a string-keyed view array from request params for save operations.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array<string, mixed>
	 */
	private function build_view_array( $request ): array {
		$raw_name = $request->get_param( 'name' );
		$raw_sort = $request->get_param( 'sort' );
		$filters  = $request->get_param( 'filters' );
		$columns  = $request->get_param( 'columns' );
		return array(
			'name'    => is_scalar( $raw_name ) ? (string) $raw_name : '',
			'filters' => is_array( $filters ) ? $this->to_string_keyed_array( $filters ) : array(),
			'sort'    => is_scalar( $raw_sort ) ? (string) $raw_sort : 'last_reply_desc',
			'columns' => is_array( $columns ) ? array_values( $columns ) : array(),
		);
	}

	/**
	 * Narrow an array to string keys, dropping non-string keys, and sanitize
	 * scalar leaf values. Only the `name` and `sort` keys carry user-visible
	 * strings and are sanitized with sanitize_text_field(); structural values
	 * (filters, columns) are kept as-is but limited to scalar children.
	 *
	 * @param mixed $value Incoming value (expected to be array).
	 * @return array<string, mixed>
	 */
	private function to_string_keyed_array( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $k => $v ) {
			if ( ! is_string( $k ) ) {
				continue;
			}
			// Sanitize the user-facing name and sort key fields.
			if ( in_array( $k, array( 'name', 'sort' ), true ) ) {
				$out[ $k ] = sanitize_text_field( is_scalar( $v ) ? (string) $v : '' );
			} elseif ( is_array( $v ) ) {
				// Recurse one level for filters/columns (no deeper nesting expected).
				$out[ $k ] = $this->to_string_keyed_array( $v );
			} elseif ( is_scalar( $v ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}
}

add_action(
	'rest_api_init',
	static function (): void {
		( new ViewsController() )->register_routes();
	}
);
