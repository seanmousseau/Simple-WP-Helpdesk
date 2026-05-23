<?php
/**
 * Portal v2 thread container. Calls swh_portal_render_thread() to fill it.
 *
 * Variables in scope: $ticket_id (int).
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<main class="swh-portal-v2-thread" id="swh-portal-thread" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Conversation thread', 'simple-wp-helpdesk' ); ?>">
	<?php echo swh_portal_render_thread( (int) $ticket_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes per-field via esc_html() ?>
</main>
