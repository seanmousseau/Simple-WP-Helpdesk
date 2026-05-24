<?php
/**
 * Portal v2 header — ticket id/title, status pills, ⋮ menu.
 *
 * Variables in scope: $ticket_id (int), $token (string), $title (string),
 *   $status (string), $priority (string), $is_closed (bool).
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<header class="swh-portal-v2-header">
	<div class="swh-portal-v2-header-titles">
		<h1 class="swh-portal-v2-title">#<?php echo esc_html( (string) $ticket_id ); ?> · <?php echo esc_html( (string) $title ); ?></h1>
		<div class="swh-portal-v2-status-pills">
			<span class="swh-pill swh-pill-status"><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $status ) ) ); ?></span>
			<?php if ( ! empty( $priority ) ) : ?>
				<span class="swh-pill swh-pill-priority"><?php echo esc_html( ucfirst( (string) $priority ) ); ?></span>
			<?php endif; ?>
		</div>
	</div>
	<div class="swh-portal-v2-menu-wrap">
		<button type="button"
				class="swh-portal-v2-menu-trigger"
				aria-label="<?php esc_attr_e( 'Ticket actions', 'simple-wp-helpdesk' ); ?>"
				aria-haspopup="true"
				aria-expanded="false">⋮</button>
		<ul class="swh-portal-v2-menu" hidden>
			<?php if ( ! $is_closed ) : ?>
				<li><button type="button" data-action="close"><?php esc_html_e( 'Close ticket', 'simple-wp-helpdesk' ); ?></button></li>
			<?php else : ?>
				<li><button type="button" data-action="reopen"><?php esc_html_e( 'Reopen ticket', 'simple-wp-helpdesk' ); ?></button></li>
			<?php endif; ?>
			<?php // CSAT inline prompt not implemented in v4.0; v4.1 will add stars UI. Hidden until then. ?>
		</ul>
	</div>
</header>
