<?php
declare(strict_types=1);

namespace SWH\Rest\V1\Schemas;

use WP_Post;

use function get_post_meta;
use function get_term;
use function get_userdata;
use function rest_url;
use function swh_get_int_meta;
use function swh_get_string_meta;
use function wp_get_post_terms;

/**
 * Single source of truth for the public `swh/v1` ticket shape.
 *
 * **Authoritative for OpenAPI (Phase 8).** When Phase 8 generates the
 * machine-readable spec it imports the schema produced by {@see schema()} as
 * the `Ticket` component. Adding a field here means it appears in the spec;
 * removing one is a breaking change for every API consumer. Treat any edit
 * to this file as a public-API change.
 *
 * **Allowlist, not denylist.** {@see from_post()} returns exactly the keys
 * documented below — internal post meta such as `_swh_unread`, the API-key
 * access log, and `_ticket_token` MUST NOT appear in the output. Any code
 * path that "just adds the meta" to a response is a leak.
 *
 * Locked schema (the OpenAPI Phase 8 `Ticket` component):
 *
 * ```
 * id                  int
 * subject             string
 * status              enum (configurable; usually Open / In Progress / Resolved / Closed)
 * priority            enum (configurable; usually Low / Medium / High / Urgent)
 * assignee            { id:int|null, display_name:string|null }
 * requester           { email:string, name:string|null }
 * category            { id:int|null, name:string|null, slug:string|null }
 * sla                 { due_at:int|null, breached:bool }
 * csat                { score:int|null, comment:string|null }
 * created_at          int (unix)
 * updated_at          int (unix)
 * first_response_at   int|null
 * links               { self:string, replies:string }
 * ```
 *
 * @since 4.1.0
 */
final class Ticket {

	/** REST namespace for self/replies links. */
	private const REST_NAMESPACE = 'swh/v1';

	/**
	 * Return the WP REST `args`-shape ticket schema. Used by
	 * {@see \SWH\Rest\V1\TicketsController::get_item_schema()} and by Phase 8
	 * when emitting the OpenAPI `Ticket` component.
	 *
	 * @return array<string,mixed>
	 */
	public static function schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'ticket',
			'type'       => 'object',
			'properties' => array(
				'id'                => array(
					'description' => 'Unique ticket identifier (post ID).',
					'type'        => 'integer',
					'readonly'    => true,
				),
				'subject'           => array(
					'description' => 'Ticket subject (post title).',
					'type'        => 'string',
				),
				'status'            => array(
					'description' => 'Configured status label. Must be a member of the configured status list.',
					'type'        => 'string',
				),
				'priority'          => array(
					'description' => 'Configured priority label. Must be a member of the configured priority list.',
					'type'        => 'string',
				),
				'assignee'          => array(
					'description' => 'Assigned technician (id + display name) or nulls when unassigned.',
					'type'        => 'object',
					'properties'  => array(
						'id'           => array( 'type' => array( 'integer', 'null' ) ),
						'display_name' => array( 'type' => array( 'string', 'null' ) ),
					),
				),
				'requester'         => array(
					'description' => 'Client requester contact.',
					'type'        => 'object',
					'properties'  => array(
						'email' => array(
							'type'   => 'string',
							'format' => 'email',
						),
						'name'  => array( 'type' => array( 'string', 'null' ) ),
					),
				),
				'category'          => array(
					'description' => 'Primary helpdesk_category term, or nulls when no category is set.',
					'type'        => 'object',
					'properties'  => array(
						'id'   => array( 'type' => array( 'integer', 'null' ) ),
						'name' => array( 'type' => array( 'string', 'null' ) ),
						'slug' => array( 'type' => array( 'string', 'null' ) ),
					),
				),
				'sla'               => array(
					'description' => 'SLA due-at unix timestamp and breach flag.',
					'type'        => 'object',
					'properties'  => array(
						'due_at'   => array( 'type' => array( 'integer', 'null' ) ),
						'breached' => array( 'type' => 'boolean' ),
					),
				),
				'csat'              => array(
					'description' => 'Client satisfaction rating (1–5) + optional comment.',
					'type'        => 'object',
					'properties'  => array(
						'score'   => array( 'type' => array( 'integer', 'null' ) ),
						'comment' => array( 'type' => array( 'string', 'null' ) ),
					),
				),
				'created_at'        => array(
					'description' => 'Ticket creation time (unix).',
					'type'        => 'integer',
				),
				'updated_at'        => array(
					'description' => 'Last activity time (unix).',
					'type'        => 'integer',
				),
				'first_response_at' => array(
					'description' => 'First staff reply time (unix), or null when no staff reply yet.',
					'type'        => array( 'integer', 'null' ),
				),
				'links'             => array(
					'description' => 'Hypermedia links: self URL + replies-collection URL.',
					'type'        => 'object',
					'properties'  => array(
						'self'    => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'replies' => array(
							'type'   => 'string',
							'format' => 'uri',
						),
					),
				),
			),
		);
	}

	/**
	 * Project a `helpdesk_ticket` post into the public ticket payload.
	 *
	 * Allowlist — every key in the response is one of those documented in
	 * {@see schema()}. Internal meta is intentionally excluded.
	 *
	 * @param WP_Post $post Ticket post.
	 * @return array<string,mixed>
	 */
	public static function from_post( WP_Post $post ): array {
		$id = (int) $post->ID;

		// Assignee.
		$assignee_id   = swh_get_int_meta( $id, '_ticket_assigned_to' );
		$assignee_name = null;
		if ( $assignee_id > 0 ) {
			$user = get_userdata( $assignee_id );
			if ( $user && isset( $user->display_name ) ) {
				$assignee_name = (string) $user->display_name;
			}
		}

		// Category — primary (first) helpdesk_category term.
		$category = array(
			'id'   => null,
			'name' => null,
			'slug' => null,
		);
		$terms    = wp_get_post_terms( $id, 'helpdesk_category' );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			$first = $terms[0];
			if ( $first instanceof \WP_Term ) {
				$category = array(
					'id'   => (int) $first->term_id,
					'name' => (string) $first->name,
					'slug' => (string) $first->slug,
				);
			}
		}

		// SLA.
		$sla_due_at       = swh_get_int_meta( $id, '_ticket_sla_due_at' );
		$sla_breached_raw = get_post_meta( $id, '_ticket_sla_breached_at', true );
		$sla_breached     = is_scalar( $sla_breached_raw ) && '' !== (string) $sla_breached_raw && '0' !== (string) $sla_breached_raw;

		// CSAT.
		$csat_score_raw   = get_post_meta( $id, '_ticket_csat', true );
		$csat_score       = is_numeric( $csat_score_raw ) ? (int) $csat_score_raw : null;
		$csat_comment_raw = get_post_meta( $id, '_ticket_csat_comment', true );
		$csat_comment     = is_string( $csat_comment_raw ) && '' !== $csat_comment_raw ? $csat_comment_raw : null;

		// Timestamps.
		$created_at = '' !== $post->post_date_gmt ? strtotime( $post->post_date_gmt . ' UTC' ) : 0;
		$created_at = is_int( $created_at ) ? $created_at : 0;
		$updated_at = swh_get_int_meta( $id, '_swh_last_activity_at' );
		if ( 0 === $updated_at && '' !== $post->post_modified_gmt ) {
			$ts         = strtotime( $post->post_modified_gmt . ' UTC' );
			$updated_at = is_int( $ts ) ? $ts : 0;
		}
		$first_response_raw = get_post_meta( $id, '_ticket_first_response_at', true );
		$first_response_at  = is_numeric( $first_response_raw ) && (int) $first_response_raw > 0 ? (int) $first_response_raw : null;

		return array(
			'id'                => $id,
			'subject'           => $post->post_title,
			'status'            => swh_get_string_meta( $id, '_ticket_status' ),
			'priority'          => swh_get_string_meta( $id, '_ticket_priority' ),
			'assignee'          => array(
				'id'           => $assignee_id > 0 ? $assignee_id : null,
				'display_name' => $assignee_name,
			),
			'requester'         => array(
				'email' => swh_get_string_meta( $id, '_ticket_email' ),
				'name'  => '' !== swh_get_string_meta( $id, '_ticket_name' ) ? swh_get_string_meta( $id, '_ticket_name' ) : null,
			),
			'category'          => $category,
			'sla'               => array(
				'due_at'   => $sla_due_at > 0 ? $sla_due_at : null,
				'breached' => $sla_breached,
			),
			'csat'              => array(
				'score'   => $csat_score,
				'comment' => $csat_comment,
			),
			'created_at'        => $created_at,
			'updated_at'        => $updated_at,
			'first_response_at' => $first_response_at,
			'links'             => array(
				'self'    => rest_url( self::REST_NAMESPACE . '/tickets/' . $id ),
				'replies' => rest_url( self::REST_NAMESPACE . '/tickets/' . $id . '/replies' ),
			),
		);
	}
}
