<?php
/**
 * Single Portal v2 message bubble (rendered inside swh_portal_render_thread()).
 *
 * Variables in scope: $msg array with id, author, body, date_gmt, is_staff.
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array{id:int,author:string,body:string,date_gmt:string,is_staff:bool} $msg */
$cls       = $msg['is_staff'] ? 'swh-msg-staff' : 'swh-msg-customer';
$ts_attr   = ! empty( $msg['date_gmt'] ) ? $msg['date_gmt'] . 'Z' : '';
$ts_text   = ! empty( $msg['date_gmt'] ) ? esc_html( $msg['date_gmt'] ) : '';
$body_html = nl2br( esc_html( (string) $msg['body'] ) );
?>
<article class="swh-msg <?php echo esc_attr( $cls ); ?>" data-message-id="<?php echo esc_attr( (string) $msg['id'] ); ?>">
	<div class="swh-msg-bubble"><?php echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via esc_html() then nl2br ?></div>
	<div class="swh-msg-meta">
		<strong><?php echo esc_html( (string) $msg['author'] ); ?></strong>
		<?php if ( $msg['is_staff'] ) : ?>
			<span class="swh-msg-tag"><?php esc_html_e( 'staff', 'simple-wp-helpdesk' ); ?></span>
		<?php endif; ?>
		· <time datetime="<?php echo esc_attr( $ts_attr ); ?>" class="swh-msg-time"><?php echo $ts_text; ?></time>
	</div>
</article>
