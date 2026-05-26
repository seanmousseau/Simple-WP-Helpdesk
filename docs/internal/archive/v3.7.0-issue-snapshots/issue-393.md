# Issue #393: Deprecation helper — wrap apply_filters_deprecated() and do_action_deprecated()

**Labels:** dx
**Milestone:** v3.7.0 — v4 Foundations

## Body

v4.0's [#360](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/360) deprecates pre-3.0 filter hooks. WP core has \`apply_filters_deprecated()\` and \`do_action_deprecated()\` since 4.6, but the plugin doesn't use them. Add thin wrappers with consistent message format so v4.0's deprecations are mechanical.

### Plan

Add \`includes/deprecations.php\` with:

\`\`\`php
function swh_apply_deprecated_filter( \$hook, \$args, \$version, \$replacement = null, \$message = '' ) {
    \$msg = \$message ?: sprintf( 'Use %s instead.', \$replacement ?: 'the documented replacement' );
    return apply_filters_deprecated( \$hook, \$args, \"SWH \$version\", \$replacement, \$msg );
}

function swh_do_deprecated_action( \$hook, \$args, \$version, \$replacement = null, \$message = '' ) { ... }
\`\`\`

### Acceptance

- [ ] Helpers added, PHPStan typed
- [ ] Document the deprecation policy in \`docs/developer/deprecations.md\`: minimum 2-minor-release window before removal, \`SWH x.y\` version-tag format
- [ ] PHPUnit: helper fires the deprecation notice and returns the filtered value
- [ ] No existing hooks deprecated yet (that's v4.0's job — this just lands the tool)

### Dependencies

Unblocks v4.0 [#360](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/360).
