<?php
/**
 * Ticket editor: meta boxes, save handler, and conversation UI for the admin ticket edit screen.
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks into admin_notices to display the Helpdesk Page configuration warning.
 *
 * @since 2.0.0
 * @see swh_admin_helpdesk_page_notice()
 */
add_action( 'admin_notices', 'swh_admin_helpdesk_page_notice' );
/**
 * Displays an admin notice when the Helpdesk Page setting is not configured.
 *
 * @since 2.0.0
 * @return void
 */
function swh_admin_helpdesk_page_notice() {
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( $screen->id, 'helpdesk_ticket' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$page_id = (int) get_option( 'swh_ticket_page_id', 0 );
	if ( $page_id && get_post( $page_id ) ) {
		return;
	}
	echo '<div class="notice notice-warning is-dismissible"><p>';
	echo '<strong>' . esc_html__( 'Simple WP Helpdesk:', 'simple-wp-helpdesk' ) . '</strong> ' . esc_html__( 'The Helpdesk Page setting is not configured. Admin-created tickets will not have a client portal URL.', 'simple-wp-helpdesk' ) . ' ';
	echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=helpdesk_ticket&page=swh-settings' ) ) . '">' . esc_html__( 'Configure it under Settings &rarr; Assignment & Routing.', 'simple-wp-helpdesk' ) . '</a>';
	echo '</p></div>';
}

/**
 * Hooks into admin_notices to display the post-reassignment success notice.
 *
 * @since 2.0.0
 * @see swh_reassigned_notice()
 */
add_action( 'admin_notices', 'swh_reassigned_notice' );
/**
 * Displays an admin notice after a ticket is successfully reassigned.
 *
 * Reads the `swh_reassigned` GET parameter set by the post-save redirect.
 *
 * @since 2.0.0
 * @return void
 */
function swh_reassigned_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET param set by server redirect; used only for display.
	if ( ! isset( $_GET['swh_reassigned'] ) ) {
		return;
	}
	echo '<div class="notice notice-success is-dismissible"><p>';
	echo esc_html__( 'Ticket reassigned successfully.', 'simple-wp-helpdesk' );
	echo '</p></div>';
}

/**
 * Hooks into add_meta_boxes to register the Ticket Details and Conversation & Reply meta boxes.
 *
 * @since 2.0.0
 * @see swh_add_ticket_meta_boxes()
 */
add_action( 'add_meta_boxes', 'swh_add_ticket_meta_boxes' );
/**
 * Registers the Ticket Details (side) and Conversation & Reply (normal) meta boxes.
 *
 * @since 2.0.0
 * @return void
 */
function swh_add_ticket_meta_boxes() {
	add_meta_box( 'swh_ticket_status', __( 'Ticket Details', 'simple-wp-helpdesk' ), 'swh_status_meta_box_html', 'helpdesk_ticket', 'side', 'high' );
	add_meta_box( 'swh_ticket_conversation', __( 'Conversation & Reply', 'simple-wp-helpdesk' ), 'swh_conversation_meta_box_html', 'helpdesk_ticket', 'normal', 'high' );
}

/**
 * Renders the Ticket Details side meta box.
 *
 * Displays ticket UID, client name/email, all attachments, assigned-to selector,
 * priority selector, and status selector. Outputs a nonce field for save_post.
 *
 * @since 2.0.0
 * @param WP_Post $post The current ticket post object.
 * @return void
 */
function swh_status_meta_box_html( $post ) {
	$defs     = swh_get_defaults();
	$uid      = get_post_meta( $post->ID, '_ticket_uid', true );
	$status   = get_post_meta( $post->ID, '_ticket_status', true ) ? get_post_meta( $post->ID, '_ticket_status', true ) : get_option( 'swh_default_status', $defs['swh_default_status'] );
	$priority = get_post_meta( $post->ID, '_ticket_priority', true ) ? get_post_meta( $post->ID, '_ticket_priority', true ) : get_option( 'swh_default_priority', $defs['swh_default_priority'] );
	$assignee = get_post_meta( $post->ID, '_ticket_assigned_to', true );
	$name     = get_post_meta( $post->ID, '_ticket_name', true ) ? get_post_meta( $post->ID, '_ticket_name', true ) : 'Unknown User';
	$email    = get_post_meta( $post->ID, '_ticket_email', true );

	$statuses   = swh_get_statuses();
	$priorities = swh_get_priorities();
	$techs      = get_users( array( 'role__in' => array( 'administrator', 'technician' ) ) );

	if ( $status && ! in_array( $status, $statuses, true ) ) {
		$statuses[] = $status;
	}
	if ( $priority && ! in_array( $priority, $priorities, true ) ) {
		$priorities[] = $priority;
	}
	wp_nonce_field( 'swh_save_ticket', 'swh_ticket_nonce' );
	$is_new_ticket = empty( $uid );
	?>
	<div style="font-size: 16px; font-weight: bold; background: #f0f0f1; padding: 10px; text-align: center; margin-bottom: 15px;">
		<?php echo $is_new_ticket ? esc_html__( 'New Ticket', 'simple-wp-helpdesk' ) : esc_html__( 'ID:', 'simple-wp-helpdesk' ) . ' ' . esc_html( $uid ); ?>
	</div>
	<p style="margin-bottom: 5px;"><label for="swh-client-name"><strong><?php esc_html_e( 'Client Name:', 'simple-wp-helpdesk' ); ?></strong></label></p>
	<input type="text" id="swh-client-name" name="ticket_client_name" value="<?php echo esc_attr( 'Unknown User' !== $name ? $name : '' ); ?>" placeholder="<?php esc_attr_e( 'Client name', 'simple-wp-helpdesk' ); ?>" style="width:100%; margin-bottom:8px;">
	<p style="margin-bottom: 5px;"><label for="swh-client-email"><strong><?php esc_html_e( 'Client Email:', 'simple-wp-helpdesk' ); ?></strong></label></p>
	<input type="email" id="swh-client-email" name="ticket_client_email" value="<?php echo esc_attr( $email ); ?>" placeholder="client@example.com" style="width:100%; margin-bottom:8px;">
	<?php if ( $is_new_ticket ) : ?>
	<p><label><input type="checkbox" name="swh_send_client_email" value="1"> <?php esc_html_e( 'Send confirmation email to client', 'simple-wp-helpdesk' ); ?></label></p>
	<?php elseif ( $email ) : ?>
	<p style="font-size:12px; color:#666;"><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></p>
	<?php endif; ?>
	<?php
	$main_attachments  = get_post_meta( $post->ID, '_ticket_attachments', true ) ? get_post_meta( $post->ID, '_ticket_attachments', true ) : array();
	$main_orignames    = get_post_meta( $post->ID, '_swh_attachment_orignames', true );
	$main_orignames    = is_array( $main_orignames ) ? $main_orignames : array();
	$comments          = get_comments(
		array(
			'post_id' => $post->ID,
			'order'   => 'ASC',
		)
	);
	$comments          = is_array( $comments ) ? $comments : array();
	$reply_attachments = array();
	$reply_orignames   = array();
	foreach ( $comments as $c ) {
		if ( ! $c instanceof WP_Comment ) {
			continue;
		}
		$atts = get_comment_meta( (int) $c->comment_ID, '_attachments', true );
		if ( ! empty( $atts ) && is_array( $atts ) ) {
			$reply_attachments = array_merge( $reply_attachments, $atts );
		}
		$c_names = get_comment_meta( (int) $c->comment_ID, '_swh_reply_orignames', true );
		if ( ! empty( $c_names ) && is_array( $c_names ) ) {
			$reply_orignames = array_merge( $reply_orignames, $c_names );
		}
	}
	$all_attachments = array_merge( $main_attachments, $reply_attachments );
	$all_orignames   = array_merge( $main_orignames, $reply_orignames );
	if ( ! empty( $all_attachments ) ) :
		?>
		<p><strong><?php esc_html_e( 'All Attachments:', 'simple-wp-helpdesk' ); ?></strong><br>
		<?php foreach ( $all_attachments as $url ) : ?>
			<?php $label = ! empty( $all_orignames[ $url ] ) ? $all_orignames[ $url ] : basename( $url ); ?>
			<a href="<?php echo esc_url( swh_get_file_proxy_url( $url, $post->ID ) ); ?>" target="_blank" class="button button-secondary button-small" style="margin-top:5px; margin-right:5px;"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?></p>
	<?php endif; ?>
	<hr>
	<p><label for="swh-assigned-to"><strong><?php esc_html_e( 'Assigned To:', 'simple-wp-helpdesk' ); ?></strong></label></p>
	<select id="swh-assigned-to" name="ticket_assigned_to" style="width: 100%; margin-bottom: 10px;">
		<option value=""><?php echo '-- ' . esc_html__( 'Unassigned', 'simple-wp-helpdesk' ) . ' --'; ?></option>
		<?php foreach ( $techs as $t ) : ?>
			<option value="<?php echo esc_attr( $t->ID ); ?>" <?php selected( $assignee, $t->ID ); ?>><?php echo esc_html( $t->display_name ); ?></option>
		<?php endforeach; ?>
	</select>
	<p><label for="swh-priority"><strong><?php esc_html_e( 'Priority:', 'simple-wp-helpdesk' ); ?></strong></label></p>
	<select id="swh-priority" name="ticket_priority" style="width: 100%; margin-bottom: 10px;">
		<?php foreach ( $priorities as $p ) : ?>
			<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $priority, $p ); ?>><?php echo esc_html( $p ); ?></option>
		<?php endforeach; ?>
	</select>
	<p><label for="swh-status"><strong><?php esc_html_e( 'Status:', 'simple-wp-helpdesk' ); ?></strong></label></p>
	<select id="swh-status" name="ticket_status" style="width: 100%;">
		<?php foreach ( $statuses as $s ) : ?>
			<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( $s ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * Renders the Conversation & Reply meta box.
 *
 * Shows all ticket comments (replies, internal notes, system messages) in a scrollable log,
 * then two side-by-side textareas for adding a public reply or internal note.
 * Attachments on individual replies are displayed as download links.
 *
 * @since 2.0.0
 * @param WP_Post $post The current ticket post object.
 * @return void
 */
function swh_conversation_meta_box_html( $post ) {
	$comments = get_comments(
		array(
			'post_id' => $post->ID,
			'order'   => 'ASC',
		)
	);
	$comments = is_array( $comments ) ? $comments : array();
	echo '<div role="log" aria-label="' . esc_attr__( 'Ticket Conversation', 'simple-wp-helpdesk' ) . '" style="max-height: 400px; overflow-y: auto; background: #fff; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px;">';
	if ( $comments ) {
		foreach ( $comments as $comment ) {
			if ( ! $comment instanceof WP_Comment ) {
				continue;
			}
			$is_internal = get_comment_meta( (int) $comment->comment_ID, '_is_internal_note', true );
			$is_user     = get_comment_meta( (int) $comment->comment_ID, '_is_user_reply', true );

			if ( $is_internal ) {
				/* translators: %s: comment author name */
				$author_label = sprintf( __( 'Internal Note (%s)', 'simple-wp-helpdesk' ), $comment->comment_author );
				$bg_color     = '#fff3cd';
				$border       = '#ffeeba';
			} else {
				/* translators: %s: comment author name */
				$author_label = $is_user ? sprintf( __( 'Client (%s)', 'simple-wp-helpdesk' ), $comment->comment_author ) : sprintf( __( 'Technician (%s)', 'simple-wp-helpdesk' ), $comment->comment_author );
				$bg_color     = $is_user ? '#f9f9f9' : '#e6f7ff';
				$border       = '#0073aa';
			}

			echo '<div style="background: ' . esc_attr( $bg_color ) . '; padding: 10px 15px; margin-bottom: 10px; border-left: 4px solid ' . esc_attr( $border ) . '; border-radius: 3px;">';
			echo '<strong style="display:block; margin-bottom: 5px;">' . esc_html( $author_label ) . ' <span style="font-weight:normal; font-size: 0.8em; color: #666;">(' . esc_html( $comment->comment_date ) . ')</span></strong>';
			echo nl2br( esc_html( $comment->comment_content ) );

			$attachments = get_comment_meta( (int) $comment->comment_ID, '_attachments', true );
			if ( ! empty( $attachments ) && is_array( $attachments ) ) {
				echo '<div style="margin-top: 10px;">';
				foreach ( $attachments as $url ) {
					echo '<a href="' . esc_url( swh_get_file_proxy_url( $url, $post->ID ) ) . '" target="_blank" class="button button-small" style="margin-right:5px;">' . esc_html( basename( $url ) ) . '</a>';
				}
				echo '</div>';
			}
			echo '</div>';
		}
	} else {
		echo '<p style="color: #666; font-style: italic;">' . esc_html__( 'No replies yet. Use the boxes below to start the conversation.', 'simple-wp-helpdesk' ) . '</p>';
	}
	echo '</div>';
	?>
	<div style="display:flex; gap: 20px;">
		<div style="flex:1;">
			<h4 style="margin-top:0;"><label for="swh-tech-reply-text"><?php esc_html_e( 'Add a Public Reply', 'simple-wp-helpdesk' ); ?></label></h4>
			<p style="font-size:12px;"><?php esc_html_e( 'This will be emailed to the client.', 'simple-wp-helpdesk' ); ?></p>
			<?php
			$swh_canned = get_option( 'swh_canned_responses', array() );
			if ( is_array( $swh_canned ) && ! empty( $swh_canned ) ) :
				?>
			<p style="margin-bottom:6px;">
				<label for="swh-canned-select" style="font-size:12px; font-weight:600;"><?php esc_html_e( 'Insert Canned Response:', 'simple-wp-helpdesk' ); ?></label><br>
				<select id="swh-canned-select" style="max-width:300px; margin-right:6px; margin-top:3px;">
					<option value=""><?php esc_html_e( '— Select a response —', 'simple-wp-helpdesk' ); ?></option>
					<?php foreach ( $swh_canned as $swh_cr_item ) : ?>
						<option value="<?php echo esc_attr( isset( $swh_cr_item['body'] ) ? $swh_cr_item['body'] : '' ); ?>"><?php echo esc_html( isset( $swh_cr_item['title'] ) ? $swh_cr_item['title'] : '' ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" id="swh-canned-insert" class="button button-small"><?php esc_html_e( 'Insert', 'simple-wp-helpdesk' ); ?></button>
			</p>
			<?php endif; ?>
			<textarea id="swh-tech-reply-text" name="swh_tech_reply_text" style="width: 100%;" rows="5" placeholder="<?php esc_attr_e( 'Type reply here...', 'simple-wp-helpdesk' ); ?>"></textarea>
			<p><label for="swh-tech-reply-files"><strong><?php esc_html_e( 'Attach Files (Optional):', 'simple-wp-helpdesk' ); ?></strong></label><br>
			<input type="file" id="swh-tech-reply-files" name="swh_tech_reply_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt">
			<br><small style="color:#666;"><?php esc_html_e( 'Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT.', 'simple-wp-helpdesk' ); ?></small>
			</p>
		</div>
		<div style="flex:1; background: #fff3cd; padding: 15px; border-radius: 5px; border: 1px solid #ffeeba;">
			<h4 style="margin-top:0; color: #856404;"><label for="swh-tech-note-text"><?php esc_html_e( 'Add Internal Note', 'simple-wp-helpdesk' ); ?></label></h4>
			<p style="font-size:12px; color: #856404;"><?php esc_html_e( 'Hidden from client. For staff only.', 'simple-wp-helpdesk' ); ?></p>
			<textarea id="swh-tech-note-text" name="swh_tech_note_text" style="width: 100%;" rows="5" placeholder="<?php esc_attr_e( 'Type private note here...', 'simple-wp-helpdesk' ); ?>"></textarea>
		</div>
	</div>
	<p class="description">
	<?php
	/* translators: "Update" refers to the WordPress post editor button */
	printf( esc_html__( 'Click the %s button on the top right to save the ticket.', 'simple-wp-helpdesk' ), '<strong>' . esc_html__( 'Update', 'simple-wp-helpdesk' ) . '</strong>' );
	?>
	</p>
	<?php
}

/**
 * Hooks into save_post_helpdesk_ticket to save ticket meta, replies, uploads, and send emails.
 *
 * @since 2.0.0
 * @see swh_save_ticket_data()
 */
add_action( 'save_post_helpdesk_ticket', 'swh_save_ticket_data', 10, 3 );
/**
 * Handles saving ticket meta, reply comments, file uploads, and outbound emails on post save.
 *
 * Validates nonce and capability, then:
 * - Updates status, priority, assignee (with whitelist validation).
 * - Bootstraps UID/token for new admin-created tickets.
 * - Inserts public reply comment and/or internal note comment.
 * - Handles file uploads for reply attachments.
 * - Sends appropriate email (reply, status change, resolved, reassignment, confirmation).
 * - Sets/clears `_resolved_timestamp` when ticket enters/leaves resolved status.
 *
 * @since 2.0.0
 * @param int     $post_id The ticket post ID.
 * @param WP_Post $post    The ticket post object.
 * @param bool    $update  True if this is an update, false for a new post.
 * @return void
 */
function swh_save_ticket_data( $post_id, $post, $update ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['swh_ticket_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_ticket_nonce'] ) ), 'swh_save_ticket' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$defs            = swh_get_defaults();
	$old_status      = get_post_meta( $post_id, '_ticket_status', true );
	$old_assigned_to = (int) get_post_meta( $post_id, '_ticket_assigned_to', true );
	$new_status      = isset( $_POST['ticket_status'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_status'] ) ) : '';
	$new_priority    = isset( $_POST['ticket_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_priority'] ) ) : '';
	$assigned_to     = isset( $_POST['ticket_assigned_to'] ) ? absint( $_POST['ticket_assigned_to'] ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

	// Validate status and priority against configured lists.
	$allowed_statuses   = swh_get_statuses();
	$allowed_priorities = swh_get_priorities();
	if ( $new_status && ! in_array( $new_status, $allowed_statuses, true ) ) {
		$new_status = $old_status; // Reject unknown status; keep existing.
	}
	if ( $new_priority && ! in_array( $new_priority, $allowed_priorities, true ) ) {
		$new_priority = get_post_meta( $post_id, '_ticket_priority', true );
	}
	// Validate assignee: must be an administrator or technician (0 = unassigned).
	if ( $assigned_to ) {
		$assignee_data = get_userdata( $assigned_to );
		if ( ! $assignee_data || empty( array_intersect( array( 'administrator', 'technician' ), (array) $assignee_data->roles ) ) ) {
			$assigned_to = 0;
		}
	}

	update_post_meta( $post_id, '_ticket_status', $new_status );
	update_post_meta( $post_id, '_ticket_priority', $new_priority );
	update_post_meta( $post_id, '_ticket_assigned_to', $assigned_to ? $assigned_to : '' );

	// When ticket is reassigned, clear the post lock and suppress lock reads for
	// 3 minutes. A simple delete_post_meta is not enough because the previous
	// editor's browser heartbeat can re-set the lock before the page reloads.
	if ( $assigned_to !== $old_assigned_to ) {
		delete_post_meta( $post_id, '_edit_lock' );
		set_transient( 'swh_lock_clear_' . $post_id, 1, 3 * MINUTE_IN_SECONDS );
		add_filter(
			'redirect_post_location',
			function () {
				return admin_url( 'edit.php?post_type=helpdesk_ticket&swh_reassigned=1' );
			}
		);
	}

	// Save editable client name/email (admin-created tickets or corrections).
	// Must run before the bootstrap and assignment notification so meta is current
	// when swh_get_secure_ticket_link() and email templates are called below.
	$client_name  = isset( $_POST['ticket_client_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_client_name'] ) ) : '';
	$client_email = isset( $_POST['ticket_client_email'] ) ? sanitize_email( wp_unslash( $_POST['ticket_client_email'] ) ) : '';
	if ( $client_name ) {
		update_post_meta( $post_id, '_ticket_name', $client_name );
	}
	if ( $client_email ) {
		update_post_meta( $post_id, '_ticket_email', $client_email );
	}

	// For admin-created tickets that have no UID yet, bootstrap the ticket identity.
	// Must run before the assignment notification so _ticket_token exists when
	// swh_get_secure_ticket_link() is called to build the portal URL.
	if ( ! get_post_meta( $post_id, '_ticket_uid', true ) ) {
		$uid   = 'TKT-' . str_pad( (string) $post_id, 4, '0', STR_PAD_LEFT );
		$token = wp_generate_password( 20, false );
		update_post_meta( $post_id, '_ticket_uid', $uid );
		update_post_meta( $post_id, '_ticket_token', $token );
		update_post_meta( $post_id, '_ticket_token_created', time() );
		$portal_page_id = (int) get_option( 'swh_ticket_page_id', 0 );
		if ( $portal_page_id ) {
			update_post_meta( $post_id, '_ticket_url', get_permalink( $portal_page_id ) );
		}
		// Send confirmation email to client if email is set and checkbox is checked.
		if ( isset( $_POST['swh_send_client_email'] ) && $client_email ) {
			$ticket_link = swh_get_secure_ticket_link( $post_id );
			if ( $ticket_link ) {
				$new_data = array(
					'name'           => $client_name ? $client_name : 'Client',
					'email'          => $client_email,
					'ticket_id'      => $uid,
					'title'          => $post->post_title,
					'status'         => $new_status,
					'priority'       => $new_priority,
					'ticket_url'     => $ticket_link,
					'admin_url'      => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
					'message'        => '',
					'autoclose_days' => get_option( 'swh_autoclose_days', $defs['swh_autoclose_days'] ),
				);
				swh_send_email( $client_email, 'swh_em_user_new_sub', 'swh_em_user_new_body', $new_data );
			}
		}
	}

	// Send assignment notification when a ticket is newly assigned or reassigned.
	// Token and client meta are now guaranteed to exist before this block runs.
	if ( $assigned_to && $assigned_to !== $old_assigned_to ) {
		$assignee_user = get_userdata( $assigned_to );
		if ( $assignee_user->user_email ) {
			$assign_data = array(
				'name'           => get_post_meta( $post_id, '_ticket_name', true ) ? get_post_meta( $post_id, '_ticket_name', true ) : 'Client',
				'email'          => get_post_meta( $post_id, '_ticket_email', true ),
				'ticket_id'      => get_post_meta( $post_id, '_ticket_uid', true ) ? get_post_meta( $post_id, '_ticket_uid', true ) : 'TKT-' . str_pad( (string) $post_id, 4, '0', STR_PAD_LEFT ),
				'title'          => $post->post_title,
				'status'         => $new_status,
				'priority'       => $new_priority,
				'ticket_url'     => swh_get_secure_ticket_link( $post_id ),
				'admin_url'      => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
				'message'        => '',
				'autoclose_days' => get_option( 'swh_autoclose_days', $defs['swh_autoclose_days'] ),
			);
			swh_send_email( $assignee_user->user_email, 'swh_em_assigned_sub', 'swh_em_assigned_body', $assign_data );
		}
	}

	$resolved_status = get_option( 'swh_resolved_status', $defs['swh_resolved_status'] );
	if ( $resolved_status === $new_status && $old_status !== $new_status ) {
		update_post_meta( $post_id, '_resolved_timestamp', time() );
	} elseif ( $resolved_status === $old_status && $resolved_status !== $new_status ) {
		delete_post_meta( $post_id, '_resolved_timestamp' );
	}

	$data = array(
		'name'           => get_post_meta( $post_id, '_ticket_name', true ) ? get_post_meta( $post_id, '_ticket_name', true ) : 'Client',
		'email'          => get_post_meta( $post_id, '_ticket_email', true ),
		'ticket_id'      => get_post_meta( $post_id, '_ticket_uid', true ),
		'title'          => $post->post_title,
		'status'         => $new_status,
		'priority'       => $new_priority,
		'autoclose_days' => get_option( 'swh_autoclose_days', 3 ),
		'ticket_url'     => swh_get_secure_ticket_link( $post_id ),
		'admin_url'      => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		'message'        => '',
	);

	$current_user = wp_get_current_user();

	if ( ! empty( $_POST['swh_tech_note_text'] ) ) {
		$note_text  = sanitize_textarea_field( wp_unslash( $_POST['swh_tech_note_text'] ) );
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => $current_user->display_name,
				'comment_author_email' => $current_user->user_email,
				'comment_content'      => $note_text,
				'comment_approved'     => 1,
				'comment_type'         => 'helpdesk_reply',
			)
		);
		if ( $comment_id ) {
			update_comment_meta( $comment_id, '_is_internal_note', '1' );
		}
	}

	$just_replied = false;
	$attach_urls  = array();
	$reply_text   = isset( $_POST['swh_tech_reply_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['swh_tech_reply_text'] ) ) : '';

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$has_files = ! empty( $_FILES['swh_tech_reply_attachments']['name'][0] );

	if ( $reply_text || $has_files ) {
		$just_replied    = true;
		$comment_content = $reply_text ? $reply_text : __( 'Attached file(s)', 'simple-wp-helpdesk' );
		$comment_id      = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => $current_user->display_name,
				'comment_author_email' => $current_user->user_email,
				'comment_content'      => $comment_content,
				'comment_approved'     => 1,
				'comment_type'         => 'helpdesk_reply',
			)
		);
		if ( $comment_id ) {
			update_comment_meta( $comment_id, '_is_user_reply', '0' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tech_orignames = null;
		$attach_urls    = swh_handle_multiple_uploads( $_FILES['swh_tech_reply_attachments'], $tech_orignames );
		if ( $comment_id && ! empty( $attach_urls ) ) {
			update_comment_meta( $comment_id, '_attachments', $attach_urls );
			if ( ! empty( $tech_orignames ) ) {
				update_comment_meta( $comment_id, '_swh_reply_orignames', $tech_orignames );
			}
		}
		$data['message'] = $reply_text ? $reply_text : __( 'Attached file(s)', 'simple-wp-helpdesk' );
	}

	$status_changed = ( $update && $old_status !== $new_status );
	$is_resolving   = ( $resolved_status === $new_status );

	$proxy_attach_urls = array_map(
		function ( $url ) use ( $post_id ) {
			return swh_get_file_proxy_url( $url, $post_id );
		},
		$attach_urls
	);

	if ( $data['email'] ) {
		if ( $just_replied && $status_changed && $is_resolving ) {
			swh_send_email( $data['email'], 'swh_em_user_resolved_sub', 'swh_em_user_resolved_body', $data, $proxy_attach_urls );
		} elseif ( $just_replied && $status_changed ) {
			swh_send_email( $data['email'], 'swh_em_user_reply_status_sub', 'swh_em_user_reply_status_body', $data, $proxy_attach_urls );
		} elseif ( $just_replied ) {
			swh_send_email( $data['email'], 'swh_em_user_reply_sub', 'swh_em_user_reply_body', $data, $proxy_attach_urls );
		} elseif ( $status_changed ) {
			$data['message'] = __( 'No additional notes provided.', 'simple-wp-helpdesk' );
			if ( $is_resolving ) {
				swh_send_email( $data['email'], 'swh_em_user_resolved_sub', 'swh_em_user_resolved_body', $data );
			} elseif ( get_option( 'swh_reopened_status', $defs['swh_reopened_status'] ) === $new_status && get_option( 'swh_closed_status', $defs['swh_closed_status'] ) === $old_status ) {
				swh_send_email( $data['email'], 'swh_em_user_reopen_sub', 'swh_em_user_reopen_body', $data );
			} else {
				swh_send_email( $data['email'], 'swh_em_user_status_sub', 'swh_em_user_status_body', $data );
			}
		}
	}
}
