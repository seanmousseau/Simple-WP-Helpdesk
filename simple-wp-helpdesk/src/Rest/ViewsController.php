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
					'name'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'scope'   => array( 'type' => 'string', 'default' => 'personal', 'enum' => array( 'personal', 'global' ) ),
					'filters' => array( 'type' => 'object', 'default' => array() ),
					'sort'    => array( 'type' => 'string', 'default' => 'last_reply_desc' ),
					'columns' => array( 'type' => 'array', 'default' => array() ),
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
					'id'    => array( 'type' => 'string', 'required' => true ),
					'scope' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'personal', 'global' ) ),
					'patch' => array( 'type' => 'object', 'required' => true ),
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
					'id'    => array( 'type' => 'string', 'required' => true ),
					'scope' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'personal', 'global' ) ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request REST request.
	 */
	public function permissions_check( WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( 'edit_posts' );
	}

	/**
	 * @param WP_REST_Request $request REST request.
	 */
	public function list_views( $request ): WP_REST_Response {
		unset( $request );
		$views = $this->repo->get_all_for_user( get_current_user_id() );
		return new WP_REST_Response( array( 'views' => $views ), 200 );
	}

	/**
	 * @param WP_REST_Request $request REST request.
	 */
	public function create_view( $request ): WP_REST_Response {
		$scope = (string) $request->get_param( 'scope' );
		$view  = array(
			'name'    => (string) $request->get_param( 'name' ),
			'filters' => (array) $request->get_param( 'filters' ),
			'sort'    => (string) $request->get_param( 'sort' ),
			'columns' => (array) $request->get_param( 'columns' ),
		);

		if ( 'global' === $scope ) {
			$saved = $this->repo->save_global( $view );
			if ( null === $saved ) {
				return new WP_REST_Response( array( 'error' => 'forbidden' ), 403 );
			}
			$saved['scope'] = 'global';
			return new WP_REST_Response( array( 'view' => $saved ), 201 );
		}

		$saved          = $this->repo->save_personal( get_current_user_id(), $view );
		$saved['scope'] = 'personal';
		return new WP_REST_Response( array( 'view' => $saved ), 201 );
	}

	/**
	 * @param WP_REST_Request $request REST request.
	 */
	public function update_view( $request ): WP_REST_Response {
		$id    = (string) $request->get_param( 'id' );
		$scope = (string) $request->get_param( 'scope' );
		$patch = (array) $request->get_param( 'patch' );

		if ( 'global' === $scope ) {
			$updated = $this->repo->update_global( $id, $patch );
			if ( null === $updated ) {
				return new WP_REST_Response( array( 'error' => 'forbidden_or_not_found' ), 403 );
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
	 * @param WP_REST_Request $request REST request.
	 */
	public function delete_view( $request ): WP_REST_Response {
		$id    = (string) $request->get_param( 'id' );
		$scope = (string) $request->get_param( 'scope' );

		if ( 'global' === $scope ) {
			$ok = $this->repo->delete_global( $id );
			return new WP_REST_Response(
				array( 'deleted' => $ok ),
				$ok ? 200 : 403
			);
		}

		$ok = $this->repo->delete_personal( get_current_user_id(), $id );
		return new WP_REST_Response(
			array( 'deleted' => $ok ),
			$ok ? 200 : 404
		);
	}
}

add_action(
	'rest_api_init',
	static function (): void {
		( new ViewsController() )->register_routes();
	}
);
