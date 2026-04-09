<?php
/**
 * Client portal view: renders the single-ticket portal with reply, close, and reopen forms.
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the client portal view for a single ticket.
 *
 * Called from the router (swh_ticket_frontend or swh_helpdesk_portal_shortcode)
 * inside an ob_start() context. Echoes HTML directly; the caller handles
 * ob_get_clean(). Validates the ticket token via hash_equals() before rendering.
 *
 * @return void
 */
function swh_render_client_portal() {
	$defs            = swh_get_defaults();
	$closed_status   = get_option( 'swh_closed_status', $defs['swh_closed_status'] );
	$resolved_status = get_option( 'swh_resolved_status', $defs['swh_resolved_status'] );
	$reopened_status = get_option( 'swh_reopened_status', $defs['swh_reopened_status'] );
	$default_status  = get_option( 'swh_default_status', $defs['swh_default_status'] );
	$spam_method     = get_option( 'swh_spam_method', 'none' );

	// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Token-based auth; params verified via hash_equals() below; isset() checked by callers.
	$ticket_id = absint( $_GET['swh_ticket'] );
	$token     = sanitize_text_field( wp_unslash( $_GET['token'] ) );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	$post     = get_post( $ticket_id );
	$db_token = get_post_meta( $ticket_id, '_ticket_token', true );

	if ( ! $post || 'helpdesk_ticket' !== $post->post_type || ! hash_equals( $db_token, $token ) ) {
		echo '<div class="swh-helpdesk-wrapper">';
		echo '<div class="swh-alert swh-alert-error">' . esc_html( get_option( 'swh_msg_err_invalid', $defs['swh_msg_err_invalid'] ) ) . '</div>';
		echo '</div>';
		return;
	}

	if ( swh_is_token_expired( $ticket_id ) ) {
		echo '<div class="swh-helpdesk-wrapper">';
		echo '<div class="swh-alert swh-alert-error">' . esc_html( get_option( 'swh_msg_err_expired', $defs['swh_msg_err_expired'] ) ) . '</div>';
		echo '</div>';
		return;
	}

	$data = array(
		'name'       => get_post_meta( $ticket_id, '_ticket_name', true ) ? get_post_meta( $ticket_id, '_ticket_name', true ) : 'Client',
		'email'      => get_post_meta( $ticket_id, '_ticket_email', true ),
		'ticket_id'  => get_post_meta( $ticket_id, '_ticket_uid', true ),
		'title'      => $post->post_title,
		'status'     => get_post_meta( $ticket_id, '_ticket_status', true ),
		'priority'   => get_post_meta( $ticket_id, '_ticket_priority', true ),
		'ticket_url' => swh_get_secure_ticket_link( $ticket_id ),
		'admin_url'  => admin_url( 'post.php?post=' . $ticket_id . '&action=edit' ),
		'message'    => '',
	);

	// Rate-limit frontend POST actions to one per 30 seconds per action + ticket + IP.
	// Each action type has its own key so that closing a ticket does not block the
	// immediate reopen attempt (and vice-versa).
	$is_close       = isset( $_POST['swh_user_close_ticket_submit'] );
	$is_reopen      = isset( $_POST['swh_user_reopen_submit'] );
	$is_reply       = isset( $_POST['swh_user_reply_submit'] );
	$is_post_action = $is_close || $is_reopen || $is_reply;
	if ( $is_post_action ) {
		$rl_action = $is_close ? 'portal_close_' : ( $is_reopen ? 'portal_reopen_' : 'portal_reply_' );
		if ( swh_is_rate_limited( $rl_action . $ticket_id, 30 ) ) {
			echo '<div class="swh-alert swh-alert-error">' . esc_html__( 'Please wait a moment before submitting again.', 'simple-wp-helpdesk' ) . '</div>';
			$is_post_action = false;
		}
	}

	if ( $is_post_action && isset( $_POST['swh_user_close_ticket_submit'], $_POST['swh_close_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_close_nonce'] ) ), 'swh_user_close' ) ) {
		update_post_meta( $ticket_id, '_ticket_status', $closed_status );
		delete_post_meta( $ticket_id, '_resolved_timestamp' );
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $ticket_id,
				'comment_author'       => $data['name'],
				'comment_author_email' => $data['email'],
				'comment_content'      => __( 'TICKET CLOSED BY CLIENT', 'simple-wp-helpdesk' ),
				'comment_approved'     => 1,
				'comment_type'         => 'helpdesk_reply',
			)
		);
		if ( $comment_id ) {
			update_comment_meta( $comment_id, '_is_user_reply', '1' );
		}
		$admin_email = swh_get_admin_email( $ticket_id );
		swh_send_email( $admin_email, 'swh_em_admin_closed_sub', 'swh_em_admin_closed_body', $data );
		swh_send_email( $data['email'], 'swh_em_user_closed_sub', 'swh_em_user_closed_body', $data );
		echo '<div class="swh-alert swh-alert-success">' . esc_html( get_option( 'swh_msg_success_closed', $defs['swh_msg_success_closed'] ) ) . '</div>';
		$data['status'] = $closed_status;
	} elseif ( $is_post_action && isset( $_POST['swh_user_reopen_submit'], $_POST['swh_reopen_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_reopen_nonce'] ) ), 'swh_user_reopen' ) ) {
		if ( swh_check_antispam( false ) ) {
			echo '<div class="swh-alert swh-alert-error">' . esc_html( get_option( 'swh_msg_err_spam', $defs['swh_msg_err_spam'] ) ) . '</div>';
		} else {
			$reply_text = isset( $_POST['ticket_reopen_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_reopen_text'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$has_files = ! empty( $_FILES['swh_reopen_attachments']['name'][0] );
			update_post_meta( $ticket_id, '_ticket_status', $reopened_status );
			delete_post_meta( $ticket_id, '_resolved_timestamp' );
			if ( $reply_text ) {
				$comment_content = __( 'TICKET RE-OPENED:', 'simple-wp-helpdesk' ) . " \n" . $reply_text;
			} elseif ( $has_files ) {
				$comment_content = __( 'TICKET RE-OPENED:', 'simple-wp-helpdesk' ) . ' ' . __( 'Attached file(s)', 'simple-wp-helpdesk' );
			} else {
				$comment_content = __( 'TICKET RE-OPENED BY CLIENT', 'simple-wp-helpdesk' );
			}
			$comment_id = wp_insert_comment(
				array(
					'comment_post_ID'      => $ticket_id,
					'comment_author'       => $data['name'],
					'comment_author_email' => $data['email'],
					'comment_content'      => $comment_content,
					'comment_approved'     => 1,
					'comment_type'         => 'helpdesk_reply',
				)
			);
			if ( $comment_id ) {
				update_comment_meta( $comment_id, '_is_user_reply', '1' );
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$attach_urls = swh_handle_multiple_uploads( $_FILES['swh_reopen_attachments'] );
			if ( $comment_id && ! empty( $attach_urls ) ) {
				update_comment_meta( $comment_id, '_attachments', $attach_urls );
			}
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- dynamic fallback intentional
			$data['message'] = $reply_text ? $reply_text : ( $has_files ? __( 'Attached file(s)', 'simple-wp-helpdesk' ) : '' );
			$admin_email     = swh_get_admin_email( $ticket_id );
			$proxy_urls      = array_map(
				function ( $u ) use ( $ticket_id ) {
					return swh_get_file_proxy_url( $u, $ticket_id );
				},
				$attach_urls
			);
			swh_send_email( $admin_email, 'swh_em_admin_reopen_sub', 'swh_em_admin_reopen_body', $data, $proxy_urls );
			swh_send_email( $data['email'], 'swh_em_user_reopen_sub', 'swh_em_user_reopen_body', $data );
			echo '<div class="swh-alert swh-alert-success">' . esc_html( get_option( 'swh_msg_success_reopen', $defs['swh_msg_success_reopen'] ) ) . '</div>';
			$data['status'] = $reopened_status;
		} // end anti-spam else
	} elseif ( $is_post_action && isset( $_POST['swh_user_reply_submit'], $_POST['swh_reply_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_reply_nonce'] ) ), 'swh_user_reply' ) ) {
		if ( swh_check_antispam( true ) ) {
			echo '<div class="swh-alert swh-alert-error">' . esc_html( get_option( 'swh_msg_err_spam', $defs['swh_msg_err_spam'] ) ) . '</div>';
		} else {
			$reply_text = isset( $_POST['ticket_reply_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_reply_text'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$has_files = ! empty( $_FILES['swh_user_reply_attachments']['name'][0] );
			if ( $reply_text || $has_files ) {
				if ( $resolved_status === $data['status'] ) {
					$data['status'] = $reopened_status;
					update_post_meta( $ticket_id, '_ticket_status', $reopened_status );
					delete_post_meta( $ticket_id, '_resolved_timestamp' );
				}
				$comment_content = $reply_text ? $reply_text : __( 'Attached file(s)', 'simple-wp-helpdesk' );
				$comment_id      = wp_insert_comment(
					array(
						'comment_post_ID'      => $ticket_id,
						'comment_author'       => $data['name'],
						'comment_author_email' => $data['email'],
						'comment_content'      => $comment_content,
						'comment_approved'     => 1,
						'comment_type'         => 'helpdesk_reply',
					)
				);
				if ( $comment_id ) {
					update_comment_meta( $comment_id, '_is_user_reply', '1' );
				}
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$attach_urls = swh_handle_multiple_uploads( $_FILES['swh_user_reply_attachments'] );
				if ( $comment_id && ! empty( $attach_urls ) ) {
					update_comment_meta( $comment_id, '_attachments', $attach_urls );
				}
				$data['message'] = $reply_text ? $reply_text : __( 'Attached file(s)', 'simple-wp-helpdesk' );
				$admin_email     = swh_get_admin_email( $ticket_id );
				$proxy_urls      = array_map(
					function ( $u ) use ( $ticket_id ) {
						return swh_get_file_proxy_url( $u, $ticket_id );
					},
					$attach_urls
				);
				swh_send_email( $admin_email, 'swh_em_admin_reply_sub', 'swh_em_admin_reply_body', $data, $proxy_urls );
				echo '<div class="swh-alert swh-alert-success">' . esc_html( get_option( 'swh_msg_success_reply', $defs['swh_msg_success_reply'] ) ) . '</div>';
			}
		} // end anti-spam else
	}
	?>
	<div class="swh-helpdesk-wrapper">
	<div class="swh-card">
		<div style="float: right; font-weight: bold; color: #666; font-size: 1.2em;"><?php echo esc_html( $data['ticket_id'] ); ?></div>
		<h3 style="margin-top:0; font-size: 22px; color: #222;"><?php echo esc_html( $data['title'] ); ?></h3>
		<p style="margin: 0 0 15px 0;"><strong><?php esc_html_e( 'Status:', 'simple-wp-helpdesk' ); ?></strong> <span class="swh-badge <?php echo ( $closed_status === $data['status'] ) ? 'swh-badge-closed' : 'swh-badge-open'; ?>"><?php echo esc_html( $data['status'] ); ?></span>
		&nbsp;|&nbsp; <strong><?php esc_html_e( 'Priority:', 'simple-wp-helpdesk' ); ?></strong> <?php echo esc_html( $data['priority'] ); ?></p>
		<hr>
		<p><?php echo nl2br( esc_html( $post->post_content ) ); ?></p>
		<?php
		$attachments = get_post_meta( $ticket_id, '_ticket_attachments', true );
		if ( ! empty( $attachments ) && is_array( $attachments ) ) {
			echo '<p><strong>' . esc_html__( 'Attachments:', 'simple-wp-helpdesk' ) . '</strong><br>';
			foreach ( $attachments as $url ) {
				echo '<a href="' . esc_url( swh_get_file_proxy_url( $url, $ticket_id ) ) . '" target="_blank" style="text-decoration: underline; margin-right:10px; color:#0073aa;">' . esc_html( basename( $url ) ) . '</a>'; // nosemgrep -- $url from post meta (not $_REQUEST); esc_url() + esc_html() applied.
			}
			echo '</p>';
		}
		?>
	</div>
	<h4 style="margin-bottom: 15px;"><?php esc_html_e( 'Conversation History', 'simple-wp-helpdesk' ); ?></h4>
	<div style="margin-bottom: 20px;">
	<?php
	$comments = get_comments(
		array(
			'post_id' => $ticket_id,
			'order'   => 'ASC',
			'type'    => 'helpdesk_reply',
		)
	);
	if ( $comments ) {
		foreach ( $comments as $comment ) {
			if ( get_comment_meta( $comment->comment_ID, '_is_internal_note', true ) ) {
				continue;
			}
			$is_user = get_comment_meta( $comment->comment_ID, '_is_user_reply', true );
			/* translators: %s: technician name */
			$author_name  = $is_user ? __( 'You', 'simple-wp-helpdesk' ) : sprintf( __( 'Technician (%s)', 'simple-wp-helpdesk' ), $comment->comment_author );
			$bubble_class = $is_user ? 'swh-chat-user' : 'swh-chat-tech';
			$attach_urls  = get_comment_meta( $comment->comment_ID, '_attachments', true );

			echo '<div class="swh-chat-bubble ' . esc_attr( $bubble_class ) . '">';
			echo '<strong style="display:block; margin-bottom: 5px;">' . esc_html( $author_name ) . ' <span style="font-weight:normal; font-size: 0.85em; color: #777;">(' . esc_html( $comment->comment_date ) . ')</span></strong>';
			echo nl2br( esc_html( $comment->comment_content ) );
			if ( ! empty( $attach_urls ) && is_array( $attach_urls ) ) {
				echo '<div style="margin-top: 10px;">';
				foreach ( $attach_urls as $url ) {
					echo '<a href="' . esc_url( swh_get_file_proxy_url( $url, $ticket_id ) ) . '" target="_blank" style="text-decoration: underline; margin-right:10px; color:#0073aa; font-size:13px;">' . esc_html( basename( $url ) ) . '</a>'; // nosemgrep -- $url from comment meta (not $_REQUEST); esc_url() + esc_html() applied.
				}
				echo '</div>';
			}
			echo '</div>';
		}
	} else {
		echo '<p>' . esc_html__( 'No replies yet.', 'simple-wp-helpdesk' ) . '</p>';
	}
	?>
	</div>
	<?php if ( $resolved_status === $data['status'] ) : ?>
		<div class="swh-alert swh-alert-info">
			<div>
				<h4 style="margin: 0 0 5px 0; color: #005980;"><?php esc_html_e( 'Is your issue fully resolved?', 'simple-wp-helpdesk' ); ?></h4>
				<p style="margin: 0; font-size: 13px; color: #005980;"><?php esc_html_e( 'Click the button to close this ticket, or use the form below to reply if you still need help.', 'simple-wp-helpdesk' ); ?></p>
			</div>
			<form method="POST" action="" style="margin:0;">
				<?php wp_nonce_field( 'swh_user_close', 'swh_close_nonce' ); ?>
				<input type="submit" name="swh_user_close_ticket_submit" value="<?php esc_attr_e( 'Yes, Close Ticket', 'simple-wp-helpdesk' ); ?>" class="swh-btn">
			</form>
		</div>
	<?php endif; ?>
	<?php if ( $closed_status !== $data['status'] ) : ?>
		<form method="POST" action="" enctype="multipart/form-data">
			<?php wp_nonce_field( 'swh_user_reply', 'swh_reply_nonce' ); ?>
			<div class="swh-form-group">
				<label><?php esc_html_e( 'Add a Reply:', 'simple-wp-helpdesk' ); ?></label>
				<textarea name="ticket_reply_text" rows="4" class="swh-form-control"></textarea>
			</div>
			<div class="swh-form-group">
				<label><?php esc_html_e( 'Attach Files (Optional):', 'simple-wp-helpdesk' ); ?></label>
				<input type="file" name="swh_user_reply_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" class="swh-form-control swh-file-input">
				<small style="color:#666; display:block; margin-top:5px;">
				<?php
					/* translators: 1: max upload size in MB, 2: max file count */
					printf( esc_html__( 'Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT. Max size: %1$sMB per file. Max files: %2$s.', 'simple-wp-helpdesk' ), esc_html( get_option( 'swh_max_upload_size', 5 ) ), esc_html( get_option( 'swh_max_upload_count', 5 ) ) );
				?>
				</small>
			</div>
			<?php
			if ( 'honeypot' === $spam_method ) {
				echo '<div style="position: absolute; left: -9999px;"><label>Leave this empty</label><input type="text" name="swh_website_url_hp" value="" tabindex="-1" autocomplete="off"></div>';
			} elseif ( 'recaptcha' === $spam_method ) {
				$key = get_option( 'swh_recaptcha_site_key' );
				echo '<div id="swh-recaptcha-reply" style="margin-bottom: 15px;"></div>';
				// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External CDN script; null version is intentional.
				wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js?onload=swhRecaptchaLoad&render=explicit', array(), null, true );
				wp_add_inline_script( 'google-recaptcha', 'window.swhRecaptchaLoad = function() { document.querySelectorAll("[id^=swh-recaptcha-]").forEach(function(el) { if(window.grecaptcha && !el.hasChildNodes()) { grecaptcha.render(el.id, {"sitekey": "' . esc_js( $key ) . '"}); } }); };', 'before' );
			} elseif ( 'turnstile' === $spam_method ) {
				$key = get_option( 'swh_turnstile_site_key' );
				echo '<div id="swh-turnstile-reply" style="margin-bottom: 15px;"></div>';
				// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External CDN script; null version is intentional.
				wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=swhTurnstileLoad&render=explicit', array(), null, true );
				wp_add_inline_script( 'cf-turnstile', 'window.swhTurnstileLoad = function() { document.querySelectorAll("[id^=swh-turnstile-]").forEach(function(el) { if(window.turnstile && !el.hasChildNodes()) { turnstile.render("#" + el.id, {sitekey: "' . esc_js( $key ) . '"}); } }); };', 'before' );
			}
			?>
			<div class="swh-form-group">
				<input type="submit" name="swh_user_reply_submit" value="<?php esc_attr_e( 'Send Reply', 'simple-wp-helpdesk' ); ?>" class="swh-btn">
			</div>
		</form>
	<?php else : ?>
		<div class="swh-alert swh-alert-error">
			<p style="margin-top: 0; font-weight: bold;"><?php esc_html_e( 'This ticket is closed.', 'simple-wp-helpdesk' ); ?></p>
			<form method="POST" action="" enctype="multipart/form-data">
				<?php wp_nonce_field( 'swh_user_reopen', 'swh_reopen_nonce' ); ?>
				<div class="swh-form-group">
					<label><?php esc_html_e( 'Explain why you need this re-opened:', 'simple-wp-helpdesk' ); ?></label>
					<textarea name="ticket_reopen_text" rows="3" class="swh-form-control"></textarea>
				</div>
				<div class="swh-form-group">
					<label><?php esc_html_e( 'Attach Files (Optional):', 'simple-wp-helpdesk' ); ?></label>
					<input type="file" name="swh_reopen_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" class="swh-form-control swh-file-input">
					<small style="color:#721c24; display:block; margin-top:5px;">
					<?php
						/* translators: 1: max upload size in MB, 2: max file count */
						printf( esc_html__( 'Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT. Max size: %1$sMB. Max files: %2$s.', 'simple-wp-helpdesk' ), esc_html( get_option( 'swh_max_upload_size', 5 ) ), esc_html( get_option( 'swh_max_upload_count', 5 ) ) );
					?>
					</small>
				</div>
				<?php if ( 'honeypot' === $spam_method ) : ?>
					<div style="position: absolute; left: -9999px;"><label>Leave this empty</label><input type="text" name="swh_website_url_hp" value="" tabindex="-1" autocomplete="off"></div>
				<?php endif; ?>
				<div class="swh-form-group">
					<input type="submit" name="swh_user_reopen_submit" value="<?php esc_attr_e( 'Re-open Ticket', 'simple-wp-helpdesk' ); ?>" class="swh-btn swh-btn-danger">
				</div>
			</form>
		</div>
	<?php endif; ?>
	</div> <!-- End .swh-helpdesk-wrapper -->
	<?php
}
