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
 * Supported attributes:
 *   show_priority    yes|no  — whether to display the priority field (default: yes)
 *   default_priority string  — pre-select a specific priority value
 *   default_status   string  — override the new-ticket status on submission
 *   show_lookup      yes|no  — whether to show the "Resend my ticket links" section (default: yes)
 *
 * @param array<string, string>|string $atts Shortcode attributes.
 * @return string Shortcode HTML output.
 */
function swh_ticket_frontend( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'show_priority'    => 'yes',
			'default_priority' => '',
			'default_status'   => '',
			'show_lookup'      => 'yes',
		),
		is_array( $atts ) ? $atts : array(),
		'submit_ticket'
	);

	wp_enqueue_style( 'swh-shared', SWH_PLUGIN_URL . 'assets/swh-shared.css', array(), SWH_VERSION );
	wp_enqueue_style( 'swh-frontend', SWH_PLUGIN_URL . 'assets/swh-frontend.css', array( 'swh-shared' ), SWH_VERSION );
	wp_enqueue_script( 'swh-frontend', SWH_PLUGIN_URL . 'assets/swh-frontend.js', array(), SWH_VERSION, true );
	/**
	 * Filters the list of allowed file extensions for ticket attachments.
	 *
	 * @since 2.1.0
	 * @param string[] $exts Array of lowercase file extension strings.
	 */
	/* @var string[] $allowed_exts */ // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- PHPStan type annotation
	$allowed_exts = apply_filters( 'swh_allowed_file_types', array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt' ) );
	$tmpl_raw     = get_option( 'swh_ticket_templates', array() );
	$tmpl_raw     = is_array( $tmpl_raw ) ? $tmpl_raw : array();
	$tmpl_js      = array();
	foreach ( $tmpl_raw as $tmpl ) {
		if ( is_array( $tmpl ) && ! empty( $tmpl['label'] ) && is_string( $tmpl['label'] ) ) {
			$tmpl_js[] = array(
				'label' => $tmpl['label'],
				'body'  => isset( $tmpl['body'] ) && is_string( $tmpl['body'] ) ? $tmpl['body'] : '',
			);
		}
	}
	wp_localize_script(
		'swh-frontend',
		'swhConfig',
		array(
			'maxMb'       => swh_get_int_option( 'swh_max_upload_size', 5 ),
			'maxFiles'    => swh_get_int_option( 'swh_max_upload_count', 5 ),
			'allowedExts' => $allowed_exts,
			'templates'   => $tmpl_js,
			'i18n'        => array(
				/* translators: %d: maximum number of files allowed */
				'maxFilesError'  => __( 'You may only attach up to %d file(s) per upload.', 'simple-wp-helpdesk' ),
				/* translators: %s: file name */
				'invalidType'    => __( 'File "%s" has an invalid type.', 'simple-wp-helpdesk' ),
				/* translators: 1: file name, 2: max file size in MB */
				'sizeExceeded'   => __( 'File "%1$s" exceeds the %2$dMB size limit.', 'simple-wp-helpdesk' ),
				/* translators: shown as first option in the Request Type dropdown */
				'selectTemplate' => __( '— Select a request type —', 'simple-wp-helpdesk' ),
				'uploading'      => __( 'Uploading\u{2026}', 'simple-wp-helpdesk' ),
				'submitLabel'    => __( 'Submit Ticket', 'simple-wp-helpdesk' ),
			),
		)
	);

	ob_start();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET params used for routing only; form actions verified via nonces.
	if ( isset( $_GET['swh_ticket'], $_GET['token'] ) ) {
		swh_render_client_portal();
	} else {
		swh_render_submission_form( $atts );
	}

	$buf = ob_get_clean();
	return false !== $buf ? $buf : '';
}

/**
 * Renders the ticket submission form and ticket lookup form.
 *
 * This is the default view when no ticket/token URL parameters are present.
 * Handles submission, validation, anti-spam, file uploads, and ticket lookup POST actions.
 *
 * @param array<string, string> $atts Shortcode attributes from shortcode_atts().
 * @return void
 */
function swh_render_submission_form( $atts = array() ) {
	$defs           = swh_get_defaults();
	$closed_status  = swh_get_string_option( 'swh_closed_status', is_string( $defs['swh_closed_status'] ) ? $defs['swh_closed_status'] : '' );
	$priorities     = swh_get_priorities();
	$default_prio   = ! empty( $atts['default_priority'] ) && in_array( $atts['default_priority'], $priorities, true )
		? $atts['default_priority']
		: swh_get_string_option( 'swh_default_priority', is_string( $defs['swh_default_priority'] ) ? $defs['swh_default_priority'] : '' );
	$valid_statuses = swh_get_statuses();
	$default_status = ! empty( $atts['default_status'] ) && in_array( $atts['default_status'], $valid_statuses, true )
		? $atts['default_status']
		: swh_get_string_option( 'swh_default_status', is_string( $defs['swh_default_status'] ) ? $defs['swh_default_status'] : '' );
	$show_priority  = ( ! isset( $atts['show_priority'] ) || 'no' !== strtolower( $atts['show_priority'] ) );
	$show_lookup    = ( ! isset( $atts['show_lookup'] ) || 'no' !== strtolower( $atts['show_lookup'] ) );
	$show_category  = ( isset( $atts['show_category'] ) && 'yes' === strtolower( $atts['show_category'] ) );
	$spam_method    = swh_get_string_option( 'swh_spam_method', 'none' );
	$tmpl_raw       = get_option( 'swh_ticket_templates', array() );
	$tmpl_raw       = is_array( $tmpl_raw ) ? $tmpl_raw : array();
	$tmpl_js        = array();
	foreach ( $tmpl_raw as $t ) {
		if ( is_array( $t ) && ! empty( $t['label'] ) && is_string( $t['label'] ) ) {
			$tmpl_js[] = array(
				'label' => $t['label'],
				'body'  => isset( $t['body'] ) && is_string( $t['body'] ) ? $t['body'] : '',
			);
		}
	}
	?>
	<div class="swh-helpdesk-wrapper">
	<?php
	$current_user = wp_get_current_user();
	$form_name    = is_user_logged_in() ? ( is_string( $current_user->display_name ) ? $current_user->display_name : '' ) : '';
	$form_email   = is_user_logged_in() ? ( is_string( $current_user->user_email ) ? $current_user->user_email : '' ) : '';
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

	if ( $submit_rate_passed && isset( $_POST['swh_submit_ticket'], $_POST['swh_ticket_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( is_string( $_POST['swh_ticket_nonce'] ) ? $_POST['swh_ticket_nonce'] : '' ) ), 'swh_create_ticket' ) ) {
		$ticket_template = isset( $_POST['ticket_template'] ) && is_string( $_POST['ticket_template'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_template'] ) ) : '';
		$ticket_category = isset( $_POST['ticket_category'] ) && is_scalar( $_POST['ticket_category'] ) ? absint( $_POST['ticket_category'] ) : 0;
		$data            = array(
			'name'     => isset( $_POST['ticket_name'] ) && is_string( $_POST['ticket_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_name'] ) ) : '',
			'email'    => isset( $_POST['ticket_email'] ) && is_string( $_POST['ticket_email'] ) ? sanitize_email( wp_unslash( $_POST['ticket_email'] ) ) : '',
			'title'    => isset( $_POST['ticket_title'] ) && is_string( $_POST['ticket_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_title'] ) ) : '',
			'message'  => isset( $_POST['ticket_desc'] ) && is_string( $_POST['ticket_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_desc'] ) ) : '',
			'priority' => isset( $_POST['ticket_priority'] ) && is_string( $_POST['ticket_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_priority'] ) ) : '',
			'status'   => $default_status,
		);
		// Validate priority against allowed list.
		if ( ! in_array( $data['priority'], $priorities, true ) ) {
			$data['priority'] = $default_prio;
		}
		$is_spam = swh_check_antispam( true );

		if ( $is_spam ) {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( swh_get_string_option( 'swh_msg_err_spam', is_string( $defs['swh_msg_err_spam'] ) ? $defs['swh_msg_err_spam'] : '' ) ) . '</div>';
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
			if ( $ticket_id > 0 ) {
				$attach_urls    = array();
				$attach_names   = null;
				$attach_skipped = 0;
				// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$ticket_files = isset( $_FILES['ticket_attachments'] ) && is_array( $_FILES['ticket_attachments'] ) ? $_FILES['ticket_attachments'] : array();
				$ticket_names = isset( $ticket_files['name'] ) && is_array( $ticket_files['name'] ) ? $ticket_files['name'] : array();
				if ( ! empty( $ticket_names[0] ) ) {
					$attach_urls = swh_handle_multiple_uploads( $ticket_files, $attach_names, $attach_skipped );
				// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				}
				if ( ! empty( $attach_urls ) ) {
					update_post_meta( $ticket_id, '_ticket_attachments', $attach_urls );
					if ( ! empty( $attach_names ) ) {
						update_post_meta( $ticket_id, '_swh_attachment_orignames', $attach_names );
					}
				}
				$token             = wp_generate_password( 20, false );
				$data['ticket_id'] = 'TKT-' . str_pad( (string) $ticket_id, 4, '0', STR_PAD_LEFT );
				$data['admin_url'] = admin_url( 'post.php?post=' . $ticket_id . '&action=edit' );
				update_post_meta( $ticket_id, '_ticket_uid', $data['ticket_id'] );
				update_post_meta( $ticket_id, '_ticket_name', $data['name'] );
				update_post_meta( $ticket_id, '_ticket_email', $data['email'] );
				update_post_meta( $ticket_id, '_ticket_status', $data['status'] );
				update_post_meta( $ticket_id, '_ticket_priority', $data['priority'] );
				update_post_meta( $ticket_id, '_ticket_token', $token );
				update_post_meta( $ticket_id, '_ticket_token_created', time() );
				update_post_meta( $ticket_id, '_ticket_url', get_permalink() );
				if ( $ticket_template ) {
					update_post_meta( $ticket_id, '_ticket_template', $ticket_template );
				}
				// Build ticket_url after token is in meta so swh_get_secure_ticket_link()
				// can resolve the correct page (respects swh_ticket_page_id setting).
				$secure_url         = swh_get_secure_ticket_link( $ticket_id );
				$data['ticket_url'] = false !== $secure_url ? $secure_url : '';
				// Assign submitted category before evaluating assignment rules.
				if ( $ticket_category > 0 ) {
					wp_set_post_terms( $ticket_id, array( $ticket_category ), 'helpdesk_category' );
				}
				// Apply auto-assignment rules (falls back to swh_default_assignee).
				swh_apply_assignment_rules( $ticket_id );
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
				echo '<div class="swh-alert swh-alert-success" role="status">' . esc_html( swh_get_string_option( 'swh_msg_success_new', is_string( $defs['swh_msg_success_new'] ) ? $defs['swh_msg_success_new'] : '' ) ) . '</div>';
				if ( $attach_skipped > 0 ) {
					$max_mb = swh_get_int_option( 'swh_max_upload_size', 5 );
					echo '<div class="swh-alert swh-alert-warning" role="status">' . esc_html(
						/* translators: 1: number of skipped files, 2: upload size limit in MB */
						sprintf( _n( '%1$d file was not uploaded because it exceeds the %2$dMB size limit.', '%1$d files were not uploaded because they exceed the %2$dMB size limit.', $attach_skipped, 'simple-wp-helpdesk' ), $attach_skipped, $max_mb )
					) . '</div>';
				}
			} else {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional; logs submission failures for admin troubleshooting.
				error_log( 'Simple WP Helpdesk: wp_insert_post() failed for submission (email hash: ' . ( isset( $data['email'] ) ? substr( md5( $data['email'] ), 0, 8 ) : 'unknown' ) . ')' );
				echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html__( 'Sorry, there was a problem saving your ticket. Please try again.', 'simple-wp-helpdesk' ) . '</div>';
			}
		} else {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( swh_get_string_option( 'swh_msg_err_missing', is_string( $defs['swh_msg_err_missing'] ) ? $defs['swh_msg_err_missing'] : '' ) ) . '</div>';
		}
		if ( empty( $ticket_id ) ) {
			$form_name  = isset( $_POST['ticket_name'] ) && is_string( $_POST['ticket_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_name'] ) ) : $form_name;
			$form_email = isset( $_POST['ticket_email'] ) && is_string( $_POST['ticket_email'] ) ? sanitize_email( wp_unslash( $_POST['ticket_email'] ) ) : $form_email;
			$form_prio  = isset( $_POST['ticket_priority'] ) && is_string( $_POST['ticket_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_priority'] ) ) : $form_prio;
			$form_title = isset( $_POST['ticket_title'] ) && is_string( $_POST['ticket_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ticket_title'] ) ) : '';
			$form_desc  = isset( $_POST['ticket_desc'] ) && is_string( $_POST['ticket_desc'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ticket_desc'] ) ) : '';
		}
	}

	// Handle ticket lookup form.
	if ( isset( $_POST['swh_ticket_lookup'], $_POST['swh_lookup_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( is_string( $_POST['swh_lookup_nonce'] ) ? $_POST['swh_lookup_nonce'] : '' ) ), 'swh_ticket_lookup' ) ) {
		if ( swh_is_rate_limited( 'lookup', 60 ) ) {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html__( 'Please wait a moment before submitting again.', 'simple-wp-helpdesk' ) . '</div>';
		} elseif ( swh_check_antispam( false ) ) {
			echo '<div class="swh-alert swh-alert-error" role="alert">' . esc_html( swh_get_string_option( 'swh_msg_err_spam', is_string( $defs['swh_msg_err_spam'] ) ? $defs['swh_msg_err_spam'] : '' ) ) . '</div>';
		} else {
			$lookup_email = isset( $_POST['swh_lookup_email'] ) && is_string( $_POST['swh_lookup_email'] ) ? sanitize_email( wp_unslash( $_POST['swh_lookup_email'] ) ) : '';
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
							$uid           = swh_get_string_meta( $lt->ID, '_ticket_uid' );
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
			echo '<div class="swh-alert swh-alert-success" role="status">' . esc_html( swh_get_string_option( 'swh_msg_success_lookup', is_string( $defs['swh_msg_success_lookup'] ) ? $defs['swh_msg_success_lookup'] : '' ) ) . '</div>';
		}
	}
	?>
	<form id="swh-ticket-form" method="POST" action="" enctype="multipart/form-data">
		<?php wp_nonce_field( 'swh_create_ticket', 'swh_ticket_nonce' ); ?>
		<div class="swh-form-group">
			<label for="swh-name"><?php esc_html_e( 'Your Name:', 'simple-wp-helpdesk' ); ?></label>
			<input type="text" id="swh-name" name="ticket_name" required class="swh-form-control" value="<?php echo esc_attr( $form_name ); ?>">
		</div>
		<div class="swh-form-group">
			<label for="swh-email"><?php esc_html_e( 'Your Email:', 'simple-wp-helpdesk' ); ?></label>
			<input type="email" id="swh-email" name="ticket_email" required class="swh-form-control" value="<?php echo esc_attr( $form_email ); ?>">
		</div>
		<?php if ( $show_priority ) : ?>
		<div class="swh-form-group">
			<label for="swh-priority"><?php esc_html_e( 'Priority:', 'simple-wp-helpdesk' ); ?></label>
			<select id="swh-priority" name="ticket_priority" class="swh-form-control">
				<?php foreach ( $priorities as $p ) : ?>
					<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $form_prio, $p ); ?>><?php echo esc_html( $p ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php endif; ?>
		<?php if ( ! empty( $tmpl_js ) ) : ?>
		<div class="swh-form-group">
			<label for="swh-request-type"><?php esc_html_e( 'Request Type (Optional):', 'simple-wp-helpdesk' ); ?></label>
			<select id="swh-request-type" name="ticket_template" class="swh-form-control">
				<option value=""><?php esc_html_e( '— Select a request type —', 'simple-wp-helpdesk' ); ?></option>
				<?php foreach ( $tmpl_js as $tmpl ) : ?>
					<option value="<?php echo esc_attr( $tmpl['label'] ); ?>"><?php echo esc_html( $tmpl['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php endif; ?>
		<?php
		if ( $show_category ) :
			$cat_terms = get_terms(
				array(
					'taxonomy'   => 'helpdesk_category',
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $cat_terms ) && ! empty( $cat_terms ) ) :
				?>
		<div class="swh-form-group">
			<label for="swh-category"><?php esc_html_e( 'Category:', 'simple-wp-helpdesk' ); ?></label>
			<select id="swh-category" name="ticket_category" class="swh-form-control">
				<option value=""><?php esc_html_e( '— Select a category —', 'simple-wp-helpdesk' ); ?></option>
				<?php foreach ( $cat_terms as $cat_term ) : ?>
					<option value="<?php echo esc_attr( (string) $cat_term->term_id ); ?>"><?php echo esc_html( $cat_term->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
				<?php
			endif;
		endif;
		?>
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
			<input type="file" id="swh-attachments" name="ticket_attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" class="swh-form-control swh-file-input" aria-describedby="swh-file-hint">
			<small id="swh-file-hint" class="swh-text-muted" style="display:block; margin-top:5px;">
			<?php
				/* translators: 1: max upload size in MB, 2: max file count */
				printf( esc_html__( 'Allowed file types: JPG, JPEG, PNG, GIF, PDF, DOC, DOCX, TXT. Max size: %1$sMB. Max files: %2$s.', 'simple-wp-helpdesk' ), esc_html( (string) swh_get_int_option( 'swh_max_upload_size', 5 ) ), esc_html( (string) swh_get_int_option( 'swh_max_upload_count', 5 ) ) );
			?>
			</small>
		</div>
		<?php
		// Explicit rendering for anti-spam.
		if ( 'honeypot' === $spam_method ) {
			echo '<div aria-hidden="true" style="clip-path:inset(50%);height:1px;overflow:hidden;position:absolute;white-space:nowrap;width:1px;"><label aria-hidden="true">' . esc_html__( 'Leave this empty', 'simple-wp-helpdesk' ) . '</label><input type="text" name="swh_website_url_hp" value="" tabindex="-1" autocomplete="off"></div>';
		} elseif ( 'recaptcha' === $spam_method ) {
			$key            = swh_get_string_option( 'swh_recaptcha_site_key' );
			$recaptcha_type = swh_get_string_option( 'swh_recaptcha_type', 'v2' );
			echo '<div id="swh-recaptcha-box" style="margin-bottom: 15px;"></div>';
			if ( 'enterprise' === $recaptcha_type ) {
				// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External CDN script; null version is intentional.
				wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/enterprise.js?onload=swhRecaptchaLoad&render=explicit', array(), null, true );
				wp_add_inline_script(
					'google-recaptcha',
					'window.swhRecaptchaLoad = function() { if(document.getElementById("swh-recaptcha-box") && window.grecaptcha) { grecaptcha.enterprise.render("swh-recaptcha-box", {"sitekey": "' . esc_js( $key ) . '"}); } };',
					'before'
				);
			} else {
				// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External CDN script; null version is intentional.
				wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js?onload=swhRecaptchaLoad&render=explicit', array(), null, true );
				wp_add_inline_script(
					'google-recaptcha',
					'window.swhRecaptchaLoad = function() { if(document.getElementById("swh-recaptcha-box") && window.grecaptcha) { grecaptcha.render("swh-recaptcha-box", {"sitekey": "' . esc_js( $key ) . '"}); } };',
					'before'
				);
			}
		} elseif ( 'turnstile' === $spam_method ) {
			$key = swh_get_string_option( 'swh_turnstile_site_key' );
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
	<?php if ( $show_lookup ) : ?>
	<div class="swh-lookup-section">
		<p><a href="#" id="swh-toggle-lookup" aria-expanded="false" aria-controls="swh-lookup-form"><?php esc_html_e( 'Already submitted a ticket? Resend my ticket links', 'simple-wp-helpdesk' ); ?></a></p>
		<div id="swh-lookup-form" hidden aria-hidden="true">
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
		</div>
	</div>
	<?php endif; ?>
	</div> <!-- End .swh-helpdesk-wrapper -->
	<?php
}

/**
 * Shortcode handler for [helpdesk_portal].
 *
 * Provides a dedicated portal page. If no ticket/token params are present,
 * logged-in WordPress users see a "My Tickets" table (filtered by their email);
 * guests see the lookup form (or it is hidden if show_lookup="no").
 * When ticket/token params are present, renders the client portal conversation view.
 *
 * Supports the same attributes as [submit_ticket]: show_priority, default_priority,
 * default_status, show_lookup.
 *
 * @param array<string, string>|string $atts Shortcode attributes.
 * @return string Shortcode HTML output.
 */
function swh_helpdesk_portal_shortcode( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'show_priority'    => 'yes',
			'default_priority' => '',
			'default_status'   => '',
			'show_lookup'      => 'yes',
		),
		is_array( $atts ) ? $atts : array(),
		'helpdesk_portal'
	);

	wp_enqueue_style( 'swh-shared', SWH_PLUGIN_URL . 'assets/swh-shared.css', array(), SWH_VERSION );
	wp_enqueue_style( 'swh-frontend', SWH_PLUGIN_URL . 'assets/swh-frontend.css', array( 'swh-shared' ), SWH_VERSION );
	wp_enqueue_script( 'swh-frontend', SWH_PLUGIN_URL . 'assets/swh-frontend.js', array(), SWH_VERSION, true );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only GET params used for routing only; form actions verified via nonces.
	if ( ! isset( $_GET['swh_ticket'], $_GET['token'] ) ) {
		ob_start();
		swh_render_portal_no_token();
		$buf = ob_get_clean();
		return false !== $buf ? $buf : '';
	}
	/* @var string[] $allowed_exts */ // phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- PHPStan type annotation
	$allowed_exts = apply_filters( 'swh_allowed_file_types', array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt' ) );
	wp_localize_script(
		'swh-frontend',
		'swhConfig',
		array(
			'maxMb'       => swh_get_int_option( 'swh_max_upload_size', 5 ),
			'maxFiles'    => swh_get_int_option( 'swh_max_upload_count', 5 ),
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
	$buf = ob_get_clean();
	return false !== $buf ? $buf : '';
}
