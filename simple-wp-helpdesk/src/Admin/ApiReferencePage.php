<?php
declare(strict_types=1);

namespace SWH\Admin;

use function add_action;
use function add_submenu_page;
use function admin_url;
use function esc_attr;
use function esc_html__;
use function esc_url;
use function plugins_url;
use function rest_url;
use function wp_enqueue_script;

/**
 * Admin → Tools → Helpdesk API Reference page.
 *
 * Phase 8 of the v4.1.0 plan (#368). Renders the OpenAPI 3.1 spec served by
 * {@see \SWH\Rest\V1\OpenApiController} using a vendored Redoc bundle (no CDN
 * dependency — per security-model.md "No third-party runtime fetches").
 *
 * **Capability gate.** `manage_options` only. Operators who can read the
 * admin can already see the spec via the public site / JSON endpoint, but
 * Tools is the discoverability surface.
 *
 * **Conditional enqueue.** The 940KB Redoc bundle is enqueued ONLY on this
 * page (`tools_page_swh-api-reference`) — never on the rest of wp-admin.
 *
 * @since 4.1.0
 */
final class ApiReferencePage {

	/** Submenu slug under Tools. */
	public const SLUG = 'swh-api-reference';

	/** Computed admin screen ID for the conditional enqueue. */
	public const SCREEN_ID = 'tools_page_swh-api-reference';

	/**
	 * Wire up the admin menu + asset enqueue hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Add the Tools → Helpdesk API Reference submenu.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'tools.php',
			esc_html__( 'Helpdesk API Reference', 'simple-wp-helpdesk' ),
			esc_html__( 'Helpdesk API Reference', 'simple-wp-helpdesk' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Enqueue the vendored Redoc bundle ONLY on our page.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function maybe_enqueue_assets( string $hook ): void {
		if ( self::SCREEN_ID !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'swh-redoc',
			plugins_url( 'assets/vendor/redoc/redoc.standalone.js', SWH_PLUGIN_FILE ),
			array(),
			'2.5.2',
			true
		);
	}

	/**
	 * Render the page shell — Redoc element targeting the public JSON endpoint.
	 *
	 * The spec URL is the public `swh/v1/openapi.json` endpoint. No nonce is
	 * required because the endpoint is intentionally public; Redoc fetches it
	 * the same way Postman would.
	 */
	public static function render(): void {
		$spec_url = esc_url( rest_url( 'swh/v1/openapi.json' ) );
		$json_url = $spec_url;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Helpdesk API Reference', 'simple-wp-helpdesk' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: anchor tag wrapping the JSON endpoint URL */
					esc_html__( 'Machine-readable OpenAPI 3.1 spec: %s', 'simple-wp-helpdesk' ),
					'<a href="' . esc_url( $json_url ) . '" target="_blank" rel="noopener"><code>' . esc_html( $json_url ) . '</code></a>'
				);
				?>
			</p>
			<div id="swh-redoc" data-spec-url="<?php echo esc_attr( $spec_url ); ?>" style="background:#fff;min-height:80vh;"></div>
			<script>
				/* global Redoc */
				document.addEventListener( 'DOMContentLoaded', function () {
					var el = document.getElementById( 'swh-redoc' );
					if ( ! el || typeof Redoc === 'undefined' ) {
						return;
					}
					Redoc.init( el.getAttribute( 'data-spec-url' ), {
						hideDownloadButton: false,
						scrollYOffset: 32,
						theme: { colors: { primary: { main: '#2271b1' } } }
					}, el );
				} );
			</script>
		</div>
		<?php
	}
}
