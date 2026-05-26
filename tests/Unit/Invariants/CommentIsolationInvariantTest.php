<?php
/**
 * Invariant: helpdesk ticket comments are segregated from the host WordPress
 * comment stream. The mechanism is two filters in includes/class-ticket.php:
 *
 *   1. `comments_clauses` -> `swh_exclude_helpdesk_comments` — strips helpdesk
 *      ticket comments from general queries, but allows them when (a) the
 *      query targets a specific helpdesk_ticket post (admin editor, cron, etc.)
 *      or (b) the caller explicitly requests `type=helpdesk_reply` (client portal).
 *
 *      **`--post_id` vs `--post__in` gotcha (#443):** the short-circuit only
 *      checks `query_vars['post_id']` (singular). Queries that target a ticket
 *      via `post__in` (array) bypass the short-circuit and return empty. Use
 *      the singular form for tooling. See `docs/internal/data-dictionary.md`.
 *
 *   2. `comment_feed_where` -> `swh_exclude_helpdesk_from_feed` — strips helpdesk
 *      ticket comments from the site's comment RSS feed unconditionally.
 *
 * Removing or weakening either filter leaks internal notes and reply bodies
 * onto the public site. Pin them.
 *
 * @see docs/internal/design-document.md — invariant table, comment isolation
 * @see docs/internal/data-dictionary.md — comment_type='helpdesk_reply', _is_internal_note
 * @package Simple_WP_Helpdesk
 */

namespace SWH\Tests\Unit\Invariants;

class CommentIsolationInvariantTest extends SourceScanTestCase {

	/**
	 * @var string Cached source of includes/class-ticket.php.
	 */
	private string $source = '';

	protected function setUp(): void {
		parent::setUp();
		$this->source = $this->read_plugin_file( 'includes/class-ticket.php' );
	}

	public function test_comments_clauses_filter_registered(): void {
		$this->assertMatchesRegularExpression(
			"/add_filter\s*\(\s*['\"]comments_clauses['\"]\s*,\s*['\"]swh_exclude_helpdesk_comments['\"]/",
			$this->source,
			'comments_clauses filter must be registered against swh_exclude_helpdesk_comments. Without it, helpdesk replies '
				. 'appear in WP comment widgets, admin Comments screen, and the public-facing site.'
		);
	}

	public function test_comment_feed_where_filter_registered(): void {
		$this->assertMatchesRegularExpression(
			"/add_filter\s*\(\s*['\"]comment_feed_where['\"]\s*,\s*['\"]swh_exclude_helpdesk_from_feed['\"]/",
			$this->source,
			'comment_feed_where filter must be registered. Without it, ticket replies and internal notes are syndicated to /comments/feed/.'
		);
	}

	public function test_exclusion_allows_targeted_post_queries(): void {
		// The filter must short-circuit when querying a specific helpdesk_ticket post.
		// Pattern: get_post_type( $post_id ) compared to 'helpdesk_ticket' (either order).
		$forward = '/get_post_type\s*\(\s*\$post_id\s*\)\s*[\s\S]{0,40}helpdesk_ticket/';
		$reverse = "/['\"]helpdesk_ticket['\"]\s*===\s*get_post_type\s*\(\s*\\\$post_id\s*\)/";
		$ok      = preg_match( $forward, $this->source ) || preg_match( $reverse, $this->source );
		$this->assertTrue(
			(bool) $ok,
			'swh_exclude_helpdesk_comments must short-circuit when query targets a helpdesk_ticket post_id, otherwise '
				. 'the admin editor cannot list ticket replies.'
		);
	}

	public function test_exclusion_allows_explicit_helpdesk_reply_type(): void {
		// The filter must skip exclusion when caller requested type=helpdesk_reply.
		$this->assertMatchesRegularExpression(
			"/['\"]helpdesk_reply['\"]\s*===\s*\\\$requested_type/",
			$this->source,
			'swh_exclude_helpdesk_comments must short-circuit when type=helpdesk_reply was explicitly requested, otherwise '
				. 'the portal cannot list its own replies.'
		);
	}

	public function test_exclusion_uses_post_type_lookup_not_only_comment_type(): void {
		// Critical: exclusion uses NOT IN (SELECT ID FROM ... WHERE post_type = 'helpdesk_ticket')
		// because legacy comments may not have comment_type migrated. Filtering only on
		// comment_type would leak old replies created before v3.6.
		// Match the full subquery: `NOT IN ( SELECT ID FROM <table> WHERE post_type = 'helpdesk_ticket' )`.
		// Whitespace and table alias (`{$wpdb->posts}` vs literal) are tolerated; the post_type
		// literal is the load-bearing part — without it, the exclusion would scope to all posts.
		$this->assertMatchesRegularExpression(
			"/comment_post_ID\s+NOT\s+IN\s*\(\s*SELECT\s+ID\s+FROM\s+\S+\s+WHERE\s+[^)]*post_type\s*=\s*%s[^)]*\)/i",
			$this->source,
			'swh_exclude_helpdesk_comments must filter by post_type (subquery on wp_posts with WHERE post_type = %s), '
				. 'not just by comment_type. The post_type clause is the load-bearing part — without it the subquery '
				. 'would return EVERY post ID and the exclusion would silently scope to all comments site-wide. '
				. 'Tickets created before the comment_type_v2 migration may still have comment_type=\'\' '
				. 'and would leak through a comment_type-only filter.'
		);
		// Cross-check: the helpdesk_ticket literal must be passed to $wpdb->prepare() so it
		// actually substitutes into the %s placeholder above. (Catches the case where the
		// SQL has %s but no value is bound.)
		$this->assertMatchesRegularExpression(
			"/\\\$wpdb->prepare\s*\([\s\S]*?['\"]helpdesk_ticket['\"]/",
			$this->source,
			'swh_exclude_helpdesk_comments must bind the literal \'helpdesk_ticket\' into the prepared query.'
		);
	}
}
