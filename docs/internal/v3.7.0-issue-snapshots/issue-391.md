# Issue #391: Introduce swh_get_option() helper as read-through wrapper (de-risks v4.0 schema split)

**Labels:** enhancement, dx
**Milestone:** v3.7.0 — v4 Foundations

## Body

v4.0's [#356](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/356) splits monolithic \`swh_options\` into 5 sub-options. This touches every admin page and every read site in the plugin — the riskiest single change in v4.0.

Land the helper signature now (still backed by the monolithic option) so v4.0's migration only changes the implementation, not the call sites.

### Plan

Add \`swh_get_option( string \$group, string \$key, mixed \$default = null ): mixed\` to \`includes/helpers.php\`. v3.7 implementation:

\`\`\`php
function swh_get_option( \$group, \$key, \$default = null ) {
    \$opts = get_option( 'swh_options', array() );
    return isset( \$opts[ \$key ] ) ? \$opts[ \$key ] : \$default;
}
\`\`\`

The \`\$group\` arg is currently ignored but baked into the signature. v4.0 changes the body to read from the correct sub-option per group; call sites stay identical.

### Acceptance

- [ ] Helper added with full signature and PHPStan typing
- [ ] All \`get_option('swh_options')[...]\` reads in \`admin/\`, \`includes/\`, \`frontend/\` migrated to \`swh_get_option()\` (grep-verified)
- [ ] PHPUnit: default fallback, missing key, missing option entirely
- [ ] PHPStan level 9 clean
- [ ] Playwright: full settings round-trip across all 8 tabs still passes
- [ ] CHANGELOG: \"Added \`swh_get_option()\` helper (preparing for v4.0 schema split).\"

### Dependencies

Unblocks risk on v4.0 [#356](https://github.com/seanmousseau/Simple-WP-Helpdesk/issues/356) (settings schema migration).
