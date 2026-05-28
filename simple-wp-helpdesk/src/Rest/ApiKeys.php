<?php
declare(strict_types=1);

namespace SWH\Rest;

use InvalidArgumentException;

use function get_current_user_id;
use function get_option;
use function get_transient;
use function hash_equals;
use function password_hash;
use function password_verify;
use function set_transient;
use function update_option;
use function wp_generate_password;
use function wp_generate_uuid4;

/**
 * API key storage + verification layer.
 *
 * Stores entries inside the `swh_settings_integrations` option bag under the
 * `api_keys` sub-key. This class is the ONLY writer for `integrations.api_keys`;
 * other code reads via `swh_get_option( 'integrations', 'api_keys', [] )`.
 *
 * Plaintext format: `swhk_<base62 32-char>`. The prefix (first 12 chars) is
 * stored for UI display + as a constant-time short-circuit during lookup.
 * The full plaintext is bcrypt-hashed (cost 12) before storage and is NEVER
 * persisted, logged, or returned outside the initial `generate()` call.
 *
 * Public surface (locked for v4.1.0 Phase 1):
 *   - generate( $label, $scope, $rate_limit = null ): array
 *   - find_by_plaintext( $plaintext ): ?array
 *   - revoke( $id ): bool
 *   - touch_last_used( $id ): void
 *   - all(): array
 *
 * Invariants:
 *   - Plaintext is shown exactly once at generation; never re-derivable.
 *   - `hash_equals()` is used for prefix comparison (invariant #3).
 *   - `password_verify()` is used for hash comparison.
 *   - bcrypt cost 12 — first `password_hash` site in the codebase.
 *   - `touch_last_used()` is write-rate-limited to once per 5 min per key.
 *
 * @since 4.1.0
 * @see   docs/internal/security-model.md "API key authentication"
 * @see   docs/internal/data-dictionary.md `integrations.api_keys`
 */
final class ApiKeys {

	/** Option bag name. */
	private const OPTION = 'swh_settings_integrations';

	/** Sub-key within the bag. */
	private const SUBKEY = 'api_keys';

	/** Plaintext prefix shared by all keys. */
	private const PLAINTEXT_PREFIX = 'swhk_';

	/** Random portion length (base62, no special chars). */
	private const RANDOM_LEN = 32;

	/** Stored prefix length (first N chars of plaintext). */
	private const STORED_PREFIX_LEN = 12;

	/** Bcrypt cost factor. */
	private const BCRYPT_COST = 12;

	/** Maximum operator-supplied label length. */
	private const MAX_LABEL_LEN = 60;

	/** Allowed scopes. 'write' implies 'read'. */
	private const SCOPES = array( 'read', 'write' );

	/** TTL for the `touch_last_used` write-rate-limit transient (seconds). */
	private const TOUCH_TTL = 300;

	/**
	 * Generate a new API key. Plaintext is returned exactly once.
	 *
	 * @param string   $label      Operator-supplied display name (1..60 chars).
	 * @param string   $scope      'read' or 'write'.
	 * @param int|null $rate_limit Optional per-key rate-limit override.
	 * @return array{plaintext:string, entry:array<string,mixed>} The plaintext and the persisted entry MINUS the hash.
	 * @throws InvalidArgumentException If $label or $scope is invalid.
	 * @throws \RuntimeException If the persistent option write fails.
	 */
	public static function generate( string $label, string $scope, ?int $rate_limit = null ): array {
		$label = trim( $label );
		if ( '' === $label || strlen( $label ) > self::MAX_LABEL_LEN ) {
			throw new InvalidArgumentException( 'API key label must be 1-60 characters.' );
		}
		if ( ! in_array( $scope, self::SCOPES, true ) ) {
			throw new InvalidArgumentException( 'API key scope must be "read" or "write".' );
		}

		$random    = wp_generate_password( self::RANDOM_LEN, false, false );
		$plaintext = self::PLAINTEXT_PREFIX . $random;
		$prefix    = substr( $plaintext, 0, self::STORED_PREFIX_LEN );
		$hash      = password_hash( $plaintext, PASSWORD_BCRYPT, array( 'cost' => self::BCRYPT_COST ) );

		$entry = array(
			'id'           => 'k_' . wp_generate_uuid4(),
			'label'        => $label,
			'scope'        => $scope,
			'prefix'       => $prefix,
			'hash'         => $hash,
			'created_at'   => time(),
			'created_by'   => (int) get_current_user_id(),
			'last_used_at' => 0,
			'rate_limit'   => $rate_limit,
			'revoked_at'   => null,
		);

		$entries   = self::all();
		$entries[] = $entry;
		if ( ! self::persist( $entries ) ) {
			throw new \RuntimeException( 'Failed to persist new API key entry.' );
		}

		// Exclude the hash from the returned entry so callers cannot accidentally log it.
		$public_entry = $entry;
		unset( $public_entry['hash'] );

		return array(
			'plaintext' => $plaintext,
			'entry'     => $public_entry,
		);
	}

	/**
	 * Resolve a plaintext bearer token to an entry.
	 *
	 * Returns null if no entry matches or if the matched entry is revoked.
	 * Does NOT call `touch_last_used()` — callers do that on successful auth
	 * to keep this method side-effect free.
	 *
	 * @param string $plaintext Bearer token presented by the caller.
	 * @return array<string,mixed>|null
	 */
	public static function find_by_plaintext( string $plaintext ): ?array {
		if ( strlen( $plaintext ) < self::STORED_PREFIX_LEN ) {
			return null;
		}
		$prefix = substr( $plaintext, 0, self::STORED_PREFIX_LEN );

		foreach ( self::all() as $entry ) {
			if ( ! isset( $entry['prefix'], $entry['hash'] ) ) {
				continue;
			}
			$stored_prefix = $entry['prefix'];
			$stored_hash   = $entry['hash'];
			if ( ! is_string( $stored_prefix ) || ! is_string( $stored_hash ) ) {
				continue;
			}
			// Invariant #3: constant-time prefix comparison.
			if ( ! hash_equals( $stored_prefix, $prefix ) ) {
				continue;
			}
			// Constant-time hash verification.
			if ( ! password_verify( $plaintext, $stored_hash ) ) {
				continue;
			}
			if ( null !== ( $entry['revoked_at'] ?? null ) ) {
				return null;
			}
			return $entry;
		}

		return null;
	}

	/**
	 * Revoke an entry by id. Permanent — there is no unrevoke.
	 *
	 * @param string $id Stable entry id (e.g. 'k_<uuid>').
	 * @return bool True if a revocation was applied, false if id not found or already revoked.
	 */
	public static function revoke( string $id ): bool {
		$entries = self::all();
		$changed = false;
		foreach ( $entries as $i => $entry ) {
			if ( ! is_array( $entry ) || ( $entry['id'] ?? '' ) !== $id ) {
				continue;
			}
			if ( null !== ( $entry['revoked_at'] ?? null ) ) {
				return false;
			}
			$entries[ $i ]['revoked_at'] = time();
			$changed                     = true;
			break;
		}

		if ( ! $changed ) {
			return false;
		}

		return self::persist( $entries );
	}

	/**
	 * Update `last_used_at` on the matching entry, rate-limited to once per 5
	 * minutes per id via a transient guard. Prevents write amplification when a
	 * key is used frequently.
	 *
	 * @param string $id Stable entry id.
	 * @return void
	 */
	public static function touch_last_used( string $id ): void {
		$guard = 'swh_apikey_touch_' . $id;
		if ( false !== get_transient( $guard ) ) {
			return;
		}

		$entries = self::all();
		$found   = false;
		foreach ( $entries as $i => $entry ) {
			if ( ! is_array( $entry ) || ( $entry['id'] ?? '' ) !== $id ) {
				continue;
			}
			$entries[ $i ]['last_used_at'] = time();
			$found                         = true;
			break;
		}

		if ( ! $found ) {
			return;
		}

		set_transient( $guard, 1, self::TOUCH_TTL );
		self::persist( $entries );
	}

	/**
	 * Return every entry, including revoked ones. UI may want to show revoked
	 * keys greyed-out; auth paths must check `revoked_at` themselves.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		$bag = get_option( self::OPTION, array() );
		if ( ! is_array( $bag ) ) {
			return array();
		}
		$entries = $bag[ self::SUBKEY ] ?? array();
		if ( ! is_array( $entries ) ) {
			return array();
		}
		// Re-index numerically and drop any non-array rows so the documented
		// return shape holds. Callers iterate as a list.
		$out = array();
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Write the entries array back into the integrations bag.
	 *
	 * Returns true on successful write OR when the option value is already
	 * identical to what we'd write (update_option returns false in that case
	 * but it's not an error). Returns false on real write failure so callers
	 * can surface storage errors instead of silently dropping a revocation /
	 * key creation.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 */
	private static function persist( array $entries ): bool {
		$bag = get_option( self::OPTION, array() );
		if ( ! is_array( $bag ) ) {
			$bag = array();
		}
		$bag[ self::SUBKEY ] = array_values( $entries );
		if ( update_option( self::OPTION, $bag ) ) {
			return true;
		}
		// update_option returns false either on failure OR when value is
		// unchanged. Re-read to confirm the persisted state matches what we
		// intended; only treat a true mismatch as a hard failure.
		$verify = get_option( self::OPTION, array() );
		return is_array( $verify ) && isset( $verify[ self::SUBKEY ] ) && $verify[ self::SUBKEY ] === $bag[ self::SUBKEY ];
	}
}
