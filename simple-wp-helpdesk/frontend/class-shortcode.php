<?php
/**
 * Frontend shortcodes: [submit_ticket] form, ticket lookup, and [helpdesk_portal] routing.
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the [submit_ticket] shortcode: renders the submission form and portal router. */
add_shortcode( 'submit_ticket', 'swh_ticket_frontend' );
/** Registers the [helpdesk_portal] shortcode: renders the client portal on a dedicated page. */
add_shortcode( 'helpdesk_portal', 'swh_helpdesk_portal_shortcode' );

/**
 * Main shortcode handler for [submit_ticket].
 *
 * Acts as a thin router: enqueues assets, then delegates to either the
 * client-portal view or the submission-form view.
 *
 * @return string Shortcode HTML output.
 */
function swh_ticket_frontend() {
	wp_enqueue_style( 'swh-frontend', SWH_PLUGIN_URL . 'assets/swh-frontend.css', array(), SWH_VERSION );
	wp_enqueue_script( 'swh-frontend', SWH_PLUGIN_URL . 'assets/swh-frontend.js', array(), SWH_VERSION, true );
	/**
	 * Filters the list of allowed file extensions for ticket attachments.
	 *
	 * @since 2.1.0
	 * @param string[] $exts Array of lowercase file extension strings.
	 */
	/* @var string[] $allowed_exts */ // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- PHPStan type annotation
	$allowed_exts = apply_filters( 'swh_allowed_file_types', array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt' ) );
	wp_localize_script(
		'swh-frontend',
		'swhConfig',
		array(
			'maxMb'       => (int) get_option( 'swh_max_upload_size', 5 ),
			'maxFiles'    => (int) get_option( 'swh_max_upload_count', 5 ),
			'allowedExts' => $allowed_exts,
			'i18n'        => array(
				/* translators: %d: maximum number of files allowed */
				'maxFilesError' => __( 'You may only attach up to %d file(s) per upload.', 'simple-wp-helpdesk' ),
				/* translators: %s: file name */
				'invalidType'   => __( 'File "%s" has an invalid type.', 'simple-wp-helpdesk' ),
				/* translators: 1: file name, 2: max file size in MB */
				'sizeExceeded'  => __( 'File "%1$s" exceeds the %2$dMB size limit.', 'simple-wp-helpdesk' ),
			),
		)
	);

	ob_start();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET params used for routing only; form actions verified via nonces.
	if ( isset( $_GET['swh_ticket'], $_GET['token'] ) ) {
		swh_render_client_portal();
	} else {
		swh_render_submission_form();
	}

	return ob_get_clean();
}

/**
 * Renders the ticket submission form and ticket lookup form.
 *
 * This is the default view when no ticket/token URL parameters are present.
 * Handles submission, validation, anti-spam, file uploads, and ticket lookup POST actions.
 *
 * @return void
 */
function swh_render_submission_form() {
	$defs            = swh_get_defaults();
	$closed_status   = get_option( 'swh_closed_status', $defs['swh_closed_status'] );
	$resolved_status = get_option( 'swh_resolved_status', $defs['swh_resolved_status'] );
	$reopened_status = get_option( 'swh_reopened_status', $defs['swh_reopened_status'] );
	$default_status  = get_option( 'swh_default_status', $defs['swh_default_status'] );
	$priorities      = swh_get_priorities();
	$default_prio    = get_option( 'swh_default_priority', $defs['swh_default_priority'] );
	$spam_method     = get_option( 'swh_spam_method', 'none' );
	?>
	<div class="swh-helpdesk-wrapper">
	<?php
	$current_user = wp_get_current_user();
	$form_name    = is_user_logged_in() ? $current_user->display_name : '';
	$form_email   = is_user_logged_in() ? $current_user->user_email : '';
	$form_prio    = $default_prio;
	$form_title   = '';
	$form_desc    = '';

	$submit_rate_passed = true;
	if ( isset( $_POST['swh_submit_ticket'] ) ) {
		if ( swh_is_rate_limited( 'submit', 30 ) ) {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html__( 'Please wait a moment before submitting again.', 'simple-wp-helpdesk' ) . '</div>';
			$submit_rate_passed = false;
		}
	}

	if ( $submit_rate_passed && isset( $_POST['swh_submit_ticket'], $_POST['swh_ticket_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_ticket_nonce'] ) ), 'swh_create_ticket' ) ) {
		$data = array(
			'name'     => isset( $_POST['ticket_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_name'] ) ) : '',
			'email'    => isset( $_POST['ticket_email'] ) ? sanitize_email( wp_unslash( $_POST['ticket_email'] ) ) : '',
			'title'    => isset( $_POST['ticket_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_title'] ) ) : '',
			'message'  => isset( $_POST['ticket_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_desc'] ) ) : '',
			'priority' => isset( $_POST['ticket_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_priority'] ) ) : '',
			'status'   => $default_status,
		);
		// Validate priority against allowed list.
		if ( ! in_array( $data['priority'], $priorities, true ) ) {
			$data['priority'] = $default_prio;
		}
		$is_spam = swh_check_antispam( true );

		if ( $is_spam ) {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( get_option( 'swh_msg_err_spam', $defs['swh_msg_err_spam'] ) ) . '</div>';
		} elseif ( $data['name'] && $data['title'] && $data['message'] && $data['email'] ) {
			/**
			 * Filters the ticket submission data array before the ticket is created.
			 *
			 * @since 2.1.0
			 * @param array<string, string> $data Sanitized submission fields: name, email, title, message, priority, status.
			 */
			$data = apply_filters( 'swh_submission_data', $data );
			/**
			 * Fires immediately before a new ticket is inserted.
			 *
			 * @since 2.1.0
			 * @param array<string, string> $data Sanitized submission data.
			 */
			do_action( 'swh_pre_ticket_create', $data );
			$ticket_id = wp_insert_post(
				array(
					'post_title'   => $data['title'],
					'post_content' => $data['message'],
					'post_type'    => 'helpdesk_ticket',
					'post_status'  => 'publish',
				)
			);
			if ( $ticket_id && ! is_wp_error( $ticket_id ) ) {
				$attach_urls = array();
				// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( ! empty( $_FILES['ticket_attachments']['name'][0] ) ) {
					$attach_urls = swh_handle_multiple_uploads( $_FILES['ticket_attachments'] );
				// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				}
				if ( ! empty( $attach_urls ) ) {
					update_post_meta( $ticket_id, '_ticket_attachments', $attach_urls );
				}
				$token             = wp_generate_password( 20, false );
				$data['ticket_id'] = 'TKT-' . str_pad( $ticket_id, 4, '0', STR_PAD_LEFT );
				$data['admin_url'] = admin_url( 'post.php?post=' . $ticket_id . '&action=edit' );
				update_post_meta( $ticket_id, '_ticket_uid', $data['ticket_id'] );
				update_post_meta( $ticket_id, '_ticket_name', $data['name'] );
				update_post_meta( $ticket_id, '_ticket_email', $data['email'] );
				update_post_meta( $ticket_id, '_ticket_status', $data['status'] );
				update_post_meta( $ticket_id, '_ticket_priority', $data['priority'] );
				update_post_meta( $ticket_id, '_ticket_token', $token );
				update_post_meta( $ticket_id, '_ticket_token_created', time() );
				update_post_meta( $ticket_id, '_ticket_url', get_permalink() );
				// Build ticket_url after token is in meta so swh_get_secure_ticket_link()
				// can resolve the correct page (respects swh_ticket_page_id setting).
				$data['ticket_url'] = swh_get_secure_ticket_link( $ticket_id );
				$default_assignee   = get_option( 'swh_default_assignee' );
				if ( $default_assignee ) {
					update_post_meta( $ticket_id, '_ticket_assigned_to', $default_assignee );
				}
				swh_send_email( $data['email'], 'swh_em_user_new_sub', 'swh_em_user_new_body', $data );
				$admin_email = swh_get_admin_email( $ticket_id );
				$proxy_urls  = array_map(
					function ( $u ) use ( $ticket_id ) {
						return swh_get_file_proxy_url( $u, $ticket_id );
					},
					$attach_urls
				);
				swh_send_email( $admin_email, 'swh_em_admin_new_sub', 'swh_em_admin_new_body', $data, $proxy_urls );
				/**
				 * Fires after a new ticket has been created and all meta, emails, and attachments saved.
				 *
				 * @since 2.1.0
				 * @param int                   $ticket_id The new ticket post ID.
				 * @param array<string, string> $data      Submission data including ticket_id, ticket_url, email.
				 */
				do_action( 'swh_ticket_created', $ticket_id, $data );
				echo '<div class="swh-alert swh-alert-success" role="status">' . esc_html( get_option( 'swh_msg_success_new', $defs['swh_msg_success_new'] ) ) . '</div>';
			}
		} else {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( get_option( 'swh_msg_err_missing', $defs['swh_msg_err_missing'] ) ) . '</div>';
		}
		if ( empty( $ticket_id ) ) {
			$form_name  = isset( $_POST['ticket_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_name'] ) ) : $form_name;
			$form_email = isset( $_POST['ticket_email'] ) ? sanitize_email( wp_unslash( $_POST['ticket_email'] ) ) : $form_email;
			$form_prio  = isset( $_POST['ticket_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_priority'] ) ) : $form_prio;
			$form_title = isset( $_POST['ticket_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_title'] ) ) : '';
			$form_desc  = isset( $_POST['ticket_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_desc'] ) ) : '';
		}
	}

	// Handle ticket lookup form.
	if ( isset( $_POST['swh_ticket_lookup'], $_POST['swh_lookup_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swh_lookup_nonce'] ) ), 'swh_ticket_lookup' ) ) {
		if ( swh_is_rate_limited( 'lookup', 60 ) ) {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html__( 'Please wait a moment before submitting again.', 'simple-wp-helpdesk' ) . '</div>';
		} elseif ( swh_check_antispam( false ) ) {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( get_option( 'swh_msg_err_spam', $defs['swh_msg_err_spam'] ) ) . '</div>';
		} else {
			$lookup_email = isset( $_POST['swh_lookup_email'] ) ? sanitize_email( wp_unslash( $_POST['swh_lookup_email'] ) ) : '';
			if ( $lookup_email ) {
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
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
				if ( ! empty( $lookup_tickets ) ) {
					$ticket_links = '';
					foreach ( $lookup_tickets as $lt ) {
						$new_token = wp_generate_password( 20, false );
						update_post_meta( $lt->ID, '_ticket_token', $new_token );
						update_post_meta( $lt->ID, '_ticket_token_created', time() );
						$link = swh_get_secure_ticket_link( $lt->ID );
						if ( $link ) {
							$uid           = get_post_meta( $lt->ID, '_ticket_uid', true );
							$ticket_links .= '- ' . $uid . ': ' . $lt->post_title . "\n  " . $link . "\n\n";
						}
					}
					$lookup_data = array(
						'email'        => $lookup_email,
						'ticket_links' => $ticket_links,
					);
					swh_send_email( $lookup_email, 'swh_em_user_lookup_sub', 'swh_em_user_lookup_body', $lookup_data );
				}
			}
			// Always show the same message to prevent email enumeration.
			echo '<div class="swh-alert swh-alert-success" role="status">' . esc_html( get_option( 'swh_msg_success_lookup', $defs['swh_msg_success_lookup'] ) ) . '</div>';
		}
	}
	?>
	<form method="POST" action="" enctype="multipart/form-data">
		<?php wp_nonce_field( 'swh_create_ticket', 'swh_ticket_nonce' ); ?>
		<div class="swh-form-group">
			<label for="swh-name"><?php esc_html_e( 'Your Name:', 'simple-wp-helpdesk' ); ?></label>
			<input type="text" id="swh-name" name="ticket_name" required class="swh-form-control" value="<?php echo esc_attr( $form_name ); ?>">
		</div>
		<div class="swh-form-group">
			<label for="swh-email"><?php esc_html_e( 'Your Email:', 'simple-wp-helpdesk' ); ?></label>
			<input type="email" id="swh-email" name="ticket_email" required class="swh-form-control" value="<?php echo esc_attr( $form_email ); ?>">
		</div>
		<div class="swh-form-group">
			<label for="swh-priority"><?php esc_html_e( 'Priority:', 'simple-wp-helpdesk' ); ?></label>
			<select id="swh-priority" name="ticket_priority" class="swh-form-control">
				<?php foreach ( $priorities as $p ) : ?>
					<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $form_prio, $p ); ?>><?php echo esc_html( $p ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="swh-form-group">
			<label for="swh-title"><?php esc_html_e( 'Problem Summary (Title):', 'simple-wp-helpdesk' ); ?></label>
			<input type="text" id="swh-title" name="ticket_title" required class="swh-form-control" value="<?php echo esc_attr( $form_title ); ?>">
		</div>
		<div class="swh-form-group">
			<label for="swh-desc"><?php esc_html_e( 'Problem Description:', 'simple-wp-helpdesk' ); ?></label>
			<textarea id="swh-desc" name="ticket_desc" rows="5" required class="swh-form-control"><?php echo esc_textarea( $form_desc ); // nosemgrep -- $form_desc sanitized via sanitize_textarea_field() before assignment; esc_textarea() applied at output. ?></textarea>
		</div>
		<div class="swh-form-group">
			<label for="swh-attachments"><?php esc_html_e( 'Attachments (Optional):', 'simple-wp-helpdesk' ); ?></label>
			<input type="file" id="swh-attachments" name="ticket_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" class="swh-form-control swh-file-input" style="padding: 5px;" aria-describedby="swh-file-hint">
			<small id="swh-file-hint" class="swh-text-muted" style="display:block; margin-top:5px;">
			<?php
				/* translators: 1: max upload size in MB, 2: max file count */
				printf( esc_html__( 'Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT. Max size: %1$sMB. Max files: %2$s.', 'simple-wp-helpdesk' ), esc_html( get_option( 'swh_max_upload_size', 5 ) ), esc_html( get_option( 'swh_max_upload_count', 5 ) ) );
			?>
			</small>
		</div>
		<?php
		// Explicit rendering for anti-spam.
		if ( 'honeypot' === $spam_method ) {
			echo '<div aria-hidden="true" style="position: absolute; left: -9999px;"><label aria-hidden="true">Leave this empty</label><input type="text" name="swh_website_url_hp" value="" tabindex="-1" autocomplete="off"></div>';
		} elseif ( 'recaptcha' === $spam_method ) {
			$key = get_option( 'swh_recaptcha_site_key' );
			echo '<div id="swh-recaptcha-box" style="margin-bottom: 15px;"></div>';
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External CDN script; null version is intentional.
			wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js?onload=swhRecaptchaLoad&render=explicit', array(), null, true );
			wp_add_inline_script(
				'google-recaptcha',
				'window.swhRecaptchaLoad = function() { if(document.getElementById("swh-recaptcha-box") && window.grecaptcha) { grecaptcha.render("swh-recaptcha-box", {"sitekey": "' . esc_js( $key ) . '"}); } };',
				'before'
			);
		} elseif ( 'turnstile' === $spam_method ) {
			$key = get_option( 'swh_turnstile_site_key' );
			echo '<div id="swh-turnstile-box" style="margin-bottom: 15px;"></div>';
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External CDN script; null version is intentional.
			wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=swhTurnstileLoad&render=explicit', array(), null, true );
			wp_add_inline_script(
				'cf-turnstile',
				'window.swhTurnstileLoad = function() { if(document.getElementById("swh-turnstile-box") && window.turnstile) { turnstile.render("#swh-turnstile-box", {sitekey: "' . esc_js( $key ) . '"}); } };',
				'before'
			);
		}
		?>
		<div class="swh-form-group">
			<input type="submit" name="swh_submit_ticket" value="<?php esc_attr_e( 'Submit Ticket', 'simple-wp-helpdesk' ); ?>" class="swh-btn">
		</div>
	</form>
	<div class="swh-lookup-section">
		<p><a href="#" id="swh-toggle-lookup" aria-expanded="false" aria-controls="swh-lookup-form"><?php esc_html_e( 'Already submitted a ticket? Resend my ticket links', 'simple-wp-helpdesk' ); ?></a></p>
		<div id="swh-lookup-form" style="display:none;">
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
		</div>
	</div>
	</div> <!-- End .swh-helpdesk-wrapper -->
	<?php
}

/**
 * Shortcode handler for [helpdesk_portal].
 *
 * Provides a dedicated portal page. If no ticket/token params are present,
 * displays a message. Otherwise renders the client portal view.
 *
 * @return string Shortcode HTML output.
 */
function swh_helpdesk_portal_shortcode() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET params used for routing only; form actions verified via nonces.
	if ( ! isset( $_GET['swh_ticket'], $_GET['token'] ) ) {
		return '<div class="swh-helpdesk-wrapper"><div class="swh-alert swh-alert-error" role="alert">' . esc_html__( 'No ticket specified.', 'simple-wp-helpdesk' ) . '</div></div>';
	}

	wp_enqueue_style( 'swh-frontend', SWH_PLUGIN_URL . 'assets/swh-frontend.css', array(), SWH_VERSION );
	wp_enqueue_script( 'swh-frontend', SWH_PLUGIN_URL . 'assets/swh-frontend.js', array(), SWH_VERSION, true );
	/* @var string[] $allowed_exts */ // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- PHPStan type annotation
	$allowed_exts = apply_filters( 'swh_allowed_file_types', array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt' ) );
	wp_localize_script(
		'swh-frontend',
		'swhConfig',
		array(
			'maxMb'       => (int) get_option( 'swh_max_upload_size', 5 ),
			'maxFiles'    => (int) get_option( 'swh_max_upload_count', 5 ),
			'allowedExts' => $allowed_exts,
			'i18n'        => array(
				/* translators: %d: maximum number of files allowed */
				'maxFilesError' => __( 'You may only attach up to %d file(s) per upload.', 'simple-wp-helpdesk' ),
				/* translators: %s: file name */
				'invalidType'   => __( 'File "%s" has an invalid type.', 'simple-wp-helpdesk' ),
				/* translators: 1: file name, 2: max file size in MB */
				'sizeExceeded'  => __( 'File "%1$s" exceeds the %2$dMB size limit.', 'simple-wp-helpdesk' ),
			),
		)
	);

	ob_start();
	swh_render_client_portal();
	return ob_get_clean();
}
