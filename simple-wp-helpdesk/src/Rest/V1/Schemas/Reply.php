<?php
declare(strict_types=1);

namespace SWH\Rest\V1\Schemas;

use WP_Comment;

use function get_comment_meta;
use function get_userdata;
use function rest_url;
use function wp_attachment_is;

/**
 * Single source of truth for the public `swh/v1` reply shape.
 *
 * **Authoritative for OpenAPI (Phase 8).** When Phase 8 generates the
 * machine-readable spec it imports the schema produced by {@see schema()} as
 * the `Reply` component. Adding a field here means it appears in the spec;
 * removing one is a breaking change for every API consumer. Treat any edit
 * to this file as a public-API change.
 *
 * **Allowlist, not denylist.** {@see from_comment()} returns exactly the
 * keys documented below — internal comment meta MUST NOT appear in the
 * output. The single-helper insert path
 * ({@see \swh_insert_helpdesk_reply()}, invariant #20) sets
 * `_is_user_reply` / `_is_internal_note` / `_attachments` /
 * `_swh_reply_orignames` on the comment; only the latter two are projected
 * into the response (`is_internal_note` + `attachments`).
 *
 * Locked schema (the OpenAPI Phase 8 `Reply` component):
 *
 * ```
 * id                int
 * body              string  (sanitised HTML — wp_kses_post-applied at insert time)
 * author            { type: enum(staff|client), id:int|null, display_name:string|null, email:string|null }
 * is_internal_note  bool
 * created_at        int  (unix)
 * attachments       [ { id:int|null, url:string, filename:string|null, mime:string|null, size:int|null } ]
 * links             { self:string, ticket:string }
 * ```
 *
 * @since 4.1.0
 */
final class Reply {

	/** REST namespace for self/ticket links. */
	private const REST_NAMESPACE = 'swh/v1';

	/**
	 * Return the WP REST `args`-shape reply schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'reply',
			'type'       => 'object',
			'properties' => array(
				'id'               => array(
					'description' => 'Unique reply identifier (comment ID).',
					'type'        => 'integer',
					'readonly'    => true,
				),
				'body'             => array(
					'description' => 'Reply body. Sanitised via wp_kses_post() at insert time.',
					'type'        => 'string',
				),
				'author'           => array(
					'description' => 'Reply author (staff or client).',
					'type'        => 'object',
					'properties'  => array(
						'type'         => array(
							'type' => 'string',
							'enum' => array( 'staff', 'client' ),
						),
						'id'           => array( 'type' => array( 'integer', 'null' ) ),
						'display_name' => array( 'type' => array( 'string', 'null' ) ),
						'email'        => array( 'type' => array( 'string', 'null' ) ),
					),
				),
				'is_internal_note' => array(
					'description' => 'When true, this reply is a staff-only internal note. Excluded from `read`-scope GET responses.',
					'type'        => 'boolean',
				),
				'created_at'       => array(
					'description' => 'Reply creation time (unix).',
					'type'        => 'integer',
				),
				'attachments'      => array(
					'description' => 'Files attached to the reply.',
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'       => array( 'type' => array( 'integer', 'null' ) ),
							'url'      => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'filename' => array( 'type' => array( 'string', 'null' ) ),
							'mime'     => array( 'type' => array( 'string', 'null' ) ),
							'size'     => array( 'type' => array( 'integer', 'null' ) ),
						),
					),
				),
				'links'            => array(
					'description' => 'Hypermedia links: self URL + parent ticket URL.',
					'type'        => 'object',
					'properties'  => array(
						'self'   => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'ticket' => array(
							'type'   => 'string',
							'format' => 'uri',
						),
					),
				),
			),
		);
	}

	/**
	 * Project a `helpdesk_reply` comment into the public reply payload.
	 *
	 * Allowlist — every key in the response is one of those documented in
	 * {@see schema()}. Internal comment meta is intentionally excluded.
	 *
	 * @param WP_Comment $comment Reply comment.
	 * @return array<string,mixed>
	 */
	public static function from_comment( WP_Comment $comment ): array {
		$id        = (int) $comment->comment_ID;
		$ticket_id = (int) $comment->comment_post_ID;

		// Author resolution. comment user_id > 0 means a logged-in WP user
		// wrote the reply (staff). user_id == 0 means client / portal / API
		// key (no WP user backing).
		$author_user_id = (int) $comment->user_id;
		$author         = array(
			'type'         => $author_user_id > 0 ? 'staff' : 'client',
			'id'           => $author_user_id > 0 ? $author_user_id : null,
			'display_name' => '' !== $comment->comment_author ? $comment->comment_author : null,
			'email'        => '' !== $comment->comment_author_email ? $comment->comment_author_email : null,
		);
		// Re-derive display_name from the user record when available (covers
		// renames after the comment was written).
		if ( $author_user_id > 0 ) {
			$user = get_userdata( $author_user_id );
			if ( $user && isset( $user->display_name ) && is_string( $user->display_name ) ) {
				$author['display_name'] = $user->display_name;
			}
		}

		$is_internal_raw  = get_comment_meta( $id, '_is_internal_note', true );
		$is_internal_note = is_scalar( $is_internal_raw ) && '1' === (string) $is_internal_raw;

		$created_at = 0;
		if ( '' !== $comment->comment_date_gmt ) {
			$ts         = strtotime( $comment->comment_date_gmt . ' UTC' );
			$created_at = is_int( $ts ) ? $ts : 0;
		}

		$attachments = array();
		$urls        = get_comment_meta( $id, '_attachments', true );
		$names       = get_comment_meta( $id, '_swh_reply_orignames', true );
		if ( is_array( $urls ) ) {
			$name_map = is_array( $names ) ? $names : array();
			foreach ( $urls as $url ) {
				if ( ! is_string( $url ) || '' === $url ) {
					continue;
				}
				$attachments[] = array(
					'id'       => null,
					'url'      => $url,
					'filename' => isset( $name_map[ $url ] ) && is_string( $name_map[ $url ] ) ? $name_map[ $url ] : null,
					'mime'     => null,
					'size'     => null,
				);
			}
		}

		return array(
			'id'               => $id,
			'body'             => $comment->comment_content,
			'author'           => $author,
			'is_internal_note' => $is_internal_note,
			'created_at'       => $created_at,
			'attachments'      => $attachments,
			'links'            => array(
				'self'   => rest_url( self::REST_NAMESPACE . '/tickets/' . $ticket_id . '/replies/' . $id ),
				'ticket' => rest_url( self::REST_NAMESPACE . '/tickets/' . $ticket_id ),
			),
		);
	}
}
