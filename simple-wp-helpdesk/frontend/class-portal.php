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
 * Enqueue Portal v2 assets. Called from swh_render_client_portal() so
 * the bundle only loads on pages that actually render the portal.
 *
 * @since 4.0.0 (#354)
 */
function swh_portal_v2_enqueue_assets(): void {
	$asset_file = SWH_PLUGIN_DIR . 'assets/dist/portal-v2.asset.php';
	$deps       = array();
	$version    = SWH_VERSION;
	if ( file_exists( $asset_file ) ) {
		// @phpstan-ignore-next-line include.fileNotFound
		$asset = include $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		if ( is_array( $asset ) ) {
			if ( isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ) {
				$deps = array_values( array_filter( $asset['dependencies'], 'is_string' ) );
			}
			if ( isset( $asset['version'] ) && is_string( $asset['version'] ) ) {
				$version = $asset['version'];
			}
		}
	}

	// wp-scripts names the CSS chunk after the SCSS filename (style.scss → style-portal-v2.css).
	wp_enqueue_style(
		'swh-portal-v2',
		SWH_PLUGIN_URL . 'assets/dist/style-portal-v2.css',
		array(),
		$version
	);
	wp_enqueue_script(
		'swh-portal-v2',
		SWH_PLUGIN_URL . 'assets/dist/portal-v2.js',
		$deps,
		$version,
		true
	);
}

/**
 * Render the messenger-style thread for Portal v2.
 *
 * Treats the ticket's post body as the first synthetic customer message,
 * then loops over comment_type='helpdesk_reply' comments (skipping internal
 * notes) in chronological order. Returns the rendered HTML as a string.
 *
 * @since 4.0.0 (#354)
 *
 * @param int $ticket_id The ticket post ID.
 * @return string Rendered HTML, or empty string if the ticket doesn't exist.
 */
function swh_portal_render_thread( int $ticket_id ): string {
	$post = get_post( $ticket_id );
	if ( ! $post ) {
		return '';
	}

	$meta_email     = get_post_meta( $ticket_id, '_ticket_email', true );
	$customer_email = is_string( $meta_email ) ? $meta_email : '';

	$messages = array(
		array(
			'id'       => 0,
			'author'   => $customer_email,
			'body'     => $post->post_content,
			'date_gmt' => $post->post_date_gmt,
			'is_staff' => false,
		),
	);

	$comments = get_comments(
		array(
			'post_id' => $ticket_id,
			'type'    => 'helpdesk_reply',
			'orderby' => 'comment_date_gmt',
			'order'   => 'ASC',
		)
	);

	if ( is_array( $comments ) ) {
		foreach ( $comments as $c ) {
			if ( ! is_object( $c ) || ! property_exists( $c, 'comment_ID' ) ) {
				continue;
			}
			$internal_meta = get_comment_meta( (int) $c->comment_ID, '_is_internal_note', true );
			$is_internal   = is_string( $internal_meta ) && '1' === $internal_meta;
			if ( $is_internal ) {
				continue;
			}
			$messages[] = array(
				'id'       => (int) $c->comment_ID,
				'author'   => $c->comment_author,
				'body'     => $c->comment_content,
				'date_gmt' => $c->comment_date_gmt,
				'is_staff' => (int) $c->user_id > 0,
			);
		}
	}

	ob_start();
	foreach ( $messages as $msg ) {
		// @phpstan-ignore-next-line include.fileNotFound
		include SWH_PLUGIN_DIR . 'frontend/templates/portal-v2/_message.php';
	}
	return (string) ob_get_clean();
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
	$theme_attr      = ( in_array( swh_get_string_option( 'swh_portal_theme', 'auto' ), array( 'light', 'dark' ), true ) ? ' data-swh-theme="' . esc_attr( swh_get_string_option( 'swh_portal_theme', 'auto' ) ) . '"' : '' );

	// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Token-based auth; params verified via hash_equals() below; isset() checked by callers.
	$ticket_id = isset( $_GET['swh_ticket'] ) && is_scalar( $_GET['swh_ticket'] ) ? absint( $_GET['swh_ticket'] ) : 0;
	$token     = sanitize_text_field( wp_unslash( isset( $_GET['token'] ) && is_string( $_GET['token'] ) ? $_GET['token'] : '' ) );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated
	$post     = get_post( $ticket_id );
	$db_token = swh_get_string_meta( $ticket_id, '_ticket_token' );

	if ( ! $post || 'helpdesk_ticket' !== $post->post_type || '' === $db_token || '' === $token || ! hash_equals( $db_token, $token ) ) {
		echo '<div class="swh-helpdesk-wrapper"' . $theme_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded attribute string
		echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( swh_get_string_option( 'swh_msg_err_invalid', is_string( $defs['swh_msg_err_invalid'] ) ? $defs['swh_msg_err_invalid'] : '' ) ) . '</div>';
		echo '</div>';
		return;
	}

	if ( swh_is_token_expired( $ticket_id ) ) {
		echo '<div class="swh-helpdesk-wrapper"' . $theme_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded attribute string
		echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( swh_get_string_option( 'swh_msg_err_expired', is_string( $defs['swh_msg_err_expired'] ) ? $defs['swh_msg_err_expired'] : '' ) ) . '</div>';
		swh_render_lookup_form();
		// (#346) After token-expiry render, move focus to the lookup form so keyboard users
		// don't have to Tab past everything to reach the recovery input.
		echo '<script>document.addEventListener("DOMContentLoaded",function(){var i=document.getElementById("swh-lookup-email");if(i){i.focus({preventScroll:false});}});</script>';
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
		'admin_url'  => swh_admin_ticket_url( $ticket_id ),
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
		swh_set_ticket_status( $ticket_id, $closed_status );
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
			update_post_meta( $ticket_id, '_swh_last_activity_at', time() );
		}
		swh_fire_ticket_replied( $ticket_id, $comment_id, 0 );
		$admin_email = swh_get_admin_email( $ticket_id );
		swh_send_email( $admin_email, 'swh_em_admin_closed_sub', 'swh_em_admin_closed_body', $data );
		swh_send_email( $data['email'], 'swh_em_user_closed_sub', 'swh_em_user_closed_body', $data );
		$close_msg = esc_html( swh_get_string_option( 'swh_msg_success_closed', is_string( $defs['swh_msg_success_closed'] ) ? $defs['swh_msg_success_closed'] : '' ) );
		// CSAT widget — gated on portal.csat_enabled (v4.0.3 #471). When disabled,
		// only the close-success message renders — no DOM emission, no AJAX surface.
		$csat_enabled = (bool) swh_get_option( 'portal', 'csat_enabled', true );
		if ( $csat_enabled ) {
			$csat_nonce  = wp_create_nonce( 'swh_csat_' . $ticket_id );
			$csat_prompt = swh_get_string_option( 'swh_csat_prompt', is_string( $defs['swh_csat_prompt'] ) ? $defs['swh_csat_prompt'] : '' );
			$csat_thanks = swh_get_string_option( 'swh_csat_thanks', is_string( $defs['swh_csat_thanks'] ) ? $defs['swh_csat_thanks'] : '' );
			$csat_skip   = swh_get_string_option( 'swh_csat_skip_label', is_string( $defs['swh_csat_skip_label'] ) ? $defs['swh_csat_skip_label'] : '' );
			echo '<div id="swh-csat" class="swh-alert swh-alert-info" data-ticket="' . esc_attr( (string) $ticket_id ) . '" data-nonce="' . esc_attr( $csat_nonce ) . '" data-ajaxurl="' . esc_attr( admin_url( 'admin-ajax.php' ) ) . '" data-success="' . esc_attr( $close_msg ) . '">';
			echo '<p style="margin:0 0 10px 0;"><strong>' . esc_html( $csat_prompt ) . '</strong></p>';
			echo '<div class="swh-csat-stars">';
			for ( $i = 1; $i <= 5; $i++ ) {
				/* translators: %d: star rating value */
				echo '<button type="button" class="swh-csat-star" data-rating="' . esc_attr( (string) $i ) . '" aria-label="' . esc_attr( sprintf( __( '%d star', 'simple-wp-helpdesk' ), $i ) ) . '">&#9733;</button>';
			}
			echo '</div>';
			echo '<p style="margin:8px 0 0 0;"><a href="#" id="swh-csat-skip">' . esc_html( $csat_skip ) . '</a></p>';
			echo '</div>';
			echo '<div id="swh-csat-thanks" class="swh-alert swh-alert-success" style="display:none;" role="status" tabindex="-1">' . esc_html( $csat_thanks ) . '</div>';
			echo '<div id="swh-close-success" class="swh-alert swh-alert-success" style="display:none;" role="status">' . $close_msg . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $close_msg already esc_html()'d above.
		} else {
			echo '<div id="swh-close-success" class="swh-alert swh-alert-success" role="status">' . $close_msg . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $close_msg already esc_html()'d above.
		}
		$data['status'] = $closed_status;
	} elseif ( $is_post_action && isset( $_POST['swh_user_reopen_submit'], $_POST['swh_reopen_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( is_string( $_POST['swh_reopen_nonce'] ) ? $_POST['swh_reopen_nonce'] : '' ) ), 'swh_user_reopen' ) ) {
		if ( swh_check_antispam( false ) ) {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( swh_get_string_option( 'swh_msg_err_spam', is_string( $defs['swh_msg_err_spam'] ) ? $defs['swh_msg_err_spam'] : '' ) ) . '</div>';
		} else {
			$reply_text = isset( $_POST['ticket_reopen_text'] ) && is_string( $_POST['ticket_reopen_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_reopen_text'] ) ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$reopen_files     = isset( $_FILES['swh_reopen_attachments'] ) && is_array( $_FILES['swh_reopen_attachments'] ) ? $_FILES['swh_reopen_attachments'] : array();
			$reopen_orignames = null;
			$reopen_skipped   = 0;
			// $_FILES array is validated and sanitized inside swh_handle_multiple_uploads(); cannot apply sanitize_text_field() to a file array.
			$attach_urls = swh_handle_multiple_uploads( $reopen_files, $reopen_orignames, $reopen_skipped );
			$has_files   = ! empty( $attach_urls ); // Derive from actual upload result, not raw file names.
			swh_set_ticket_status( $ticket_id, $reopened_status );
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
			swh_fire_ticket_replied( $ticket_id, $comment_id, 0 );
			if ( $comment_id ) {
				update_comment_meta( $comment_id, '_is_user_reply', '1' );
				update_post_meta( $ticket_id, '_swh_last_activity_at', time() );
				if ( ! empty( $attach_urls ) ) {
					update_comment_meta( $comment_id, '_attachments', $attach_urls );
					if ( ! empty( $reopen_orignames ) ) {
						update_comment_meta( $comment_id, '_swh_reply_orignames', $reopen_orignames );
					}
				}
				update_post_meta( $ticket_id, '_swh_unread', '1' );
				delete_transient( 'swh_unread_count' );
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
			$reply_files     = isset( $_FILES['swh_user_reply_attachments'] ) && is_array( $_FILES['swh_user_reply_attachments'] ) ? $_FILES['swh_user_reply_attachments'] : array();
			$reply_orignames = null;
			$reply_skipped   = 0;
			// $_FILES array is validated and sanitized inside swh_handle_multiple_uploads(); cannot apply sanitize_text_field() to a file array.
			$attach_urls = swh_handle_multiple_uploads( $reply_files, $reply_orignames, $reply_skipped );
			$has_files   = ! empty( $attach_urls ); // Derive from actual upload result, not raw file names.
			if ( $reply_text || $has_files ) {
				if ( $resolved_status === $data['status'] ) {
					$data['status'] = $reopened_status;
					swh_set_ticket_status( $ticket_id, $reopened_status );
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
				swh_fire_ticket_replied( $ticket_id, $comment_id, 0 );
				if ( $comment_id ) {
					update_comment_meta( $comment_id, '_is_user_reply', '1' );
					update_post_meta( $ticket_id, '_swh_last_activity_at', time() );
					if ( ! empty( $attach_urls ) ) {
						update_comment_meta( $comment_id, '_attachments', $attach_urls );
						if ( ! empty( $reply_orignames ) ) {
							update_comment_meta( $comment_id, '_swh_reply_orignames', $reply_orignames );
						}
					}
					update_post_meta( $ticket_id, '_swh_unread', '1' );
					delete_transient( 'swh_unread_count' );
					\SWH\Concurrency\TicketVersion::bump( $ticket_id );
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
	// v4.0.0 #354 — Portal v2 messenger layout.
	$meta_status = get_post_meta( $ticket_id, '_ticket_status', true );
	$meta_prio   = get_post_meta( $ticket_id, '_ticket_priority', true );
	$title       = $post->post_title;
	$status      = is_string( $meta_status ) ? $meta_status : '';
	$priority    = is_string( $meta_prio ) ? $meta_prio : '';
	// Only the explicit Closed status hides the "Close ticket" menu item.
	// A Resolved ticket still shows "Close ticket" so the customer can
	// confirm closure (matches legacy portal behavior). Case-insensitive
	// because settings store "Closed" but swh_set_ticket_status writes
	// the canonical lowercase form to the meta.
	$is_closed = ( 0 === strcasecmp( $status, $closed_status ) );

	swh_portal_v2_enqueue_assets();

	echo '<div id="swh-portal-app" class="swh-portal-v2 swh-helpdesk-wrapper">';
	// CSAT widget — render once a ticket is closed and the client hasn't rated yet.
	// (#477) Portal v2's close path is a server redirect; the legacy inline-render
	// branch above only fires on POST-back, so without this block the v4.0.3
	// portal.csat_enabled toggle has no effect for portal v2 users.
	$csat_enabled  = (bool) swh_get_option( 'portal', 'csat_enabled', true );
	$rated_meta    = get_post_meta( $ticket_id, '_ticket_csat', true );
	$already_rated = is_scalar( $rated_meta ) ? (string) $rated_meta : '';
	if ( $is_closed && $csat_enabled && '' === $already_rated ) {
		$csat_nonce  = wp_create_nonce( 'swh_csat_' . $ticket_id );
		$csat_prompt = swh_get_string_option( 'swh_csat_prompt', is_string( $defs['swh_csat_prompt'] ) ? $defs['swh_csat_prompt'] : '' );
		$csat_thanks = swh_get_string_option( 'swh_csat_thanks', is_string( $defs['swh_csat_thanks'] ) ? $defs['swh_csat_thanks'] : '' );
		$csat_skip   = swh_get_string_option( 'swh_csat_skip_label', is_string( $defs['swh_csat_skip_label'] ) ? $defs['swh_csat_skip_label'] : '' );
		echo '<div id="swh-csat" class="swh-alert swh-alert-info" data-ticket="' . esc_attr( (string) $ticket_id ) . '" data-nonce="' . esc_attr( $csat_nonce ) . '" data-ajaxurl="' . esc_attr( admin_url( 'admin-ajax.php' ) ) . '">';
		echo '<p style="margin:0 0 10px 0;"><strong>' . esc_html( $csat_prompt ) . '</strong></p>';
		echo '<div class="swh-csat-stars">';
		for ( $i = 1; $i <= 5; $i++ ) {
			/* translators: %d: star rating value */
			echo '<button type="button" class="swh-csat-star" data-rating="' . esc_attr( (string) $i ) . '" aria-label="' . esc_attr( sprintf( __( '%d star', 'simple-wp-helpdesk' ), $i ) ) . '">&#9733;</button>';
		}
		echo '</div>';
		echo '<p style="margin:8px 0 0 0;"><a href="#" id="swh-csat-skip">' . esc_html( $csat_skip ) . '</a></p>';
		echo '</div>';
		echo '<div id="swh-csat-thanks" class="swh-alert swh-alert-success" style="display:none;" role="status" tabindex="-1">' . esc_html( $csat_thanks ) . '</div>';
	}
	// @phpstan-ignore-next-line include.fileNotFound
	include SWH_PLUGIN_DIR . 'frontend/templates/portal-v2/header.php';
	// @phpstan-ignore-next-line include.fileNotFound
	include SWH_PLUGIN_DIR . 'frontend/templates/portal-v2/thread.php';
	// @phpstan-ignore-next-line include.fileNotFound
	include SWH_PLUGIN_DIR . 'frontend/templates/portal-v2/composer.php';
	echo '</div>';
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
	$theme_attr = ( in_array( swh_get_string_option( 'swh_portal_theme', 'auto' ), array( 'light', 'dark' ), true ) ? ' data-swh-theme="' . esc_attr( swh_get_string_option( 'swh_portal_theme', 'auto' ) ) . '"' : '' );
	echo '<div class="swh-helpdesk-wrapper"' . $theme_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded attribute string

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

		echo '<h2 class="swh-my-tickets-heading">' . esc_html__( 'My Open Tickets', 'simple-wp-helpdesk' ) . '</h2>';

		if ( empty( $tickets ) ) {
			echo '<div class="swh-empty-state">' .
				'<svg class="swh-empty-state-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">' .
				'<path d="M20 4H4C2.9 4 2 4.9 2 6v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4v-6h16v6zM4 8V6h16v2H4z"/>' .
				'</svg>' .
				'<p class="swh-empty-state-title">' . esc_html__( 'No open tickets', 'simple-wp-helpdesk' ) . '</p>' .
				'<p class="swh-empty-state-desc">' . esc_html__( 'Submit a ticket to get started.', 'simple-wp-helpdesk' ) . '</p>' .
				'</div>';
		} else {
			echo '<ul class="swh-ticket-card-list" role="list" aria-label="' . esc_attr__( 'My open tickets', 'simple-wp-helpdesk' ) . '">';
			foreach ( $tickets as $ticket ) {
				$uid          = swh_get_string_meta( $ticket->ID, '_ticket_uid' );
				$status       = swh_get_string_meta( $ticket->ID, '_ticket_status' );
				$status_slug  = sanitize_title( $status );
				$unread       = get_post_meta( $ticket->ID, '_swh_unread', true );
				$link         = swh_get_secure_ticket_link( $ticket->ID );
				$modified_gmt = $ticket->post_modified_gmt;
				$utc_ts       = $modified_gmt ? strtotime( $modified_gmt . ' UTC' ) : false;
				$utc_ts       = false !== $utc_ts ? $utc_ts : time();
				$date_fmt     = get_option( 'date_format' );
				$time_fmt     = get_option( 'time_format' );
				$fmt          = ( is_string( $date_fmt ) ? $date_fmt : 'F j, Y' ) . ' ' . ( is_string( $time_fmt ) ? $time_fmt : 'g:i a' );
				$disp         = wp_date( $fmt, $utc_ts );
				$disp         = is_string( $disp ) ? $disp : gmdate( 'Y-m-d H:i', $utc_ts );
				$card_class   = 'swh-ticket-card' . ( $unread ? ' swh-ticket-card--unread' : '' );
				echo '<li class="' . esc_attr( $card_class ) . '">';
				echo '<span class="swh-badge swh-badge-' . esc_attr( $status_slug ) . '">' . esc_html( $status ) . '</span>';
				echo '<div class="swh-ticket-card-body">';
				echo '<p class="swh-ticket-card-title">' . esc_html( $ticket->post_title ) . '</p>';
				echo '<p class="swh-ticket-card-meta">' . esc_html( $uid ) . ' &middot; ';
				echo '<time class="swh-timestamp" datetime="' . esc_attr( gmdate( 'c', $utc_ts ) ) . '" title="' . esc_attr( $disp ) . '">' . esc_html( $disp ) . '</time>';
				echo '</p>';
				echo '</div>';
				if ( $link ) {
					/* translators: %s: ticket subject */
					echo '<a href="' . esc_url( $link ) . '" class="swh-btn swh-btn-sm" aria-label="' . esc_attr( sprintf( __( 'View ticket: %s', 'simple-wp-helpdesk' ), $ticket->post_title ) ) . '">' . esc_html__( 'View', 'simple-wp-helpdesk' ) . ' &rarr;</a>';
				} else {
					echo '<span class="swh-muted">' . esc_html__( 'Link unavailable', 'simple-wp-helpdesk' ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}
	} else {
		swh_render_lookup_form();
	}

	echo '</div>';
}

// ── admin-post.php handlers for portal v2 actions ────────────────────────────
// These are registered as nopriv so unauthenticated portal visitors can
// submit without being redirected to wp-login.php.

add_action( 'admin_post_nopriv_swh_portal_reply', 'swh_handle_portal_v2_reply' );
add_action( 'admin_post_swh_portal_reply', 'swh_handle_portal_v2_reply' );

/**
 * Handles the portal v2 reply form submission (admin-post.php pathway).
 *
 * Validates the token, processes the reply, then redirects back to the portal.
 * Mirrors the inline POST logic in swh_render_client_portal() for the reply case.
 *
 * @return void
 */
function swh_handle_portal_v2_reply() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified via wp_verify_nonce() below.
	$ticket_id = isset( $_POST['ticket_id'] ) && is_scalar( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
	$token     = sanitize_text_field( wp_unslash( isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? $_POST['token'] : '' ) );
	$nonce_val = sanitize_text_field( wp_unslash( isset( $_POST['swh_portal_reply_nonce'] ) && is_string( $_POST['swh_portal_reply_nonce'] ) ? $_POST['swh_portal_reply_nonce'] : '' ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$post       = $ticket_id ? get_post( $ticket_id ) : null;
	$db_token   = $ticket_id ? swh_get_string_meta( $ticket_id, '_ticket_token' ) : '';
	$portal_url = $ticket_id ? swh_get_secure_ticket_link( $ticket_id ) : '';

	if (
		! $post ||
		'helpdesk_ticket' !== $post->post_type ||
		'' === $db_token ||
		'' === $token ||
		! hash_equals( $db_token, $token ) ||
		! wp_verify_nonce( $nonce_val, 'swh_portal_reply' )
	) {
		wp_die( esc_html__( 'Invalid request.', 'simple-wp-helpdesk' ), 403 );
	}

	if ( swh_is_token_expired( $ticket_id ) ) {
		wp_die( esc_html__( 'This link has expired. Please request a new link.', 'simple-wp-helpdesk' ), 403 );
	}

	$defs            = swh_get_defaults();
	$resolved_status = swh_get_string_option( 'swh_resolved_status', is_string( $defs['swh_resolved_status'] ) ? $defs['swh_resolved_status'] : '' );
	$reopened_status = swh_get_string_option( 'swh_reopened_status', is_string( $defs['swh_reopened_status'] ) ? $defs['swh_reopened_status'] : '' );
	$meta_status     = swh_get_string_meta( $ticket_id, '_ticket_status' );
	$meta_email      = swh_get_string_meta( $ticket_id, '_ticket_email' );
	$ticket_name     = swh_get_string_meta( $ticket_id, '_ticket_name' );
	$reply_text      = isset( $_POST['reply_body'] ) && is_string( $_POST['reply_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reply_body'] ) ) : '';

	if ( swh_is_rate_limited( 'portal_reply_' . $ticket_id, 30 ) || ! $reply_text ) {
		if ( $portal_url ) {
			wp_safe_redirect( $portal_url );
			exit;
		}
		wp_die( esc_html__( 'Invalid request.', 'simple-wp-helpdesk' ), 400 );
	}

	if ( swh_check_antispam( true ) ) {
		if ( $portal_url ) {
			wp_safe_redirect( $portal_url );
			exit;
		}
		wp_die( esc_html__( 'Spam detected.', 'simple-wp-helpdesk' ), 403 );
	}

	if ( $resolved_status === $meta_status ) {
		swh_set_ticket_status( $ticket_id, $reopened_status );
		delete_post_meta( $ticket_id, '_resolved_timestamp' );
	}

	$comment_id = wp_insert_comment(
		array(
			'comment_post_ID'      => $ticket_id,
			'comment_author'       => $ticket_name ? $ticket_name : 'Client',
			'comment_author_email' => $meta_email,
			'comment_content'      => $reply_text,
			'comment_approved'     => 1,
			'comment_type'         => 'helpdesk_reply',
		)
	);
	if ( $comment_id && ! is_wp_error( $comment_id ) ) {
		update_comment_meta( (int) $comment_id, '_is_user_reply', '1' );
		// Process attachments from the portal v2 composer (input name="attachments[]").
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$attach_files = isset( $_FILES['attachments'] ) && is_array( $_FILES['attachments'] ) ? $_FILES['attachments'] : array();
		$attach_names = null;
		$skipped      = 0;
		if ( isset( $attach_files['name'] ) && is_array( $attach_files['name'] ) && ! empty( $attach_files['name'][0] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$attach_urls = swh_handle_multiple_uploads( $attach_files, $attach_names, $skipped );
			if ( ! empty( $attach_urls ) ) {
				update_comment_meta( (int) $comment_id, '_attachments', $attach_urls );
				if ( ! empty( $attach_names ) ) {
					update_comment_meta( (int) $comment_id, '_swh_reply_orignames', $attach_names );
				}
			}
		}
		update_post_meta( $ticket_id, '_swh_last_activity_at', time() );
		update_post_meta( $ticket_id, '_swh_unread', '1' );
		delete_transient( 'swh_unread_count' );
		\SWH\Concurrency\TicketVersion::bump( $ticket_id );
	}
	swh_fire_ticket_replied( $ticket_id, $comment_id, 0 );

	$data        = array(
		'name'       => $ticket_name ? $ticket_name : 'Client',
		'email'      => $meta_email,
		'ticket_id'  => swh_get_string_meta( $ticket_id, '_ticket_uid' ),
		'title'      => $post->post_title,
		'status'     => $meta_status,
		'priority'   => swh_get_string_meta( $ticket_id, '_ticket_priority' ),
		'ticket_url' => $portal_url,
		'admin_url'  => swh_admin_ticket_url( $ticket_id ),
		'message'    => $reply_text,
	);
	$admin_email = swh_get_admin_email( $ticket_id );
	swh_send_email( $admin_email, 'swh_em_admin_reply_sub', 'swh_em_admin_reply_body', $data );

	// JS-driven fetch sends Accept: application/json — respond in kind so the
	// frontend gets a clean success signal instead of following the redirect.
	$accept_header = isset( $_SERVER['HTTP_ACCEPT'] ) && is_string( $_SERVER['HTTP_ACCEPT'] )
		? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) )
		: '';
	$xhr_header    = isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && is_string( $_SERVER['HTTP_X_REQUESTED_WITH'] )
		? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUESTED_WITH'] ) )
		: '';
	$wants_json    = ( false !== strpos( $accept_header, 'application/json' ) ) || ( 'XMLHttpRequest' === $xhr_header );
	if ( $wants_json ) {
		wp_send_json_success(
			array(
				'ticket_id'  => $ticket_id,
				'comment_id' => (int) $comment_id,
			)
		);
	}
	if ( $portal_url ) {
		wp_safe_redirect( $portal_url );
		exit;
	}
	wp_die( esc_html__( 'Reply sent.', 'simple-wp-helpdesk' ) );
}

add_action( 'admin_post_nopriv_swh_portal_close', 'swh_handle_portal_v2_close' );
add_action( 'admin_post_swh_portal_close', 'swh_handle_portal_v2_close' );

/**
 * Handles the portal v2 close-ticket action (admin-post.php pathway).
 *
 * @return void
 */
function swh_handle_portal_v2_close() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified via wp_verify_nonce() below.
	$ticket_id = isset( $_POST['ticket_id'] ) && is_scalar( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
	$token     = sanitize_text_field( wp_unslash( isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? $_POST['token'] : '' ) );
	$nonce_val = sanitize_text_field( wp_unslash( isset( $_POST['swh_portal_reply_nonce'] ) && is_string( $_POST['swh_portal_reply_nonce'] ) ? $_POST['swh_portal_reply_nonce'] : '' ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$post       = $ticket_id ? get_post( $ticket_id ) : null;
	$db_token   = $ticket_id ? swh_get_string_meta( $ticket_id, '_ticket_token' ) : '';
	$portal_url = $ticket_id ? swh_get_secure_ticket_link( $ticket_id ) : '';

	if (
		! $post ||
		'helpdesk_ticket' !== $post->post_type ||
		'' === $db_token ||
		'' === $token ||
		! hash_equals( $db_token, $token ) ||
		! wp_verify_nonce( $nonce_val, 'swh_portal_reply' )
	) {
		wp_die( esc_html__( 'Invalid request.', 'simple-wp-helpdesk' ), 403 );
	}

	if ( swh_is_rate_limited( 'portal_close_' . $ticket_id, 30 ) ) {
		if ( $portal_url ) {
			wp_safe_redirect( $portal_url );
			exit;
		}
		wp_die( esc_html__( 'Please wait before submitting again.', 'simple-wp-helpdesk' ), 429 );
	}

	$defs          = swh_get_defaults();
	$closed_status = swh_get_string_option( 'swh_closed_status', is_string( $defs['swh_closed_status'] ) ? $defs['swh_closed_status'] : '' );
	$ticket_name   = swh_get_string_meta( $ticket_id, '_ticket_name' );
	$meta_email    = swh_get_string_meta( $ticket_id, '_ticket_email' );

	swh_set_ticket_status( $ticket_id, $closed_status );
	delete_post_meta( $ticket_id, '_resolved_timestamp' );

	$comment_id = wp_insert_comment(
		array(
			'comment_post_ID'      => $ticket_id,
			'comment_author'       => $ticket_name ? $ticket_name : 'Client',
			'comment_author_email' => $meta_email,
			'comment_content'      => __( 'TICKET CLOSED BY CLIENT', 'simple-wp-helpdesk' ),
			'comment_approved'     => 1,
			'comment_type'         => 'helpdesk_reply',
		)
	);
	if ( $comment_id && ! is_wp_error( $comment_id ) ) {
		update_comment_meta( (int) $comment_id, '_is_user_reply', '1' );
		update_post_meta( $ticket_id, '_swh_last_activity_at', time() );
	}
	swh_fire_ticket_replied( $ticket_id, $comment_id, 0 );

	$data        = array(
		'name'       => $ticket_name ? $ticket_name : 'Client',
		'email'      => $meta_email,
		'ticket_id'  => swh_get_string_meta( $ticket_id, '_ticket_uid' ),
		'title'      => $post->post_title,
		'status'     => $closed_status,
		'priority'   => swh_get_string_meta( $ticket_id, '_ticket_priority' ),
		'ticket_url' => $portal_url,
		'admin_url'  => swh_admin_ticket_url( $ticket_id ),
		'message'    => '',
	);
	$admin_email = swh_get_admin_email( $ticket_id );
	swh_send_email( $admin_email, 'swh_em_admin_closed_sub', 'swh_em_admin_closed_body', $data );
	swh_send_email( $meta_email, 'swh_em_user_closed_sub', 'swh_em_user_closed_body', $data );

	if ( $portal_url ) {
		wp_safe_redirect( $portal_url );
		exit;
	}
	wp_die( esc_html__( 'Ticket closed.', 'simple-wp-helpdesk' ) );
}

add_action( 'admin_post_nopriv_swh_portal_reopen', 'swh_handle_portal_v2_reopen' );
add_action( 'admin_post_swh_portal_reopen', 'swh_handle_portal_v2_reopen' );

/**
 * Handles the portal v2 reopen-ticket action (admin-post.php pathway).
 *
 * @return void
 */
function swh_handle_portal_v2_reopen() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified via wp_verify_nonce() below.
	$ticket_id = isset( $_POST['ticket_id'] ) && is_scalar( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
	$token     = sanitize_text_field( wp_unslash( isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? $_POST['token'] : '' ) );
	$nonce_val = sanitize_text_field( wp_unslash( isset( $_POST['swh_portal_reply_nonce'] ) && is_string( $_POST['swh_portal_reply_nonce'] ) ? $_POST['swh_portal_reply_nonce'] : '' ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$post       = $ticket_id ? get_post( $ticket_id ) : null;
	$db_token   = $ticket_id ? swh_get_string_meta( $ticket_id, '_ticket_token' ) : '';
	$portal_url = $ticket_id ? swh_get_secure_ticket_link( $ticket_id ) : '';

	if (
		! $post ||
		'helpdesk_ticket' !== $post->post_type ||
		'' === $db_token ||
		'' === $token ||
		! hash_equals( $db_token, $token ) ||
		! wp_verify_nonce( $nonce_val, 'swh_portal_reply' )
	) {
		wp_die( esc_html__( 'Invalid request.', 'simple-wp-helpdesk' ), 403 );
	}

	if ( swh_is_rate_limited( 'portal_reopen_' . $ticket_id, 30 ) ) {
		if ( $portal_url ) {
			wp_safe_redirect( $portal_url );
			exit;
		}
		wp_die( esc_html__( 'Please wait before submitting again.', 'simple-wp-helpdesk' ), 429 );
	}

	$defs            = swh_get_defaults();
	$reopened_status = swh_get_string_option( 'swh_reopened_status', is_string( $defs['swh_reopened_status'] ) ? $defs['swh_reopened_status'] : '' );
	$ticket_name     = swh_get_string_meta( $ticket_id, '_ticket_name' );
	$meta_email      = swh_get_string_meta( $ticket_id, '_ticket_email' );

	swh_set_ticket_status( $ticket_id, $reopened_status );
	delete_post_meta( $ticket_id, '_resolved_timestamp' );

	$comment_id = wp_insert_comment(
		array(
			'comment_post_ID'      => $ticket_id,
			'comment_author'       => $ticket_name ? $ticket_name : 'Client',
			'comment_author_email' => $meta_email,
			'comment_content'      => __( 'TICKET RE-OPENED BY CLIENT', 'simple-wp-helpdesk' ),
			'comment_approved'     => 1,
			'comment_type'         => 'helpdesk_reply',
		)
	);
	if ( $comment_id && ! is_wp_error( $comment_id ) ) {
		update_comment_meta( (int) $comment_id, '_is_user_reply', '1' );
		update_post_meta( $ticket_id, '_swh_last_activity_at', time() );
		update_post_meta( $ticket_id, '_swh_unread', '1' );
		delete_transient( 'swh_unread_count' );
	}
	swh_fire_ticket_replied( $ticket_id, $comment_id, 0 );

	$data        = array(
		'name'       => $ticket_name ? $ticket_name : 'Client',
		'email'      => $meta_email,
		'ticket_id'  => swh_get_string_meta( $ticket_id, '_ticket_uid' ),
		'title'      => $post->post_title,
		'status'     => $reopened_status,
		'priority'   => swh_get_string_meta( $ticket_id, '_ticket_priority' ),
		'ticket_url' => $portal_url,
		'admin_url'  => swh_admin_ticket_url( $ticket_id ),
		'message'    => '',
	);
	$admin_email = swh_get_admin_email( $ticket_id );
	swh_send_email( $admin_email, 'swh_em_admin_reopen_sub', 'swh_em_admin_reopen_body', $data );
	swh_send_email( $meta_email, 'swh_em_user_reopen_sub', 'swh_em_user_reopen_body', $data );

	if ( $portal_url ) {
		wp_safe_redirect( $portal_url );
		exit;
	}
	wp_die( esc_html__( 'Ticket re-opened.', 'simple-wp-helpdesk' ) );
}

add_action( 'admin_post_nopriv_swh_portal_csat', 'swh_handle_portal_v2_csat' );
add_action( 'admin_post_swh_portal_csat', 'swh_handle_portal_v2_csat' );

/**
 * Handles the portal v2 CSAT menu action — opens portal with CSAT widget visible.
 *
 * The CSAT widget already renders via AJAX (wp_ajax_nopriv_swh_submit_csat).
 * This handler just redirects to the portal URL so the menu action has somewhere
 * to go; the actual rating is submitted via the existing AJAX endpoint.
 *
 * @return void
 */
function swh_handle_portal_v2_csat() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- token auth only; no nonce needed for redirect.
	$ticket_id = isset( $_POST['ticket_id'] ) && is_scalar( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
	$token     = sanitize_text_field( wp_unslash( isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? $_POST['token'] : '' ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	$post     = $ticket_id ? get_post( $ticket_id ) : null;
	$db_token = $ticket_id ? swh_get_string_meta( $ticket_id, '_ticket_token' ) : '';
	if ( ! $post || '' === $db_token || '' === $token || ! hash_equals( $db_token, $token ) ) {
		wp_die( esc_html__( 'Invalid request.', 'simple-wp-helpdesk' ), 403 );
	}
	$portal_url = swh_get_secure_ticket_link( $ticket_id );
	if ( $portal_url ) {
		wp_safe_redirect( $portal_url );
		exit;
	}
	wp_die( esc_html__( 'Invalid request.', 'simple-wp-helpdesk' ), 403 );
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
	$spam_method = swh_get_option( 'tools', 'spam_method', 'none' );

	if ( isset( $_POST['swh_ticket_lookup'], $_POST['swh_lookup_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( is_string( $_POST['swh_lookup_nonce'] ) ? $_POST['swh_lookup_nonce'] : '' ) ), 'swh_ticket_lookup' ) ) {
		if ( swh_is_rate_limited( 'lookup', 60 ) ) {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html__( 'Please wait a moment before submitting again.', 'simple-wp-helpdesk' ) . '</div>';
		} elseif ( swh_check_antispam( false ) ) {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( swh_get_string_option( 'swh_msg_err_spam', is_string( $defs['swh_msg_err_spam'] ) ? $defs['swh_msg_err_spam'] : '' ) ) . '</div>';
		} else {
			$lookup_email = isset( $_POST['swh_lookup_email'] ) && is_string( $_POST['swh_lookup_email'] ) ? sanitize_email( wp_unslash( $_POST['swh_lookup_email'] ) ) : '';
			if ( $lookup_email ) {
				$closed_status = swh_get_option( 'general', 'closed_status', $defs['swh_closed_status'] );
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
							error_log( 'SWH: swh_get_secure_ticket_link() returned false for ticket ' . $lt->ID . ' during lookup (email hash: ' . substr( md5( $lookup_email ), 0, 8 ) . ')' );
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
						error_log( 'SWH: lookup email skipped (email hash: ' . substr( md5( $lookup_email ), 0, 8 ) . ') — no usable ticket links (portal page not configured or pre-v1.9.0 tickets without tokens).' );
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
			<div aria-hidden="true" style="clip-path:inset(50%);height:1px;overflow:hidden;position:absolute;white-space:nowrap;width:1px;"><label aria-hidden="true"><?php esc_html_e( 'Leave this empty', 'simple-wp-helpdesk' ); ?></label><input type="text" name="swh_website_url_hp" value="" tabindex="-1" autocomplete="off"></div>
		<?php endif; ?>
		<div class="swh-form-group">
			<input type="submit" name="swh_ticket_lookup" value="<?php esc_attr_e( 'Send My Ticket Links', 'simple-wp-helpdesk' ); ?>" class="swh-btn">
		</div>
	</form>
	<?php
}
