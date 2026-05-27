<?php
declare(strict_types=1);

namespace SWH\Rest\V1;

use SWH\Rest\Bootstrap;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Term;

use function get_terms;
use function register_rest_route;
use function swh_rest_require;

/**
 * Public REST controller for `swh/v1/categories`.
 *
 * Phase 5 of the v4.1.0 plan (#364). Read-only listing of the
 * `helpdesk_category` taxonomy terms.
 *
 * **Allowlist response.** Locked shape: `{ id, name, slug, parent, count }`.
 * Term meta and description are intentionally excluded — adding a field is
 * a public-API change.
 *
 * Route:
 *  - GET /swh/v1/categories — list helpdesk categories.
 *
 * @since 4.1.0
 */
final class CategoriesController extends WP_REST_Controller {

	public const REST_BASE = 'categories';

	public function __construct() {
		$this->namespace = Bootstrap::NAMESPACE;
		$this->rest_base = self::REST_BASE;
	}

	/**
	 * Register the `/categories` route.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
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
	 * Locked item schema for a category term.
	 *
	 * @return array<string,mixed>
	 */
	public function get_item_schema(): array {
		if ( null !== $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'category',
			'type'       => 'object',
			'properties' => array(
				'id'     => array(
					'description' => 'Term ID.',
					'type'        => 'integer',
					'readonly'    => true,
				),
				'name'   => array(
					'description' => 'Category display name.',
					'type'        => 'string',
				),
				'slug'   => array(
					'description' => 'Category slug.',
					'type'        => 'string',
				),
				'parent' => array(
					'description' => 'Parent term ID (0 for top-level).',
					'type'        => 'integer',
				),
				'count'  => array(
					'description' => 'Number of tickets in this category.',
					'type'        => 'integer',
				),
			),
		);
		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * GET /swh/v1/categories.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function get_items( $request ): WP_REST_Response {
		unset( $request );

		$terms = get_terms(
			array(
				'taxonomy'   => 'helpdesk_category',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		$items = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( $t instanceof WP_Term ) {
					$items[] = self::from_term( $t );
				}
			}
		}
		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * Project a WP_Term into the locked category shape.
	 *
	 * @return array<string,mixed>
	 */
	private static function from_term( WP_Term $term ): array {
		return array(
			'id'     => (int) $term->term_id,
			'name'   => (string) $term->name,
			'slug'   => (string) $term->slug,
			'parent' => (int) $term->parent,
			'count'  => (int) $term->count,
		);
	}
}

// Register the controller with the v4.1.0 namespace bootstrap. Bootstrap's
// own rest_api_init listener (priority 10) dispatches register_routes() on
// every queued controller.
Bootstrap::register_controller( new CategoriesController() );
