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
	$closed_status   = swh_get_string_option( 'swh_closed_status', is_string( $defs['swh_closed_status'] ) ? $defs['swh_closed_status'] : '' );
	$resolved_status = swh_get_string_option( 'swh_resolved_status', is_string( $defs['swh_resolved_status'] ) ? $defs['swh_resolved_status'] : '' );
	$reopened_status = swh_get_string_option( 'swh_reopened_status', is_string( $defs['swh_reopened_status'] ) ? $defs['swh_reopened_status'] : '' );
	$default_status  = swh_get_string_option( 'swh_default_status', is_string( $defs['swh_default_status'] ) ? $defs['swh_default_status'] : '' );
	$spam_method     = swh_get_string_option( 'swh_spam_method', 'none' );

	// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Token-based auth; params verified via hash_equals() below; isset() checked by callers.
	$ticket_id = isset( $_GET['swh_ticket'] ) && is_scalar( $_GET['swh_ticket'] ) ? absint( $_GET['swh_ticket'] ) : 0;
	$token     = sanitize_text_field( wp_unslash( isset( $_GET['token'] ) && is_string( $_GET['token'] ) ? $_GET['token'] : '' ) );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	$post     = get_post( $ticket_id );
	$db_token = swh_get_string_meta( $ticket_id, '_ticket_token' );

	if ( ! $post || 'helpdesk_ticket' !== $post->post_type || ! hash_equals( $db_token, $token ) ) {
		echo '<div class="swh-helpdesk-wrapper">';
		echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( swh_get_string_option( 'swh_msg_err_invalid', is_string( $defs['swh_msg_err_invalid'] ) ? $defs['swh_msg_err_invalid'] : '' ) ) . '</div>';
		echo '</div>';
		return;
	}

	if ( swh_is_token_expired( $ticket_id ) ) {
		echo '<div class="swh-helpdesk-wrapper">';
		echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( swh_get_string_option( 'swh_msg_err_expired', is_string( $defs['swh_msg_err_expired'] ) ? $defs['swh_msg_err_expired'] : '' ) ) . '</div>';
		echo '</div>';
		return;
	}

	$ticket_name = swh_get_string_meta( $ticket_id, '_ticket_name' );
	$data        = array(
		'name'       => $ticket_name ? $ticket_name : 'Client',
		'email'      => swh_get_string_meta( $ticket_id, '_ticket_email' ),
		'ticket_id'  => swh_get_string_meta( $ticket_id, '_ticket_uid' ),
		'title'      => $post->post_title,
		'status'     => swh_get_string_meta( $ticket_id, '_ticket_status' ),
		'priority'   => swh_get_string_meta( $ticket_id, '_ticket_priority' ),
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
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html__( 'Please wait a moment before submitting again.', 'simple-wp-helpdesk' ) . '</div>';
			$is_post_action = false;
		}
	}

	if ( $is_post_action && isset( $_POST['swh_user_close_ticket_submit'], $_POST['swh_close_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( is_string( $_POST['swh_close_nonce'] ) ? $_POST['swh_close_nonce'] : '' ) ), 'swh_user_close' ) ) {
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
		$csat_nonce = wp_create_nonce( 'swh_csat_' . $ticket_id );
		$close_msg  = esc_html( swh_get_string_option( 'swh_msg_success_closed', is_string( $defs['swh_msg_success_closed'] ) ? $defs['swh_msg_success_closed'] : '' ) );
		echo '<div id="swh-csat" class="swh-alert swh-alert-info" data-ticket="' . esc_attr( (string) $ticket_id ) . '" data-nonce="' . esc_attr( $csat_nonce ) . '" data-ajaxurl="' . esc_attr( admin_url( 'admin-ajax.php' ) ) . '" data-success="' . esc_attr( $close_msg ) . '">';
		echo '<p style="margin:0 0 10px 0;"><strong>' . esc_html__( 'How was your support experience?', 'simple-wp-helpdesk' ) . '</strong></p>';
		echo '<div class="swh-csat-stars">';
		for ( $i = 1; $i <= 5; $i++ ) {
			/* translators: %d: star rating value */
			echo '<button type="button" class="swh-csat-star" data-rating="' . esc_attr( (string) $i ) . '" aria-label="' . esc_attr( sprintf( __( '%d star', 'simple-wp-helpdesk' ), $i ) ) . '">&#9733;</button>';
		}
		echo '</div>';
		echo '<p style="margin:8px 0 0 0;"><a href="#" id="swh-csat-skip">' . esc_html__( 'Skip', 'simple-wp-helpdesk' ) . '</a></p>';
		echo '</div>';
		echo '<div id="swh-csat-thanks" class="swh-alert swh-alert-success" style="display:none;" role="status">' . esc_html__( 'Thanks for your feedback!', 'simple-wp-helpdesk' ) . '</div>';
		echo '<div id="swh-close-success" class="swh-alert swh-alert-success" style="display:none;" role="status">' . $close_msg . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $close_msg already esc_html()'d above.
		$data['status'] = $closed_status;
	} elseif ( $is_post_action && isset( $_POST['swh_user_reopen_submit'], $_POST['swh_reopen_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( is_string( $_POST['swh_reopen_nonce'] ) ? $_POST['swh_reopen_nonce'] : '' ) ), 'swh_user_reopen' ) ) {
		if ( swh_check_antispam( false ) ) {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( swh_get_string_option( 'swh_msg_err_spam', is_string( $defs['swh_msg_err_spam'] ) ? $defs['swh_msg_err_spam'] : '' ) ) . '</div>';
		} else {
			$reply_text = isset( $_POST['ticket_reopen_text'] ) && is_string( $_POST['ticket_reopen_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_reopen_text'] ) ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$reopen_files = isset( $_FILES['swh_reopen_attachments'] ) && is_array( $_FILES['swh_reopen_attachments'] ) ? $_FILES['swh_reopen_attachments'] : array();
			$reopen_names = isset( $reopen_files['name'] ) && is_array( $reopen_files['name'] ) ? $reopen_files['name'] : array();
			$has_files    = ! empty( $reopen_names[0] );
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
			$reopen_orignames = null;
			$reopen_skipped   = 0;
			// $_FILES array is validated and sanitized inside swh_handle_multiple_uploads(); cannot apply sanitize_text_field() to a file array.
			$attach_urls = swh_handle_multiple_uploads( $reopen_files, $reopen_orignames, $reopen_skipped );
			if ( $comment_id && ! empty( $attach_urls ) ) {
				update_comment_meta( $comment_id, '_attachments', $attach_urls );
				if ( ! empty( $reopen_orignames ) ) {
					update_comment_meta( $comment_id, '_swh_reply_orignames', $reopen_orignames );
				}
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
			echo '<div class="swh-alert swh-alert-success" role="status">' . esc_html( swh_get_string_option( 'swh_msg_success_reopen', is_string( $defs['swh_msg_success_reopen'] ) ? $defs['swh_msg_success_reopen'] : '' ) ) . '</div>';
			if ( $reopen_skipped > 0 ) {
				$max_mb = swh_get_int_option( 'swh_max_upload_size', 5 );
				echo '<div class="swh-alert swh-alert-warning" role="status">' . esc_html(
					/* translators: 1: number of skipped files, 2: upload size limit in MB */
					sprintf( _n( '%1$d file was not uploaded because it exceeds the %2$dMB size limit.', '%1$d files were not uploaded because they exceed the %2$dMB size limit.', $reopen_skipped, 'simple-wp-helpdesk' ), $reopen_skipped, $max_mb )
				) . '</div>';
			}
			$data['status'] = $reopened_status;
		} // end anti-spam else
	} elseif ( $is_post_action && isset( $_POST['swh_user_reply_submit'], $_POST['swh_reply_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( is_string( $_POST['swh_reply_nonce'] ) ? $_POST['swh_reply_nonce'] : '' ) ), 'swh_user_reply' ) ) {
		if ( swh_check_antispam( true ) ) {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( swh_get_string_option( 'swh_msg_err_spam', is_string( $defs['swh_msg_err_spam'] ) ? $defs['swh_msg_err_spam'] : '' ) ) . '</div>';
		} else {
			$reply_text = isset( $_POST['ticket_reply_text'] ) && is_string( $_POST['ticket_reply_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_reply_text'] ) ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$reply_files = isset( $_FILES['swh_user_reply_attachments'] ) && is_array( $_FILES['swh_user_reply_attachments'] ) ? $_FILES['swh_user_reply_attachments'] : array();
			$reply_names = isset( $reply_files['name'] ) && is_array( $reply_files['name'] ) ? $reply_files['name'] : array();
			$has_files   = ! empty( $reply_names[0] );
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
				$reply_orignames = null;
				$reply_skipped   = 0;
				// $_FILES array is validated and sanitized inside swh_handle_multiple_uploads(); cannot apply sanitize_text_field() to a file array.
				$attach_urls = swh_handle_multiple_uploads( $reply_files, $reply_orignames, $reply_skipped );
				if ( $comment_id && ! empty( $attach_urls ) ) {
					update_comment_meta( $comment_id, '_attachments', $attach_urls );
					if ( ! empty( $reply_orignames ) ) {
						update_comment_meta( $comment_id, '_swh_reply_orignames', $reply_orignames );
					}
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
				echo '<div class="swh-alert swh-alert-success" role="status">' . esc_html( swh_get_string_option( 'swh_msg_success_reply', is_string( $defs['swh_msg_success_reply'] ) ? $defs['swh_msg_success_reply'] : '' ) ) . '</div>';
				if ( $reply_skipped > 0 ) {
					$max_mb = swh_get_int_option( 'swh_max_upload_size', 5 );
					echo '<div class="swh-alert swh-alert-warning" role="status">' . esc_html(
						/* translators: 1: number of skipped files, 2: upload size limit in MB */
						sprintf( _n( '%1$d file was not uploaded because it exceeds the %2$dMB size limit.', '%1$d files were not uploaded because they exceed the %2$dMB size limit.', $reply_skipped, 'simple-wp-helpdesk' ), $reply_skipped, $max_mb )
					) . '</div>';
				}
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
				if ( ! is_string( $url ) ) {
					continue;
				}
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
	$comments = is_array( $comments ) ? $comments : array();
	if ( $comments ) {
		foreach ( $comments as $comment ) {
			if ( ! $comment instanceof WP_Comment ) {
				continue;
			}
			if ( get_comment_meta( (int) $comment->comment_ID, '_is_internal_note', true ) ) {
				continue;
			}
			$is_user = get_comment_meta( (int) $comment->comment_ID, '_is_user_reply', true );
			/* translators: %s: technician name */
			$author_name  = $is_user ? __( 'You', 'simple-wp-helpdesk' ) : sprintf( __( 'Technician (%s)', 'simple-wp-helpdesk' ), $comment->comment_author );
			$bubble_class = $is_user ? 'swh-chat-user' : 'swh-chat-tech';
			$attach_urls  = get_comment_meta( (int) $comment->comment_ID, '_attachments', true );
			$reply_names  = get_comment_meta( (int) $comment->comment_ID, '_swh_reply_orignames', true );
			$reply_names  = is_array( $reply_names ) ? $reply_names : array();
			$parsed_ts    = strtotime( $comment->comment_date );
			$comment_ts   = false !== $parsed_ts ? $parsed_ts : time();
			$comment_iso  = gmdate( 'c', $comment_ts );

			echo '<div class="swh-chat-bubble ' . esc_attr( $bubble_class ) . '">';
			echo '<strong style="display:block; margin-bottom: 5px;">' . esc_html( $author_name ) . ' <span style="font-weight:normal; font-size: 0.85em; color: #777;">(<time class="swh-timestamp" datetime="' . esc_attr( $comment_iso ) . '" title="' . esc_attr( $comment->comment_date ) . '">' . esc_html( $comment->comment_date ) . '</time>)</span></strong>';
			echo nl2br( esc_html( $comment->comment_content ) );
			if ( ! empty( $attach_urls ) && is_array( $attach_urls ) ) {
				echo '<div style="margin-top: 10px;">';
				foreach ( $attach_urls as $url ) {
					if ( ! is_string( $url ) ) {
						continue;
					}
					$rn    = isset( $reply_names[ $url ] ) && is_string( $reply_names[ $url ] ) ? $reply_names[ $url ] : '';
					$label = $rn ? $rn : basename( $url );
					echo '<a href="' . esc_url( swh_get_file_proxy_url( $url, $ticket_id ) ) . '" target="_blank" style="text-decoration: underline; margin-right:10px; color:#0073aa; font-size:13px;">' . esc_html( $label ) . '</a>'; // nosemgrep -- $url from comment meta (not $_REQUEST); esc_url() + esc_html() applied.
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
		<div class="swh-cta-primary">
			<div class="swh-cta-primary-content">
				<h4><?php esc_html_e( '&#10003; Your issue is resolved?', 'simple-wp-helpdesk' ); ?></h4>
				<p><?php esc_html_e( 'Mark this ticket as closed once your issue is fully resolved.', 'simple-wp-helpdesk' ); ?></p>
			</div>
			<form method="POST" action="" style="margin:0;">
				<?php wp_nonce_field( 'swh_user_close', 'swh_close_nonce' ); ?>
				<input type="submit" name="swh_user_close_ticket_submit" value="<?php esc_attr_e( 'Close Ticket', 'simple-wp-helpdesk' ); ?>" class="swh-btn">
			</form>
		</div>
		<p class="swh-cta-secondary"><?php esc_html_e( 'Still need help?', 'simple-wp-helpdesk' ); ?> <a href="#swh-reply-text"><?php esc_html_e( 'Reply below &#8595;', 'simple-wp-helpdesk' ); ?></a></p>
	<?php endif; ?>
	<?php if ( $closed_status !== $data['status'] ) : ?>
		<form method="POST" action="" enctype="multipart/form-data">
			<?php wp_nonce_field( 'swh_user_reply', 'swh_reply_nonce' ); ?>
			<div class="swh-form-group">
				<label for="swh-reply-text"><?php esc_html_e( 'Add a Reply:', 'simple-wp-helpdesk' ); ?></label>
				<textarea id="swh-reply-text" name="ticket_reply_text" rows="4" class="swh-form-control"></textarea>
			</div>
			<div class="swh-form-group">
				<label for="swh-reply-attachments"><?php esc_html_e( 'Attach Files (Optional):', 'simple-wp-helpdesk' ); ?></label>
				<input type="file" id="swh-reply-attachments" name="swh_user_reply_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" class="swh-form-control swh-file-input">
				<small class="swh-text-muted" style="display:block; margin-top:5px;">
				<?php
					/* translators: 1: max upload size in MB, 2: max file count */
					printf( esc_html__( 'Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT. Max size: %1$sMB per file. Max files: %2$s.', 'simple-wp-helpdesk' ), esc_html( (string) swh_get_int_option( 'swh_max_upload_size', 5 ) ), esc_html( (string) swh_get_int_option( 'swh_max_upload_count', 5 ) ) );
				?>
				</small>
			</div>
			<?php
			if ( 'honeypot' === $spam_method ) {
				echo '<div aria-hidden="true" style="position: absolute; left: -9999px;"><label aria-hidden="true">Leave this empty</label><input type="text" name="swh_website_url_hp" value="" tabindex="-1" autocomplete="off"></div>';
			} elseif ( 'recaptcha' === $spam_method ) {
				$key            = swh_get_string_option( 'swh_recaptcha_site_key' );
				$recaptcha_type = swh_get_string_option( 'swh_recaptcha_type', 'v2' );
				echo '<div id="swh-recaptcha-reply" style="margin-bottom: 15px;"></div>';
				if ( 'enterprise' === $recaptcha_type ) {
					// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External CDN script; null version is intentional.
					wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/enterprise.js?onload=swhRecaptchaLoad&render=explicit', array(), null, true );
					wp_add_inline_script( 'google-recaptcha', 'window.swhRecaptchaLoad = function() { document.querySelectorAll("[id^=swh-recaptcha-]").forEach(function(el) { if(window.grecaptcha && !el.hasChildNodes()) { grecaptcha.enterprise.render(el.id, {"sitekey": "' . esc_js( $key ) . '"}); } }); };', 'before' );
				} else {
					// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External CDN script; null version is intentional.
					wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js?onload=swhRecaptchaLoad&render=explicit', array(), null, true );
					wp_add_inline_script( 'google-recaptcha', 'window.swhRecaptchaLoad = function() { document.querySelectorAll("[id^=swh-recaptcha-]").forEach(function(el) { if(window.grecaptcha && !el.hasChildNodes()) { grecaptcha.render(el.id, {"sitekey": "' . esc_js( $key ) . '"}); } }); };', 'before' );
				}
			} elseif ( 'turnstile' === $spam_method ) {
				$key = swh_get_string_option( 'swh_turnstile_site_key' );
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
		<div class="swh-alert swh-alert-error" role="alert">
			<p style="margin-top: 0; font-weight: bold;"><?php esc_html_e( 'This ticket is closed.', 'simple-wp-helpdesk' ); ?></p>
			<form method="POST" action="" enctype="multipart/form-data">
				<?php wp_nonce_field( 'swh_user_reopen', 'swh_reopen_nonce' ); ?>
				<div class="swh-form-group">
					<label for="swh-reopen-text"><?php esc_html_e( 'Explain why you need this re-opened:', 'simple-wp-helpdesk' ); ?></label>
					<textarea id="swh-reopen-text" name="ticket_reopen_text" rows="3" class="swh-form-control"></textarea>
				</div>
				<div class="swh-form-group">
					<label for="swh-reopen-attachments"><?php esc_html_e( 'Attach Files (Optional):', 'simple-wp-helpdesk' ); ?></label>
					<input type="file" id="swh-reopen-attachments" name="swh_reopen_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" class="swh-form-control swh-file-input">
					<small class="swh-text-muted" style="display:block; margin-top:5px;">
					<?php
						/* translators: 1: max upload size in MB, 2: max file count */
						printf( esc_html__( 'Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT. Max size: %1$sMB. Max files: %2$s.', 'simple-wp-helpdesk' ), esc_html( (string) swh_get_int_option( 'swh_max_upload_size', 5 ) ), esc_html( (string) swh_get_int_option( 'swh_max_upload_count', 5 ) ) );
					?>
					</small>
				</div>
				<?php if ( 'honeypot' === $spam_method ) : ?>
					<div aria-hidden="true" style="position: absolute; left: -9999px;"><label aria-hidden="true">Leave this empty</label><input type="text" name="swh_website_url_hp" value="" tabindex="-1" autocomplete="off"></div>
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

/**
 * Renders the portal no-token view.
 *
 * Logged-in WP users see a table of their open tickets with secure links.
 * Guests see the ticket lookup form so they can request their links by email.
 *
 * @return void
 */
function swh_render_portal_no_token() {
	echo '<div class="swh-helpdesk-wrapper">';

	if ( is_user_logged_in() ) {
		$current_user  = wp_get_current_user();
		$defs          = swh_get_defaults();
		$closed_status = swh_get_string_option( 'swh_closed_status', is_string( $defs['swh_closed_status'] ) ? $defs['swh_closed_status'] : '' );

		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$tickets = get_posts(
			array(
				'post_type'      => 'helpdesk_ticket',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_ticket_email',
						'value' => $current_user->user_email,
					),
					array(
						'key'     => '_ticket_status',
						'value'   => $closed_status,
						'compare' => '!=',
					),
				),
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$tickets = is_array( $tickets ) ? $tickets : array();

		echo '<h3 style="margin-top:0;">' . esc_html__( 'My Open Tickets', 'simple-wp-helpdesk' ) . '</h3>';

		if ( empty( $tickets ) ) {
			echo '<p>' . esc_html__( 'You have no open tickets.', 'simple-wp-helpdesk' ) . '</p>';
		} else {
			echo '<table class="swh-ticket-table">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Ticket #', 'simple-wp-helpdesk' ) . '</th>';
			echo '<th>' . esc_html__( 'Summary', 'simple-wp-helpdesk' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'simple-wp-helpdesk' ) . '</th>';
			echo '<th>' . esc_html__( 'Last Updated', 'simple-wp-helpdesk' ) . '</th>';
			echo '<th></th>';
			echo '</tr></thead>';
			echo '<tbody>';
			foreach ( $tickets as $ticket ) {
				$uid       = swh_get_string_meta( $ticket->ID, '_ticket_uid' );
				$status    = swh_get_string_meta( $ticket->ID, '_ticket_status' );
				$link      = swh_get_secure_ticket_link( $ticket->ID );
				$parsed_ts = strtotime( $ticket->post_modified );
				$ts        = false !== $parsed_ts ? $parsed_ts : time();
				echo '<tr class="swh-ticket-row">';
				echo '<td>' . esc_html( $uid ) . '</td>';
				echo '<td>' . esc_html( $ticket->post_title ) . '</td>';
				echo '<td><span class="swh-badge swh-badge-open">' . esc_html( $status ) . '</span></td>';
				echo '<td><time class="swh-timestamp" datetime="' . esc_attr( gmdate( 'c', $ts ) ) . '" title="' . esc_attr( $ticket->post_modified ) . '">' . esc_html( $ticket->post_modified ) . '</time></td>';
				echo '<td>';
				if ( $link ) {
					echo '<a href="' . esc_url( $link ) . '" class="swh-btn" style="padding:6px 12px; font-size:13px;">' . esc_html__( 'View', 'simple-wp-helpdesk' ) . '</a>';
				} else {
					echo '<span class="swh-muted">' . esc_html__( 'Link unavailable', 'simple-wp-helpdesk' ) . '</span>';
				}
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	} else {
		swh_render_lookup_form();
	}

	echo '</div>';
}

/**
 * Renders the ticket lookup form (request links by email).
 *
 * Extracted as a standalone function so it can be rendered both inside the
 * [submit_ticket] shortcode and in the portal no-token view for guests.
 *
 * @return void
 */
function swh_render_lookup_form() {
	$defs        = swh_get_defaults();
	$spam_method = get_option( 'swh_spam_method', 'none' );

	if ( isset( $_POST['swh_ticket_lookup'], $_POST['swh_lookup_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( is_string( $_POST['swh_lookup_nonce'] ) ? $_POST['swh_lookup_nonce'] : '' ) ), 'swh_ticket_lookup' ) ) {
		if ( swh_is_rate_limited( 'lookup', 60 ) ) {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html__( 'Please wait a moment before submitting again.', 'simple-wp-helpdesk' ) . '</div>';
		} elseif ( swh_check_antispam( false ) ) {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( swh_get_string_option( 'swh_msg_err_spam', is_string( $defs['swh_msg_err_spam'] ) ? $defs['swh_msg_err_spam'] : '' ) ) . '</div>';
		} else {
			$lookup_email = isset( $_POST['swh_lookup_email'] ) && is_string( $_POST['swh_lookup_email'] ) ? sanitize_email( wp_unslash( $_POST['swh_lookup_email'] ) ) : '';
			if ( $lookup_email ) {
				$closed_status = get_option( 'swh_closed_status', $defs['swh_closed_status'] );
				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				$lookup_tickets = get_posts(
					array(
						'post_type'      => 'helpdesk_ticket',
						'posts_per_page' => -1,
						'post_status'    => 'publish',
						'meta_query'     => array(
							'relation' => 'AND',
							array(
								'key'   => '_ticket_email',
								'value' => $lookup_email,
							),
							array(
								'key'     => '_ticket_status',
								'value'   => $closed_status,
								'compare' => '!=',
							),
						),
					)
				);
				// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				if ( ! empty( $lookup_tickets ) ) {
					$lookup_tickets = is_array( $lookup_tickets ) ? $lookup_tickets : array();
					$ticket_links   = '';
					foreach ( $lookup_tickets as $lt ) {
						$new_token = wp_generate_password( 20, false );
						update_post_meta( $lt->ID, '_ticket_token', $new_token );
						update_post_meta( $lt->ID, '_ticket_token_created', time() );
						$link = swh_get_secure_ticket_link( $lt->ID );
						if ( $link ) {
							$uid           = swh_get_string_meta( $lt->ID, '_ticket_uid' );
							$ticket_links .= '- ' . $uid . ': ' . $lt->post_title . "\n  " . $link . "\n\n";
						} else {
							// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional; logs link generation failures for admin troubleshooting.
							error_log( 'SWH: swh_get_secure_ticket_link() returned false for ticket ' . $lt->ID . ' during lookup for ' . $lookup_email );
						}
					}
					if ( $ticket_links ) {
						$lookup_data = array(
							'email'        => $lookup_email,
							'ticket_links' => $ticket_links,
						);
						swh_send_email( $lookup_email, 'swh_em_user_lookup_sub', 'swh_em_user_lookup_body', $lookup_data );
					} else {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional; logs skipped lookup emails for admin troubleshooting.
						error_log( 'SWH: lookup email skipped for ' . $lookup_email . ' — no usable ticket links (portal page not configured or pre-v1.9.0 tickets without tokens).' );
					}
				}
			}
			// Always show the same message to prevent email enumeration.
			echo '<div class="swh-alert swh-alert-success" role="status">' . esc_html( swh_get_string_option( 'swh_msg_success_lookup', is_string( $defs['swh_msg_success_lookup'] ) ? $defs['swh_msg_success_lookup'] : '' ) ) . '</div>';
		}
	}
	?>
	<p><?php esc_html_e( 'Enter your email address to receive links to your open tickets.', 'simple-wp-helpdesk' ); ?></p>
	<form method="POST" action="">
		<?php wp_nonce_field( 'swh_ticket_lookup', 'swh_lookup_nonce' ); ?>
		<div class="swh-form-group">
			<label for="swh-lookup-email"><?php esc_html_e( 'Your Email Address:', 'simple-wp-helpdesk' ); ?></label>
			<input type="email" id="swh-lookup-email" name="swh_lookup_email" required class="swh-form-control">
		</div>
		<?php if ( 'honeypot' === $spam_method ) : ?>
			<div aria-hidden="true" style="position: absolute; left: -9999px;"><label aria-hidden="true">Leave this empty</label><input type="text" name="swh_website_url_hp" value="" tabindex="-1" autocomplete="off"></div>
		<?php endif; ?>
		<div class="swh-form-group">
			<input type="submit" name="swh_ticket_lookup" value="<?php esc_attr_e( 'Send My Ticket Links', 'simple-wp-helpdesk' ); ?>" class="swh-btn">
		</div>
	</form>
	<?php
}
