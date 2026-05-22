<?php
declare(strict_types=1);

namespace SWH\Concurrency;

use function get_post_meta;
use function update_post_meta;

final class TicketVersion {

	public const META_KEY = '_swh_version';

	public static function current( int $ticket_id ): int {
		$raw = get_post_meta( $ticket_id, self::META_KEY, true );
		if ( '' === $raw || null === $raw ) {
			return 1;
		}
		return max( 1, (int) $raw );
	}

	public static function bump( int $ticket_id ): int {
		$next = self::current( $ticket_id ) + 1;
		update_post_meta( $ticket_id, self::META_KEY, $next );
		return $next;
	}

	public static function check( int $ticket_id, int $expected ): bool {
		return self::current( $ticket_id ) === $expected;
	}
}
