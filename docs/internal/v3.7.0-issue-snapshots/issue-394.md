# Issue #394: PSR-4 autoload for plugin classes

**Labels:** dx
**Milestone:** v3.7.0 — v4 Foundations

## Body

Bootstrap is currently a stack of \`require_once\` calls. v4.0 will add ~10 more files (REST controllers in v4.1, settings groups, command registry, view registry). PSR-4 autoload is a small lift now and saves repeated friction across v4.x.

### Plan

- \`composer.json\`: add \`autoload.psr-4: { \"SWH\\\\\": \"src/\" }\` (or keep current paths and use classmap if rename is too risky)
- Move \`includes/class-*.php\` and \`admin/class-*.php\` files behind the autoloader gradually — start with one (e.g. \`SWH\\Email\\Mailer\`) to validate the pattern
- Keep existing \`require_once\` calls working alongside (autoloader is additive)
- \`composer dump-autoload\` runs in CI and is checked in (\`vendor/composer/autoload_*.php\` already shipped today via plugin-update-checker)

### Acceptance

- [ ] One class migrated to PSR-4 namespace (proof of concept) without breaking anything
- [ ] Existing \`require_once\` files continue to load (no regressions)
- [ ] PHPUnit + Playwright + PHPStan all pass
- [ ] \`docs/development.md\` updated with the namespace + path convention
- [ ] CHANGELOG entry under Changed: \"Added PSR-4 autoload for plugin classes (additive — existing require_once calls still work).\"

### Out of scope

- Full migration of every class — that happens organically across v4.x.
- Renaming public class symbols (e.g. \`SWH_Settings\` → \`SWH\\Admin\\Settings\`) without an alias.
