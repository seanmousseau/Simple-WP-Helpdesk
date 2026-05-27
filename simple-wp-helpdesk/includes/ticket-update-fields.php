<?php
/**
 * Context-neutral ticket field mutation helper.
 *
 * Extracted from `swh_save_ticket_data()` in v4.1.0 Phase 3 so the admin
 * save-post path AND the new public REST controller can share a single
 * mutation choke point.
 *
 * @package Simple_WP_Helpdesk
 * @since   4.1.0
 */

declare(strict_types=1);

if ( ! function_exists( 'swh_update_ticket_fields' ) ) {
	/**
	 * Update a ticket's fields in a context-neutral way.
	 *
	 * This is the single canonical entry point for ticket field mutations.
	 * Callers (admin save-post, internal REST drawer, public REST v1) are
	 * responsible for their own capability + nonce checks BEFORE calling this.
	 *
	 * Recognised keys in `$fields`:
	 *
	 * - `status` (string) — must be in `swh_get_statuses()`. Routed through
	 *   `swh_set_ticket_status()` (invariant #1, design-document.md).
	 * - `priority` (string) — must be in `swh_get_priorities()`.
	 * - `assignee` (int) — WP user ID. Must be an administrator or technician.
	 *   `0` clears the assignee. Fires `swh_ticket_assigned` on change.
	 * - `category` (int) — `helpdesk_category` term ID. `0` clears the category.
	 * - `subject` (string) — ticket title (`wp_update_post`).
	 * - `body` (string) — ticket content (`wp_update_post`).
	 * - `requester_email` (string) — `_ticket_email` meta.
	 * - `requester_name` (string) — `_ticket_name` meta.
	 * - `cc_emails` (string) — comma-separated email list. Each value is
	 *   re-validated via `is_email()` and silently dropped on failure.
	 *
	 * Unrecognised keys are ignored — callers should validate at the boundary.
	 *
	 * Side effects:
	 *
	 * - Status mutations route through `swh_set_ticket_status()` only.
	 * - Resolved-timestamp bookkeeping (`_resolved_timestamp` meta) mirrors
	 *   the legacy admin path.
	 * - Assignee changes fire `swh_ticket_assigned` so the v4.0.2 assignment
	 *   email listener dispatches.
	 *
	 * Out of scope (callers handle separately):
	 *
	 * - Reply / internal-note insertion (Phase 4 — `swh_insert_helpdesk_reply()`).
	 * - File uploads (callers own the upload pipeline).
	 * - Direct email side effects (downstream listeners fire from the lifecycle
	 *   hooks above).
	 *
	 * @since 4.1.0
	 *
	 * @param int                 $ticket_id Ticket post ID.
	 * @param array<string,mixed> $fields    Vetted field array (sanitised at the boundary).
	 * @param array<string,mixed> $context   Optional caller hints. Recognised keys:
	 *                                       - `source` (string): e.g. `admin_save`, `rest_internal`,
	 *                                         `rest_v1`. Phase 9 audit log consumes this.
	 *                                       - `actor_id` (int): WP user ID making the change. Defaults
	 *                                         to `get_current_user_id()` when omitted.
	 *                                       - `api_key_id` (string|null): When `source === 'rest_v1'`
	 *                                         and the request authenticated via API key.
	 * @return array{ok: bool, errors: array<string,string>, applied: array<int,string>}
	 *               `ok` is true iff every supplied field validated. `errors` maps
	 *               rejected field keys to a human-readable reason. `applied` lists
	 *               the field keys actually written (useful for tests and audit logs).
	 */
	function swh_update_ticket_fields( int $ticket_id, array $fields, array $context = array() ): array {
		unset( $context ); // Phase 9 will consume the audit context; currently unused.

		$errors  = array();
		$applied = array();

		if ( $ticket_id <= 0 ) {
			return array(
				'ok'      => false,
				'errors'  => array( 'ticket_id' => __( 'Invalid ticket ID.', 'simple-wp-helpdesk' ) ),
				'applied' => $applied,
			);
		}

		$post = get_post( $ticket_id );
		if ( ! $post || 'helpdesk_ticket' !== $post->post_type ) {
			return array(
				'ok'      => false,
				'errors'  => array( 'ticket_id' => __( 'Ticket not found.', 'simple-wp-helpdesk' ) ),
				'applied' => $applied,
			);
		}

		$old_status      = swh_get_string_meta( $ticket_id, '_ticket_status' );
		$old_assigned_to = swh_get_int_meta( $ticket_id, '_ticket_assigned_to' );
		$new_status      = $old_status;

		// --- status ---
		if ( array_key_exists( 'status', $fields ) ) {
			$candidate = is_string( $fields['status'] ) ? $fields['status'] : '';
			$allowed   = swh_get_statuses();
			$allowed   = is_array( $allowed ) ? $allowed : array();
			if ( '' === $candidate || ! in_array( $candidate, $allowed, true ) ) {
				$errors['status'] = __( 'Status is not one of the configured values.', 'simple-wp-helpdesk' );
			} else {
				swh_set_ticket_status( $ticket_id, $candidate );
				$new_status = $candidate;
				$applied[]  = 'status';
			}
		}

		// --- priority ---
		if ( array_key_exists( 'priority', $fields ) ) {
			$candidate = is_string( $fields['priority'] ) ? $fields['priority'] : '';
			$allowed   = swh_get_priorities();
			$allowed   = is_array( $allowed ) ? $allowed : array();
			if ( '' === $candidate || ! in_array( $candidate, $allowed, true ) ) {
				$errors['priority'] = __( 'Priority is not one of the configured values.', 'simple-wp-helpdesk' );
			} else {
				update_post_meta( $ticket_id, '_ticket_priority', $candidate );
				$applied[] = 'priority';
			}
		}

		// --- assignee ---
		if ( array_key_exists( 'assignee', $fields ) ) {
			$candidate = is_numeric( $fields['assignee'] ) ? (int) $fields['assignee'] : -1;
			if ( $candidate < 0 ) {
				$errors['assignee'] = __( 'Assignee must be a user ID or 0 to unassign.', 'simple-wp-helpdesk' );
			} else {
				$resolved = 0;
				if ( $candidate > 0 ) {
					$user = get_userdata( $candidate );
					if ( ! $user || empty( array_intersect( array( 'administrator', 'technician' ), (array) $user->roles ) ) ) {
						$errors['assignee'] = __( 'Assignee must be an administrator or technician.', 'simple-wp-helpdesk' );
					} else {
						$resolved = $candidate;
					}
				}
				if ( ! isset( $errors['assignee'] ) ) {
					update_post_meta( $ticket_id, '_ticket_assigned_to', $resolved > 0 ? $resolved : '' );
					if ( $resolved !== $old_assigned_to ) {
						do_action( 'swh_ticket_assigned', $ticket_id, (int) $old_assigned_to, (int) $resolved );
					}
					$applied[] = 'assignee';
				}
			}
		}

		// --- category (helpdesk_category term ID) ---
		if ( array_key_exists( 'category', $fields ) ) {
			$candidate = is_numeric( $fields['category'] ) ? (int) $fields['category'] : -1;
			if ( $candidate < 0 ) {
				$errors['category'] = __( 'Category must be a term ID or 0 to clear.', 'simple-wp-helpdesk' );
			} else {
				if ( $candidate > 0 ) {
					$term = get_term( $candidate, 'helpdesk_category' );
					if ( ! $term || is_wp_error( $term ) ) {
						$errors['category'] = __( 'Category term not found.', 'simple-wp-helpdesk' );
					}
				}
				if ( ! isset( $errors['category'] ) ) {
					$term_ids = $candidate > 0 ? array( $candidate ) : array();
					wp_set_post_terms( $ticket_id, $term_ids, 'helpdesk_category', false );
					$applied[] = 'category';
				}
			}
		}

		// --- subject + body (wp_update_post) ---
		$post_update = array();
		if ( array_key_exists( 'subject', $fields ) ) {
			$candidate = is_string( $fields['subject'] ) ? trim( $fields['subject'] ) : '';
			if ( '' === $candidate ) {
				$errors['subject'] = __( 'Subject cannot be empty.', 'simple-wp-helpdesk' );
			} else {
				$post_update['post_title'] = sanitize_text_field( $candidate );
			}
		}
		if ( array_key_exists( 'body', $fields ) ) {
			$candidate                   = is_string( $fields['body'] ) ? $fields['body'] : '';
			$post_update['post_content'] = wp_kses_post( $candidate );
		}
		if ( ! empty( $post_update ) ) {
			$post_update['ID'] = $ticket_id;
			$result            = wp_update_post( $post_update, true );
			if ( is_wp_error( $result ) ) {
				$errors['post'] = __( 'Failed to update ticket post.', 'simple-wp-helpdesk' );
			} else {
				if ( isset( $post_update['post_title'] ) ) {
					$applied[] = 'subject';
				}
				if ( isset( $post_update['post_content'] ) ) {
					$applied[] = 'body';
				}
			}
		}

		// --- requester email + name ---
		if ( array_key_exists( 'requester_email', $fields ) ) {
			$candidate = is_string( $fields['requester_email'] ) ? sanitize_email( $fields['requester_email'] ) : '';
			if ( '' === $candidate || ! is_email( $candidate ) ) {
				$errors['requester_email'] = __( 'Requester email must be a valid email address.', 'simple-wp-helpdesk' );
			} else {
				update_post_meta( $ticket_id, '_ticket_email', $candidate );
				$applied[] = 'requester_email';
			}
		}
		if ( array_key_exists( 'requester_name', $fields ) ) {
			$candidate = is_string( $fields['requester_name'] ) ? sanitize_text_field( $fields['requester_name'] ) : '';
			update_post_meta( $ticket_id, '_ticket_name', $candidate );
			$applied[] = 'requester_name';
		}

		// --- CC / watcher emails (comma-separated string) ---
		if ( array_key_exists( 'cc_emails', $fields ) ) {
			$raw      = is_string( $fields['cc_emails'] ) ? $fields['cc_emails'] : '';
			$cc_parts = array_map( 'sanitize_email', array_map( 'trim', explode( ',', $raw ) ) );
			$cc_clean = implode(
				', ',
				array_filter(
					$cc_parts,
					static function ( string $addr ): bool {
						return (bool) is_email( $addr );
					}
				)
			);
			update_post_meta( $ticket_id, '_ticket_cc_emails', $cc_clean );
			$applied[] = 'cc_emails';
		}

		// --- resolved-timestamp bookkeeping (mirrors admin path) ---
		if ( in_array( 'status', $applied, true ) ) {
			$defs            = swh_get_defaults();
			$resolved_status = swh_get_string_option(
				'swh_resolved_status',
				is_string( $defs['swh_resolved_status'] ) ? $defs['swh_resolved_status'] : ''
			);
			if ( $resolved_status === $new_status && $old_status !== $new_status ) {
				update_post_meta( $ticket_id, '_resolved_timestamp', time() );
			} elseif ( $resolved_status === $old_status && $resolved_status !== $new_status ) {
				delete_post_meta( $ticket_id, '_resolved_timestamp' );
			}
		}

		return array(
			'ok'      => empty( $errors ),
			'errors'  => $errors,
			'applied' => $applied,
		);
	}
}
