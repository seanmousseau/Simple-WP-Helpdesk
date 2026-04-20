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
	wp_enqueue_style( 'swh-shared', SWH_PLUGIN_URL . 'assets/swh-shared.css', array(), SWH_VERSION );
	wp_enqueue_style( 'swh-admin', SWH_PLUGIN_URL . 'assets/swh-admin.css', array( 'swh-shared' ), SWH_VERSION );
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
		<div class="swh-kpi-grid" aria-label="<?php esc_attr_e( 'Key performance indicators', 'simple-wp-helpdesk' ); ?>">
			<div class="swh-kpi-card">
				<svg class="swh-kpi-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4C2.9 4 2 4.9 2 6v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4v-6h16v6zM4 8V6h16v2H4z"/></svg>
				<p class="swh-kpi-value" id="swh-kpi-total">&mdash;</p>
				<p class="swh-kpi-label"><?php esc_html_e( 'Total Tickets', 'simple-wp-helpdesk' ); ?></p>
			</div>
			<div class="swh-kpi-card swh-kpi-card--warning">
				<svg class="swh-kpi-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 15v-4H7l5-8v4h4l-5 8z"/></svg>
				<p class="swh-kpi-value" id="swh-kpi-open">&mdash;</p>
				<p class="swh-kpi-label"><?php esc_html_e( 'Open Tickets', 'simple-wp-helpdesk' ); ?></p>
			</div>
			<div class="swh-kpi-card swh-kpi-card--success">
				<svg class="swh-kpi-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2zm.75 14.5h-1.5V11h1.5v5.5zm0-7h-1.5V8h1.5v1.5z"/></svg>
				<p class="swh-kpi-value" id="swh-kpi-resolution">&mdash;</p>
				<p class="swh-kpi-label"><?php esc_html_e( 'Avg. Resolution (30d)', 'simple-wp-helpdesk' ); ?></p>
			</div>
			<div class="swh-kpi-card swh-kpi-card--success">
				<svg class="swh-kpi-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
				<p class="swh-kpi-value" id="swh-kpi-first-response">&mdash;</p>
				<p class="swh-kpi-label"><?php esc_html_e( 'Avg. First Response (30d)', 'simple-wp-helpdesk' ); ?></p>
			</div>
		</div>
		<div class="swh-report-grid">
			<div class="swh-report-card">
				<h2><?php esc_html_e( 'Tickets by Status', 'simple-wp-helpdesk' ); ?></h2>
				<canvas id="swh-chart-status" height="200"></canvas>
				<p id="swh-chart-status-empty" class="swh-chart-empty" hidden><?php esc_html_e( 'No ticket data yet.', 'simple-wp-helpdesk' ); ?></p>
			</div>
			<div class="swh-report-card">
				<h2><?php esc_html_e( 'Weekly Trend (8 Weeks)', 'simple-wp-helpdesk' ); ?></h2>
				<canvas id="swh-chart-trend" height="200"></canvas>
				<p id="swh-chart-trend-empty" class="swh-chart-empty" hidden><?php esc_html_e( 'No ticket data yet.', 'simple-wp-helpdesk' ); ?></p>
			</div>
			<div class="swh-report-card">
				<h2><?php esc_html_e( 'Avg. Resolution Time (30 Days)', 'simple-wp-helpdesk' ); ?></h2>
				<p id="swh-avg-resolution" class="swh-stat-value">&mdash;</p>
			</div>
			<div class="swh-report-card">
				<h2><?php esc_html_e( 'Avg. First Response Time (30 Days)', 'simple-wp-helpdesk' ); ?></h2>
				<p id="swh-avg-first-response" class="swh-stat-value">&mdash;</p>
			</div>
		</div>
	</div>
	<?php
}
