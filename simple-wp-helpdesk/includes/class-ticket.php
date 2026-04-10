<?php
/**
 * Ticket helpers: file proxy, upload handling, comment exclusion filters.
 *
 * @package Simple_WP_Helpdesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ==============================================================================
// TICKET HELPERS: File handling, uploads, comment exclusion filters.
// ==============================================================================

/**
 * Serves protected attachment files via token-based auth before WordPress bootstraps fully.
 *
 * @see swh_serve_file()
 */
add_action( 'init', 'swh_serve_file', 1 );
/**
 * Serves a protected helpdesk attachment file via token-based or capability-based access.
 *
 * Validates the request against the ticket token (hash_equals) or edit_posts capability.
 * Enforces path traversal prevention with realpath() before reading the file.
 * Hooked to init at priority 1 to fire before most other code.
 *
 * @return void Outputs file content and exits, or dies on access failure.
 */
function swh_serve_file() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- File proxy uses token-based auth (hash_equals) instead of nonces.
	if ( ! isset( $_GET['swh_file'], $_GET['swh_ticket'], $_GET['token'] ) ) {
		return;
	}
	$filename  = sanitize_file_name( wp_unslash( $_GET['swh_file'] ) );
	$ticket_id = absint( $_GET['swh_ticket'] );
	$token     = sanitize_text_field( wp_unslash( $_GET['token'] ) );

	if ( ! $filename || ! $ticket_id ) {
		wp_die( esc_html__( 'Invalid request.', 'simple-wp-helpdesk' ), 403 );
	}

	$post = get_post( $ticket_id );
	if ( ! $post || 'helpdesk_ticket' !== $post->post_type ) {
		wp_die( esc_html__( 'Invalid ticket.', 'simple-wp-helpdesk' ), 404 );
	}

	// Access check: valid portal token OR admin/technician capability.
	$db_token   = get_post_meta( $ticket_id, '_ticket_token', true );
	$has_access = false;
	if ( $db_token && hash_equals( $db_token, $token ) ) {
		$has_access = true;
	} elseif ( current_user_can( 'edit_posts' ) ) {
		$has_access = true;
	}
	if ( ! $has_access ) {
		wp_die( esc_html__( 'Access denied.', 'simple-wp-helpdesk' ), 403 );
	}

	if ( ! current_user_can( 'edit_posts' ) && swh_is_token_expired( $ticket_id ) ) {
		wp_die( esc_html__( 'This link has expired.', 'simple-wp-helpdesk' ), 403 );
	}

	// Resolve file path within the protected upload directory.
	$upload_dir = wp_get_upload_dir();
	$file_path  = trailingslashit( $upload_dir['basedir'] ) . 'swh-helpdesk/' . $filename;

	// Path traversal prevention.
	$real_path    = realpath( $file_path );
	$expected_dir = realpath( $upload_dir['basedir'] . '/swh-helpdesk' );
	if ( ! $real_path || ! $expected_dir || strpos( $real_path, $expected_dir ) !== 0 ) {
		wp_die( esc_html__( 'File not found.', 'simple-wp-helpdesk' ), 404 );
	}

	$filetype = wp_check_filetype( $filename );
	$mimetype = $filetype['type'] ? $filetype['type'] : 'application/octet-stream';

	$filename = str_replace( array( "\r", "\n", '"', ';' ), '', $filename );
	header( 'Content-Type: ' . $mimetype );
	header( 'Content-Disposition: inline; filename="' . $filename . '"' );
	header( 'Content-Length: ' . filesize( $real_path ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	readfile( $real_path );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	exit;
}

/**
 * Adds multipart/form-data enctype to the post edit form for ticket posts.
 *
 * @see swh_add_enctype_to_post_form()
 */
add_action( 'post_edit_form_tag', 'swh_add_enctype_to_post_form' );
/**
 * Adds multipart/form-data enctype to the post edit form for ticket posts.
 *
 * @return void
 */
function swh_add_enctype_to_post_form() {
	global $post;
	if ( $post && 'helpdesk_ticket' === $post->post_type ) {
		echo ' enctype="multipart/form-data"';
	}
}

/**
 * Normalizes a multi-file $_FILES sub-array into an array of individual file entries.
 *
 * @param array<string, mixed> $files A $_FILES entry for a file[] input (keys: name, type, tmp_name, error, size).
 * @return array<int, array<string, mixed>> Array of per-file associative arrays.
 */
function swh_normalize_files_array( $files ) {
	$normalized = array();
	if ( isset( $files['name'] ) && is_array( $files['name'] ) ) {
		foreach ( $files['name'] as $key => $name ) {
			if ( $name ) {
				$normalized[] = array(
					'name'     => $files['name'][ $key ],
					'type'     => $files['type'][ $key ],
					'tmp_name' => $files['tmp_name'][ $key ],
					'error'    => $files['error'][ $key ],
					'size'     => $files['size'][ $key ],
				);
			}
		}
	}
	return $normalized;
}

/**
 * Redirects WordPress uploads to the protected swh-helpdesk subdirectory.
 *
 * Used as a temporary filter around wp_handle_upload() in swh_handle_multiple_uploads().
 *
 * @param array<string, string> $dirs The current upload directory info array.
 * @return array<string, string> Modified upload directory info pointing to swh-helpdesk/.
 */
function swh_custom_upload_dir( $dirs ) {
	$dirs['subdir'] = '/swh-helpdesk';
	$dirs['path']   = $dirs['basedir'] . '/swh-helpdesk';
	$dirs['url']    = $dirs['baseurl'] . '/swh-helpdesk';
	return $dirs;
}

/**
 * Creates the swh-helpdesk upload directory with an .htaccess and index.php guard.
 *
 * Safe to call on every activation and upload — creates files only if they don't exist.
 *
 * @return void
 */
function swh_ensure_upload_protection() {
	$upload_dir = wp_get_upload_dir();
	$dir        = $upload_dir['basedir'] . '/swh-helpdesk';
	wp_mkdir_p( $dir );
	$htaccess = trailingslashit( $dir ) . '.htaccess';
	if ( ! file_exists( $htaccess ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
	}
	$index = trailingslashit( $dir ) . 'index.php';
	if ( ! file_exists( $index ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $index, "<?php\n// Silence is golden.\n" );
	}
}

/**
 * Converts a raw file URL into a token-authenticated proxy URL.
 *
 * The proxy URL routes through swh_serve_file() which validates access before serving.
 * Returns the original URL unchanged if no token exists for the ticket.
 *
 * @param string $url       The raw upload file URL.
 * @param int    $ticket_id The ticket post ID.
 * @return string The proxy URL, or the original URL as a fallback.
 */
function swh_get_file_proxy_url( $url, $ticket_id ) {
	$token = get_post_meta( $ticket_id, '_ticket_token', true );
	if ( ! $token ) {
		return $url;
	}
	return add_query_arg(
		array(
			'swh_file'   => rawurlencode( basename( $url ) ),
			'swh_ticket' => $ticket_id,
			'token'      => $token,
		),
		site_url( '/' )
	);
}

/**
 * Handles uploading one or more files from a $_FILES sub-array to the protected directory.
 *
 * Validates file count, size, and MIME type. Uses wp_handle_upload() with a temporary
 * upload_dir filter to route files into swh-helpdesk/. Logs errors via error_log().
 *
 * Pass a reference as $orig_names to receive a url→original_name map for display purposes.
 *
 * @param array<string, mixed>      $file_array  The $_FILES sub-array for the file input.
 * @param array<string, string>|null $orig_names  Optional. Populated with url→original_name map on return.
 * @param-out array<string, string> $orig_names
 * @return string[] Array of successfully uploaded file URLs.
 */
function swh_handle_multiple_uploads( $file_array, &$orig_names = null ) {
	$files = swh_normalize_files_array( $file_array );
	if ( empty( $files ) ) {
		return array();
	}
	$max_count = (int) get_option( 'swh_max_upload_count', 5 );
	if ( $max_count > 0 && count( $files ) > $max_count ) {
		$files = array_slice( $files, 0, $max_count );
	}
	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$max_size_mb   = (int) get_option( 'swh_max_upload_size', 5 );
	$max_bytes     = $max_size_mb * 1048576;
	$allowed_mimes = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'gif'          => 'image/gif',
		'png'          => 'image/png',
		'pdf'          => 'application/pdf',
		'doc'          => 'application/msword',
		'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'txt'          => 'text/plain',
	);
	$overrides     = array(
		'test_form' => false,
		'mimes'     => $allowed_mimes,
	);
	/** @var string[] $uploaded_urls */
	$uploaded_urls = array();
	/** @var array<string, string> $url_name_map */
	$url_name_map  = array();
	swh_ensure_upload_protection();
	add_filter( 'upload_dir', 'swh_custom_upload_dir' );
	foreach ( $files as $file ) {
		if ( $file['size'] > $max_bytes ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional; logs oversized uploads for admin troubleshooting.
			error_log( sprintf( 'SWH upload skipped: "%1$s" exceeds %2$dMB limit.', isset( $file['name'] ) ? $file['name'] : '', $max_size_mb ) );
			continue;
		}
		$original_name = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$movefile      = wp_handle_upload( $file, $overrides );
		if ( $movefile && ! isset( $movefile['error'] ) && isset( $movefile['url'] ) && is_string( $movefile['url'] ) ) {
			$file_url                    = $movefile['url'];
			$uploaded_urls[]             = $file_url;
			$url_name_map[ $file_url ]   = $original_name;
		} elseif ( isset( $movefile['error'] ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional; logs upload failures for admin troubleshooting.
			error_log( 'SWH upload failed for "' . $original_name . '": ' . $movefile['error'] );
		}
	}
	remove_filter( 'upload_dir', 'swh_custom_upload_dir' );
	$orig_names = $url_name_map;
	return $uploaded_urls;
}

/**
 * Deletes a single file from the filesystem given its URL.
 *
 * Only deletes files within the configured uploads directory. No-ops on empty or external URLs.
 *
 * @param string $url The file URL to delete.
 * @return void
 */
function swh_delete_file_by_url( $url ) {
	if ( empty( $url ) ) {
		return;
	}
	$upload_dir = wp_get_upload_dir();
	if ( strpos( $url, $upload_dir['baseurl'] ) !== 0 ) {
		return;
	}
	$path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $url );
	if ( file_exists( $path ) ) {
		wp_delete_file( $path );
	}
}

/**
 * Permanently deletes a ticket post and all associated files (main and reply attachments).
 *
 * Handles both the current multi-URL meta format and legacy single-URL meta keys.
 *
 * @param int $ticket_id The ticket post ID.
 * @return void
 */
function swh_delete_ticket_and_files( $ticket_id ) {
	$main_atts = get_post_meta( $ticket_id, '_ticket_attachments', true );
	if ( ! empty( $main_atts ) && is_array( $main_atts ) ) {
		foreach ( $main_atts as $url ) {
			swh_delete_file_by_url( $url );
		}
	}
	$legacy_id = get_post_meta( $ticket_id, '_ticket_attachment_id', true );
	if ( $legacy_id ) {
		wp_delete_attachment( $legacy_id, true );
	}
	$legacy_url = get_post_meta( $ticket_id, '_ticket_attachment_url', true );
	if ( $legacy_url ) {
		swh_delete_file_by_url( $legacy_url );
	}
	$comments = get_comments( array( 'post_id' => $ticket_id ) );
	$comments = is_array( $comments ) ? $comments : array();
	foreach ( $comments as $c ) {
		if ( ! $c instanceof WP_Comment ) {
			continue;
		}
		$c_atts = get_comment_meta( (int) $c->comment_ID, '_attachments', true );
		if ( ! empty( $c_atts ) && is_array( $c_atts ) ) {
			foreach ( $c_atts as $url ) {
				swh_delete_file_by_url( $url );
			}
		}
		$legacy_c_url = get_comment_meta( (int) $c->comment_ID, '_attachment_url', true );
		if ( $legacy_c_url ) {
			swh_delete_file_by_url( $legacy_c_url );
		}
	}
	wp_delete_post( $ticket_id, true );
}

/**
 * Excludes helpdesk reply comments from standard WordPress comment queries.
 *
 * @see swh_exclude_helpdesk_comments()
 */
add_filter( 'comments_clauses', 'swh_exclude_helpdesk_comments', 10, 2 );
/**
 * Excludes helpdesk reply comments from standard WordPress comment queries.
 *
 * Does not exclude when querying a specific ticket post (admin editor, cron) or when
 * the query explicitly requests helpdesk_reply type (client portal).
 *
 * @param string[]         $clauses SQL clause array from WP_Comment_Query.
 * @param WP_Comment_Query $query   The comment query object.
 * @return string[] Modified clauses.
 */
function swh_exclude_helpdesk_comments( $clauses, $query ) {
	global $wpdb;
	// Don't exclude when querying a specific helpdesk ticket (admin editor, cron).
	$post_id = isset( $query->query_vars['post_id'] ) ? (int) $query->query_vars['post_id'] : 0;
	if ( $post_id && 'helpdesk_ticket' === get_post_type( $post_id ) ) {
		return $clauses;
	}
	// Don't exclude when the query explicitly requests helpdesk replies (client portal).
	$requested_type = isset( $query->query_vars['type'] ) ? $query->query_vars['type'] : '';
	if ( 'helpdesk_reply' === $requested_type ) {
		return $clauses;
	}
	// Exclude comments on helpdesk_ticket posts by post type — works regardless
	// of whether comment_type was migrated.
	$clauses['where'] .= $wpdb->prepare(
		" AND {$wpdb->comments}.comment_post_ID NOT IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = %s )",
		'helpdesk_ticket'
	);
	return $clauses;
}

/**
 * Handles the AJAX request to submit a CSAT satisfaction rating after a ticket is closed.
 *
 * @see swh_submit_csat_ajax()
 */
add_action( 'wp_ajax_swh_submit_csat', 'swh_submit_csat_ajax' );
add_action( 'wp_ajax_nopriv_swh_submit_csat', 'swh_submit_csat_ajax' );
/**
 * Saves a CSAT rating (1–5) to ticket post meta after the client closes a ticket.
 *
 * Verifies a per-ticket nonce before writing. Responds with JSON.
 *
 * @return void Outputs JSON and exits.
 */
function swh_submit_csat_ajax() {
	$ticket_id = isset( $_POST['ticket_id'] ) ? absint( $_POST['ticket_id'] ) : 0;
	$rating    = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : 0;
	$nonce     = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

	if ( ! $ticket_id || $rating < 1 || $rating > 5 || ! wp_verify_nonce( $nonce, 'swh_csat_' . $ticket_id ) ) {
		wp_send_json_error( array( 'message' => 'Invalid request.' ), 400 );
	}

	$post = get_post( $ticket_id );
	if ( ! $post || 'helpdesk_ticket' !== $post->post_type ) {
		wp_send_json_error( array( 'message' => 'Invalid ticket.' ), 400 );
	}

	update_post_meta( $ticket_id, '_ticket_csat', $rating );
	wp_send_json_success();
}

/**
 * Excludes helpdesk ticket comments from the site's comment RSS feed.
 *
 * @see swh_exclude_helpdesk_from_feed()
 */
add_filter( 'comment_feed_where', 'swh_exclude_helpdesk_from_feed', 10, 2 );
/**
 * Excludes helpdesk ticket comments from the site's comment RSS feed.
 *
 * @param string           $where The WHERE clause for the comment feed query.
 * @param WP_Comment_Query $query The comment query object (not used directly).
 * @return string Modified WHERE clause.
 */
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature requires $query; not needed here.
function swh_exclude_helpdesk_from_feed( $where, $query ) {
	global $wpdb;
	$where .= $wpdb->prepare(
		" AND {$wpdb->comments}.comment_post_ID NOT IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type = %s )",
		'helpdesk_ticket'
	);
	return $where;
}
