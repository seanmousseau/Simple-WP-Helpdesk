<?php
declare(strict_types=1);

namespace SWH\Views;

use function current_user_can;
use function get_option;
use function get_user_meta;
use function update_option;
use function update_user_meta;
use function wp_generate_uuid4;

/**
 * Storage layer for SWH saved views.
 *
 * Personal views live in user_meta `swh_personal_views`.
 * Global views live in wp_options `swh_global_views`.
 * Per-user hidden globals live in user_meta `swh_hidden_global_views`.
 *
 * @since 4.0.0 (#352)
 */
final class ViewsRepository {

	public const META_PERSONAL       = 'swh_personal_views';
	public const META_HIDDEN_GLOBALS = 'swh_hidden_global_views';
	public const META_DEFAULT_VIEW   = 'swh_default_view';
	public const OPT_GLOBAL          = 'swh_global_views';

	/**
	 * Built-in (system) view keys that are always valid as a default.
	 * Mirror of DEFAULT_VIEWS in assets/src/admin-inbox/views/defaults.js.
	 */
	private const BUILTIN_VIEWS = array( 'all', 'unassigned', 'my_open', 'high_priority', 'resolved_this_week' );

	/**
	 * Returns global + personal views for the user, with personal views
	 * appended after globals. Hidden globals are filtered out.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all_for_user( int $user_id ): array {
		$personal = $this->read_array( get_user_meta( $user_id, self::META_PERSONAL, true ) );
		$hidden   = $this->read_array( get_user_meta( $user_id, self::META_HIDDEN_GLOBALS, true ) );
		$globals  = $this->read_array( get_option( self::OPT_GLOBAL, array() ) );

		$out = array();
		foreach ( $globals as $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}
			if ( ! isset( $g['id'] ) ) {
				continue;
			}
			if ( in_array( $g['id'], $hidden, true ) ) {
				continue;
			}
			$g['scope'] = 'global';
			$out[]      = $g;
		}
		foreach ( $personal as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			if ( ! isset( $p['id'] ) ) {
				continue;
			}
			$p['scope'] = 'personal';
			$out[]      = $p;
		}
		return $out;
	}

	/**
	 * Save a new personal view for the user. Returns the saved view with id.
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $view     View data (name, filters, sort, columns).
	 * @return array<string, mixed>
	 */
	public function save_personal( int $user_id, array $view ): array {
		$existing   = $this->read_array( get_user_meta( $user_id, self::META_PERSONAL, true ) );
		$view['id'] = wp_generate_uuid4();
		$existing[] = $view;
		update_user_meta( $user_id, self::META_PERSONAL, $existing );
		return $view;
	}

	/**
	 * Update a personal view by id. Returns the updated view, or null if not found.
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param string               $id      View UUID.
	 * @param array<string, mixed> $patch   Fields to merge into the existing view.
	 * @return array<string, mixed>|null
	 */
	public function update_personal( int $user_id, string $id, array $patch ): ?array {
		$existing = $this->read_array( get_user_meta( $user_id, self::META_PERSONAL, true ) );
		foreach ( $existing as $i => $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}
			if ( ( $v['id'] ?? '' ) === $id ) {
				$existing[ $i ] = array_merge( $v, $patch, array( 'id' => $id ) );
				update_user_meta( $user_id, self::META_PERSONAL, $existing );
				return $existing[ $i ];
			}
		}
		return null;
	}

	/**
	 * Delete a personal view by id. Returns true if deleted.
	 */
	public function delete_personal( int $user_id, string $id ): bool {
		$existing = $this->read_array( get_user_meta( $user_id, self::META_PERSONAL, true ) );
		$filtered = array_values(
			array_filter( $existing, static fn( $v ) => is_array( $v ) && ( $v['id'] ?? '' ) !== $id )
		);
		if ( count( $filtered ) === count( $existing ) ) {
			return false;
		}
		update_user_meta( $user_id, self::META_PERSONAL, $filtered );
		return true;
	}

	/**
	 * Save a new global view. Requires manage_options. Returns the saved view
	 * with id, or null if capability check fails.
	 *
	 * @param array<string, mixed> $view
	 * @return array<string, mixed>|null
	 */
	public function save_global( array $view ): ?array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}
		$existing   = $this->read_array( get_option( self::OPT_GLOBAL, array() ) );
		$view['id'] = wp_generate_uuid4();
		$existing[] = $view;
		update_option( self::OPT_GLOBAL, $existing );
		return $view;
	}

	/**
	 * Update a global view by id. Requires manage_options.
	 *
	 * @param string               $id    View UUID.
	 * @param array<string, mixed> $patch Fields to merge into the existing view.
	 * @return array<string, mixed>|null
	 */
	public function update_global( string $id, array $patch ): ?array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}
		$existing = $this->read_array( get_option( self::OPT_GLOBAL, array() ) );
		foreach ( $existing as $i => $v ) {
			if ( ! is_array( $v ) ) {
				continue;
			}
			if ( ( $v['id'] ?? '' ) === $id ) {
				$existing[ $i ] = array_merge( $v, $patch, array( 'id' => $id ) );
				update_option( self::OPT_GLOBAL, $existing );
				return $existing[ $i ];
			}
		}
		return null;
	}

	/**
	 * Delete a global view by id. Requires manage_options.
	 */
	public function delete_global( string $id ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$existing = $this->read_array( get_option( self::OPT_GLOBAL, array() ) );
		$filtered = array_values(
			array_filter( $existing, static fn( $v ) => is_array( $v ) && ( $v['id'] ?? '' ) !== $id )
		);
		if ( count( $filtered ) === count( $existing ) ) {
			return false;
		}
		update_option( self::OPT_GLOBAL, $filtered );
		return true;
	}

	/**
	 * Toggle whether a global view is hidden for the user. No capability gate
	 * (every user controls their own visibility).
	 */
	public function set_hidden_for_user( int $user_id, string $global_id, bool $hidden ): void {
		$existing = $this->read_array( get_user_meta( $user_id, self::META_HIDDEN_GLOBALS, true ) );
		if ( $hidden ) {
			if ( ! in_array( $global_id, $existing, true ) ) {
				$existing[] = $global_id;
			}
		} else {
			$existing = array_values(
				array_filter( $existing, static fn( $id ) => $id !== $global_id )
			);
		}
		update_user_meta( $user_id, self::META_HIDDEN_GLOBALS, $existing );
	}

	/**
	 * Get the user's stored default view id (or built-in key). Returns null if
	 * unset. Does NOT validate that the referenced view still exists —
	 * callers wanting a stale-clean read should use resolve_default_for_user().
	 */
	public function get_default_for_user( int $user_id ): ?string {
		$raw = get_user_meta( $user_id, self::META_DEFAULT_VIEW, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		return $raw;
	}

	/**
	 * Set (or clear, with null) the user's default view. The id can be:
	 *   - a built-in view key ('all', 'unassigned', 'my_open', etc.)
	 *   - a personal or global view UUID
	 *
	 * No existence check at write time — a default can be set for a view
	 * that the caller has visible at the moment. Stale defaults are filtered
	 * out at read time by resolve_default_for_user().
	 */
	public function set_default_for_user( int $user_id, ?string $view_id ): void {
		if ( null === $view_id || '' === $view_id ) {
			update_user_meta( $user_id, self::META_DEFAULT_VIEW, '' );
			return;
		}
		update_user_meta( $user_id, self::META_DEFAULT_VIEW, $view_id );
	}

	/**
	 * Returns the user's default view ID if it still resolves to a real view,
	 * otherwise null. Built-in views always resolve. For UUIDs, checks against
	 * the user's visible view set (`get_all_for_user`) — a personal view that
	 * got deleted, or a global view the user has hidden, returns null so the
	 * client falls back to the system default.
	 */
	public function resolve_default_for_user( int $user_id ): ?string {
		$raw = $this->get_default_for_user( $user_id );
		if ( null === $raw ) {
			return null;
		}
		if ( in_array( $raw, self::BUILTIN_VIEWS, true ) ) {
			return $raw;
		}
		foreach ( $this->get_all_for_user( $user_id ) as $v ) {
			if ( is_array( $v ) && ( $v['id'] ?? '' ) === $raw ) {
				return $raw;
			}
		}
		return null;
	}

	/**
	 * Normalize meta/option reads to array.
	 *
	 * @param mixed $value
	 * @return array<int, mixed>
	 */
	private function read_array( $value ): array {
		return is_array( $value ) ? $value : array();
	}
}
