<?php
/**
 * Plugin action links shown under the plugin name on the Plugins list page.
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'plugin_action_links_' . plugin_basename( SWH_PLUGIN_FILE ), 'swh_plugin_action_links' );

/**
 * Appends Settings and Documentation links under the plugin name on the Plugins page.
 *
 * @param string[] $links Existing action links.
 * @return string[]
 */
function swh_plugin_action_links( array $links ): array {
	$settings_url = admin_url( 'edit.php?post_type=helpdesk_ticket&page=swh-settings' );
	$docs_url     = 'https://seanmousseau.github.io/Simple-WP-Helpdesk/';
	array_unshift(
		$links,
		'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'simple-wp-helpdesk' ) . '</a>',
		'<a href="' . esc_url( $docs_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Docs', 'simple-wp-helpdesk' ) . '</a>'
	);
	return $links;
}
