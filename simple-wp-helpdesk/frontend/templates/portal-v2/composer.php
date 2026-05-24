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
	<ul class="swh-portal-v2-attachment-list" hidden></ul>
	<div class="swh-portal-v2-composer-error" role="alert" aria-live="polite" hidden></div>
	<div class="swh-portal-v2-composer-toolbar">
		<?php
		$max_mb    = swh_get_int_option( 'swh_max_upload_size', 5 );
		$max_count = swh_get_int_option( 'swh_max_upload_count', 5 );
		/* translators: 1: max megabytes, 2: max file count */
		$attach_hint = sprintf( __( 'JPG, PNG, GIF, PDF, DOC, DOCX, TXT — up to %1$dMB each, max %2$d files', 'simple-wp-helpdesk' ), $max_mb, $max_count );
		?>
		<label class="swh-portal-v2-attach" title="<?php echo esc_attr( $attach_hint ); ?>">
			📎
			<input type="file"
				name="attachments[]"
				multiple
				hidden
				accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt,image/jpeg,image/png,image/gif,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain"
				data-max-mb="<?php echo esc_attr( (string) $max_mb ); ?>"
				data-max-count="<?php echo esc_attr( (string) $max_count ); ?>" />
		</label>
		<span class="swh-portal-v2-attach-hint"><?php echo esc_html( $attach_hint ); ?></span>
		<button type="submit" class="swh-portal-v2-send" aria-label="<?php esc_attr_e( 'Send reply', 'simple-wp-helpdesk' ); ?>">→</button>
	</div>
</form>
