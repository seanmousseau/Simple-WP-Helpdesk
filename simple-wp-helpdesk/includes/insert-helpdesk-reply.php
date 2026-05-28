<?php
/**
 * Context-neutral helpdesk reply insertion helper.
 *
 * Extracted from the admin save-post path in v4.1.0 Phase 4 so the admin
 * "Add Reply" form, the internal REST drawer, the portal, AND the new public
 * REST v1 controller can share a single insertion choke point.
 *
 * Invariant #20 (design-document.md): `swh_insert_helpdesk_reply()` is the
 * only correct way to insert a `helpdesk_reply` comment. Direct
 * `wp_insert_comment` calls with `comment_type='helpdesk_reply'` are
 * forbidden in callers. Centralising the insert keeps attachment handling,
 * email dispatch (`swh_send_email()` per invariant #2), `_swh_unread`
 * updates, and the `swh_ticket_replied` action firing in one place.
 *
 * @package Simple_WP_Helpdesk
 * @since   4.1.0
 */

declare(strict_types=1);

if ( ! function_exists( 'swh_insert_helpdesk_reply' ) ) {
	/**
	 * Insert a reply (helpdesk_reply comment) on a ticket in a context-neutral way.
	 *
	 * The function does NOT check capabilities or nonces; callers must do that
	 * before calling. Email dispatch always routes through `swh_send_email()`
	 * (invariant #2). The `swh_ticket_replied` action is fired via
	 * `swh_fire_ticket_replied()` (invariant: lifecycle hook v3.7+).
	 *
	 * Recognised keys in `$data`:
	 *
	 * - `body` (string, required) — Raw HTML. Always sanitised through
	 *   `wp_kses_post()`. An empty body is allowed only when at least one
	 *   `attachments` / `attachment_urls` entry is supplied (mirrors the
	 *   admin "Attached file(s)" fallback).
	 * - `is_internal_note` (bool, optional, default false) — When true, sets
	 *   `_is_internal_note=1` on the comment meta and suppresses outgoing
	 *   email to the requester.
	 * - `attachments` (int[], optional) — Media IDs (REST v1 path). Each ID
	 *   is validated via `get_post()` — must exist and be of post type
	 *   `attachment`. Resolved attachment URLs are stored in the comment
	 *   meta `_attachments` array.
	 * - `attachment_urls` (string[], optional) — Already-uploaded file URLs
	 *   (admin / portal path post `swh_handle_multiple_uploads`). Stored
	 *   verbatim in `_attachments`. Use this when the caller has already
	 *   handled `$_FILES` and just wants the helper to persist URLs.
	 * - `attachment_names` (array<string,string>, optional) — url => original
	 *   filename map for display purposes. Stored in `_swh_reply_orignames`.
	 * - `author_user_id` (int, optional) — WP user ID for staff replies.
	 *   When set, the comment is attributed to that user.
	 * - `author_email` (string, optional) — Required when `author_user_id`
	 *   is absent (client / portal replies).
	 * - `author_name` (string, optional) — Display name. Falls back to
	 *   "Client" when an email is supplied without a name.
	 * - `dispatch_email` (bool, optional, default true) — When false, suppress
	 *   the helper's email dispatch. The admin save-post path passes false so
	 *   its composite "reply + status change" branching can emit the email
	 *   itself; the REST v1 controller, the internal REST drawer, and the
	 *   portal all leave it on.
	 *
	 * Side effects:
	 *
	 * - Inserts a `helpdesk_reply` comment with the canonical metadata.
	 * - Validates attachment IDs (REST) or persists pre-uploaded URLs (admin).
	 * - Updates `_swh_last_activity_at = time()` so inbox ordering refreshes.
	 * - For client-authored replies (no `author_user_id`), sets
	 *   `_swh_unread=1` so the inbox surfaces the new message.
	 * - For staff-authored replies, updates `_ticket_first_response_at` if
	 *   the staff email differs from the requester email and no first
	 *   response has been recorded.
	 * - Fires `swh_ticket_replied` via `swh_fire_ticket_replied()`.
	 * - Dispatches the appropriate reply email via `swh_send_email()` unless
	 *   `dispatch_email=false`. Internal notes never email the requester.
	 *
	 * @since 4.1.0
	 *
	 * @param int                 $ticket_id Ticket post ID.
	 * @param array<string,mixed> $data      Reply payload (see above).
	 * @param array<string,mixed> $context   Optional caller hints. Recognised keys:
	 *                                       - `source` (string): `admin_form`, `rest_v1`,
	 *                                         `rest_internal`, `portal`. Phase 9 audit
	 *                                         log consumes this.
	 *                                       - `actor_id` (int): WP user id of the actor.
	 *                                       - `api_key_id` (string|null): When `source`
	 *                                         is `rest_v1` and the request authenticated
	 *                                         via API key.
	 * @return int|false New comment ID on success, false on validation /
	 *                   insert failure. On attachment-validation failure the
	 *                   function returns false WITHOUT inserting a comment;
	 *                   callers can inspect the most recent error via
	 *                   {@see swh_insert_helpdesk_reply_last_error()}.
	 */
	function swh_insert_helpdesk_reply( int $ticket_id, array $data, array $context = array() ) {
		unset( $context ); // Phase 9 audit consumer; currently unused.

		// Reset the last-error slot — see swh_insert_helpdesk_reply_last_error().
		$GLOBALS['_swh_insert_reply_last_error'] = array();

		if ( $ticket_id <= 0 ) {
			$GLOBALS['_swh_insert_reply_last_error'] = array( 'ticket_id' => __( 'Invalid ticket ID.', 'simple-wp-helpdesk' ) );
			return false;
		}
		$post = get_post( $ticket_id );
		if ( ! $post || 'helpdesk_ticket' !== $post->post_type ) {
			$GLOBALS['_swh_insert_reply_last_error'] = array( 'ticket_id' => __( 'Ticket not found.', 'simple-wp-helpdesk' ) );
			return false;
		}

		// --- author resolution -------------------------------------------------
		$author_user_id = isset( $data['author_user_id'] ) && is_numeric( $data['author_user_id'] )
			? (int) $data['author_user_id']
			: 0;
		$author_name    = isset( $data['author_name'] ) && is_string( $data['author_name'] )
			? sanitize_text_field( $data['author_name'] )
			: '';
		$author_email   = isset( $data['author_email'] ) && is_string( $data['author_email'] )
			? sanitize_email( $data['author_email'] )
			: '';
		if ( $author_user_id > 0 ) {
			$user = get_userdata( $author_user_id );
			if ( $user ) {
				if ( '' === $author_name && isset( $user->display_name ) && is_string( $user->display_name ) ) {
					$author_name = $user->display_name;
				}
				if ( '' === $author_email && isset( $user->user_email ) && is_string( $user->user_email ) ) {
					$author_email = $user->user_email;
				}
			}
		}
		if ( '' === $author_name ) {
			$author_name = $author_user_id > 0 ? __( 'Staff', 'simple-wp-helpdesk' ) : __( 'Client', 'simple-wp-helpdesk' );
		}

		// --- body sanitisation -------------------------------------------------
		$body_raw = isset( $data['body'] ) && is_string( $data['body'] ) ? $data['body'] : '';
		$body     = wp_kses_post( $body_raw );

		// --- attachment validation --------------------------------------------
		$attachment_urls  = array();
		$attachment_names = array();
		$has_pre_uploaded = isset( $data['attachment_urls'] ) && is_array( $data['attachment_urls'] ) && ! empty( $data['attachment_urls'] );
		$has_media_ids    = isset( $data['attachments'] ) && is_array( $data['attachments'] ) && ! empty( $data['attachments'] );

		if ( $has_pre_uploaded ) {
			// Security: reject any pre-uploaded URL that doesn't live under
			// the site's uploads baseurl. Without this check a caller could
			// inject an arbitrary remote URL into the reply meta and the
			// rendered HTML would happily link/embed it.
			$uploads      = wp_upload_dir();
			$baseurl      = isset( $uploads['baseurl'] ) && is_string( $uploads['baseurl'] ) ? $uploads['baseurl'] : '';
			$baseurl_norm = '' !== $baseurl ? rtrim( $baseurl, '/' ) . '/' : '';

			foreach ( $data['attachment_urls'] as $url ) {
				if ( ! is_string( $url ) || '' === $url ) {
					continue;
				}
				if ( '' === $baseurl_norm || strpos( $url, $baseurl_norm ) !== 0 ) {
					// Caller passed a URL outside the uploads dir — refuse the
					// whole insert so we never persist a poisoned reference.
					return false;
				}
				$attachment_urls[] = $url;
			}
			if ( isset( $data['attachment_names'] ) && is_array( $data['attachment_names'] ) ) {
				foreach ( $data['attachment_names'] as $url => $name ) {
					if ( is_string( $url ) && is_string( $name ) ) {
						$attachment_names[ $url ] = sanitize_text_field( $name );
					}
				}
			}
		}

		if ( $has_media_ids ) {
			$errors = array();
			foreach ( $data['attachments'] as $att_id ) {
				if ( ! is_numeric( $att_id ) || (int) $att_id <= 0 ) {
					$errors[] = is_scalar( $att_id ) ? (string) $att_id : '';
					continue;
				}
				$att_post = get_post( (int) $att_id );
				if ( ! $att_post || 'attachment' !== $att_post->post_type ) {
					$errors[] = (string) $att_id;
					continue;
				}
				$url = wp_get_attachment_url( (int) $att_id );
				if ( ! is_string( $url ) || '' === $url ) {
					$errors[] = (string) $att_id;
					continue;
				}
				// MIME allowlist mirrors swh_handle_multiple_uploads().
				$mime          = $att_post->post_mime_type;
				$allowed_mimes = array(
					'image/jpeg',
					'image/gif',
					'image/png',
					'application/pdf',
					'application/msword',
					'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					'text/plain',
				);
				if ( ! in_array( $mime, $allowed_mimes, true ) ) {
					$errors[] = (string) $att_id;
					continue;
				}
				$attachment_urls[]        = $url;
				$attachment_names[ $url ] = is_string( $att_post->post_title ) ? $att_post->post_title : '';
			}
			if ( ! empty( $errors ) ) {
				$GLOBALS['_swh_insert_reply_last_error'] = array( 'attachments' => $errors );
				return false;
			}
		}

		// --- required-field check ---------------------------------------------
		if ( '' === trim( $body ) && empty( $attachment_urls ) ) {
			$GLOBALS['_swh_insert_reply_last_error'] = array( 'body' => __( 'Reply body or at least one attachment is required.', 'simple-wp-helpdesk' ) );
			return false;
		}

		$is_internal_note = ! empty( $data['is_internal_note'] );
		$comment_content  = '' !== trim( $body ) ? $body : __( 'Attached file(s)', 'simple-wp-helpdesk' );

		$comment_args = array(
			'comment_post_ID'      => $ticket_id,
			'comment_author'       => $author_name,
			'comment_author_email' => $author_email,
			'comment_content'      => $comment_content,
			'comment_approved'     => 1,
			'comment_type'         => 'helpdesk_reply',
		);
		if ( $author_user_id > 0 ) {
			$comment_args['user_id'] = $author_user_id;
		}

		$comment_id = wp_insert_comment( $comment_args );
		if ( ! $comment_id ) {
			$GLOBALS['_swh_insert_reply_last_error'] = array( 'comment' => __( 'Failed to insert reply.', 'simple-wp-helpdesk' ) );
			return false;
		}
		$comment_id = (int) $comment_id;

		// --- comment meta ------------------------------------------------------
		if ( $is_internal_note ) {
			update_comment_meta( $comment_id, '_is_internal_note', '1' );
		} elseif ( $author_user_id > 0 ) {
			// Mirrors the legacy admin reply branch (`_is_user_reply='0'`
			// marks staff/system replies that are NOT internal notes;
			// portal client replies use a different writer path).
			update_comment_meta( $comment_id, '_is_user_reply', '0' );
		}
		if ( ! empty( $attachment_urls ) ) {
			update_comment_meta( $comment_id, '_attachments', $attachment_urls );
			if ( ! empty( $attachment_names ) ) {
				update_comment_meta( $comment_id, '_swh_reply_orignames', $attachment_names );
			}
		}

		// --- ticket-level bookkeeping -----------------------------------------
		update_post_meta( $ticket_id, '_swh_last_activity_at', time() );

		// `_swh_unread` semantics: client replies set the unread flag (admin
		// inbox surfaces them). Staff replies do not. Internal notes never set
		// the unread flag.
		if ( ! $is_internal_note && 0 === $author_user_id ) {
			update_post_meta( $ticket_id, '_swh_unread', '1' );
		}

		// First-response timestamp — staff reply that isn't the requester.
		if ( ! $is_internal_note && $author_user_id > 0 ) {
			$client_email = swh_get_string_meta( $ticket_id, '_ticket_email' );
			if ( $author_email !== $client_email && ! swh_get_int_meta( $ticket_id, '_ticket_first_response_at' ) ) {
				update_post_meta( $ticket_id, '_ticket_first_response_at', time() );
			}
		}

		// --- lifecycle hook ----------------------------------------------------
		swh_fire_ticket_replied( $ticket_id, $comment_id, $author_user_id );

		// Concurrency: bump the optimistic-locking version so any open editor
		// surfaces a conflict on the next save.
		if ( class_exists( '\\SWH\\Concurrency\\TicketVersion' ) ) {
			\SWH\Concurrency\TicketVersion::bump( $ticket_id );
		}

		// --- email dispatch ----------------------------------------------------
		$dispatch_email = ! array_key_exists( 'dispatch_email', $data ) || ! empty( $data['dispatch_email'] );
		if ( $dispatch_email && ! $is_internal_note ) {
			swh_insert_helpdesk_reply_dispatch_email( $ticket_id, $post, $body_raw, $attachment_urls, $author_user_id );
		}

		return $comment_id;
	}
}

if ( ! function_exists( 'swh_insert_helpdesk_reply_last_error' ) ) {
	/**
	 * Return the last validation/insert error raised by
	 * {@see swh_insert_helpdesk_reply()}. Cleared at the start of every
	 * call. Returns an empty array when the most recent call succeeded.
	 *
	 * @since 4.1.0
	 *
	 * @return array<string,mixed>
	 */
	function swh_insert_helpdesk_reply_last_error(): array {
		$err = isset( $GLOBALS['_swh_insert_reply_last_error'] ) && is_array( $GLOBALS['_swh_insert_reply_last_error'] )
			? $GLOBALS['_swh_insert_reply_last_error']
			: array();
		return $err;
	}
}

if ( ! function_exists( 'swh_insert_helpdesk_reply_dispatch_email' ) ) {
	/**
	 * Email-dispatch branch for {@see swh_insert_helpdesk_reply()}. Lives in a
	 * separate function so the standalone-reply email path is unit-testable
	 * and the helper itself stays readable. Staff replies email the requester
	 * with the reply body; client replies are not emailed (the existing
	 * notification listeners on `swh_ticket_replied` handle staff notification).
	 *
	 * @since 4.1.0
	 *
	 * @param int               $ticket_id      Ticket post ID.
	 * @param WP_Post           $post           Ticket post.
	 * @param string            $body_raw       Pre-sanitisation reply body (used as the
	 *                                          template `{message}` token).
	 * @param array<int,string> $attachment_urls Attachment URLs for the email.
	 * @param int               $author_user_id Author user id (0 for client / portal).
	 * @return void
	 */
	function swh_insert_helpdesk_reply_dispatch_email( int $ticket_id, $post, string $body_raw, array $attachment_urls, int $author_user_id ): void {
		if ( 0 === $author_user_id ) {
			return; // Client replies do not auto-email the client back.
		}
		$client_email = swh_get_string_meta( $ticket_id, '_ticket_email' );
		if ( '' === $client_email ) {
			return;
		}
		$name_meta = swh_get_string_meta( $ticket_id, '_ticket_name' );
		$uid_meta  = swh_get_string_meta( $ticket_id, '_ticket_uid' );
		$title     = '';
		if ( $post instanceof \WP_Post ) {
			$title = $post->post_title;
		}
		$data              = array(
			'name'           => '' !== $name_meta ? $name_meta : __( 'Client', 'simple-wp-helpdesk' ),
			'email'          => $client_email,
			'ticket_id'      => '' !== $uid_meta ? $uid_meta : 'TKT-' . str_pad( (string) $ticket_id, 4, '0', STR_PAD_LEFT ),
			'title'          => $title,
			'status'         => swh_get_string_meta( $ticket_id, '_ticket_status' ),
			'priority'       => swh_get_string_meta( $ticket_id, '_ticket_priority' ),
			'autoclose_days' => swh_get_int_option( 'swh_autoclose_days', 3 ),
			'ticket_url'     => swh_get_secure_ticket_link( $ticket_id ),
			'admin_url'      => swh_admin_ticket_url( $ticket_id ),
			'message'        => $body_raw,
		);
		$proxy_attach_urls = array_map(
			static function ( $url ) use ( $ticket_id ) {
				return swh_get_file_proxy_url( $url, $ticket_id );
			},
			$attachment_urls
		);
		swh_send_email( $client_email, 'swh_em_user_reply_sub', 'swh_em_user_reply_body', $data, $proxy_attach_urls, $ticket_id );
	}
}
