<?php
/**
 * Reporting dashboard UI: submenu page registration and report page render.
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'swh_register_reports_submenu' );
/**
 * Registers the Reports submenu page under the Tickets CPT menu.
 *
 * @since 3.0.0
 * @return void
 */
function swh_register_reports_submenu() {
	add_submenu_page(
		'edit.php?post_type=helpdesk_ticket',
		__( 'Helpdesk Reports', 'simple-wp-helpdesk' ),
		__( 'Reports', 'simple-wp-helpdesk' ),
		'manage_options',
		'swh-reports',
		'swh_render_reports_page'
	);
}

add_action( 'admin_enqueue_scripts', 'swh_enqueue_reporting_assets' );
/**
 * Enqueues Chart.js and inline report JS on the Reports admin page.
 *
 * @since 3.0.0
 * @param string $hook The current admin page hook suffix.
 * @return void
 */
function swh_enqueue_reporting_assets( $hook ) {
	if ( 'helpdesk_ticket_page_swh-reports' !== $hook ) {
		return;
	}
	wp_enqueue_script(
		'swh-chartjs',
		'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js',
		array(),
		'4',
		true
	);
	wp_enqueue_script(
		'swh-reports',
		SWH_PLUGIN_URL . 'assets/swh-reports.js',
		array( 'swh-chartjs' ),
		SWH_VERSION,
		true
	);
	wp_localize_script(
		'swh-reports',
		'swhReports',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'swh_report_data' ),
		)
	);
}

/**
 * Renders the Reports admin page with Chart.js canvas placeholders.
 *
 * @since 3.0.0
 * @return void
 */
function swh_render_reports_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'simple-wp-helpdesk' ) );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Helpdesk Reports', 'simple-wp-helpdesk' ); ?></h1>
		<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:20px;">
			<div style="background:#fff; border:1px solid #ddd; border-radius:4px; padding:20px;">
				<h2><?php esc_html_e( 'Tickets by Status', 'simple-wp-helpdesk' ); ?></h2>
				<canvas id="swh-chart-status" height="200"></canvas>
			</div>
			<div style="background:#fff; border:1px solid #ddd; border-radius:4px; padding:20px;">
				<h2><?php esc_html_e( 'Weekly Trend (8 Weeks)', 'simple-wp-helpdesk' ); ?></h2>
				<canvas id="swh-chart-trend" height="200"></canvas>
			</div>
			<div style="background:#fff; border:1px solid #ddd; border-radius:4px; padding:20px;">
				<h2><?php esc_html_e( 'Avg. Resolution Time (30 Days)', 'simple-wp-helpdesk' ); ?></h2>
				<p id="swh-avg-resolution" style="font-size:2em; font-weight:bold; text-align:center;">&mdash;</p>
			</div>
			<div style="background:#fff; border:1px solid #ddd; border-radius:4px; padding:20px;">
				<h2><?php esc_html_e( 'Avg. First Response Time (30 Days)', 'simple-wp-helpdesk' ); ?></h2>
				<p id="swh-avg-first-response" style="font-size:2em; font-weight:bold; text-align:center;">&mdash;</p>
			</div>
		</div>
	</div>
	<?php
}
