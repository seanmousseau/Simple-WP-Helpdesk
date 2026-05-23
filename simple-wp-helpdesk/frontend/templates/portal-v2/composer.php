<?php
/**
 * Portal v2 composer — pinned form at bottom.
 *
 * Variables in scope: $ticket_id (int), $token (string).
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form class="swh-portal-v2-composer" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="swh_portal_reply" />
	<input type="hidden" name="ticket_id" value="<?php echo esc_attr( (string) $ticket_id ); ?>" />
	<input type="hidden" name="token" value="<?php echo esc_attr( (string) $token ); ?>" />
	<?php wp_nonce_field( 'swh_portal_reply', 'swh_portal_reply_nonce' ); ?>

	<textarea name="reply_body"
				aria-label="<?php esc_attr_e( 'Reply', 'simple-wp-helpdesk' ); ?>"
				placeholder="<?php esc_attr_e( 'Type your reply… (Cmd+Enter to send)', 'simple-wp-helpdesk' ); ?>"
				rows="2"></textarea>
	<div class="swh-portal-v2-composer-toolbar">
		<label class="swh-portal-v2-attach" title="<?php esc_attr_e( 'Attach file', 'simple-wp-helpdesk' ); ?>">
			📎
			<input type="file" name="attachments[]" multiple hidden />
		</label>
		<button type="submit" class="swh-portal-v2-send" aria-label="<?php esc_attr_e( 'Send reply', 'simple-wp-helpdesk' ); ?>">→</button>
	</div>
</form>
