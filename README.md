# WordPress Abilities

Canonical WordPress Abilities, consumable by plugins via the Abilities API.

`wordpress/abilities` is where the WordPress Core AI team develops **canonical
core WordPress Abilities** — functional primitives for entities from core
WordPress software (posts, users, settings, comments, terms, media; read
**and** write) — defined through the WP Abilities API (`wp_register_ability()`,
the abilities registry, `wp_abilities_api_init`) and consumed by plugins such
as the AI experiments plugin and the MCP adapter.

**This is a Composer library, not a plugin.** It ships classes and
registration data. It has no admin UI, no activation step, and never
bootstraps itself.

- **One canonical implementation per ability.** Consumer plugins never fork
  an ability's schema — see the review rules in [CONTRIBUTING.md](CONTRIBUTING.md).
- **Zero runtime dependencies.** The only requirement is `php >= 7.4`. The
  package operates entirely within the bounds of the WP Abilities API and
  **never ships the API itself**.
- **PSR-4 only** (`WordPress\Abilities\` → `src/`), no side effects at
  autoload time.
- License: [GPL-2.0-or-later](LICENSE).

## What's in the package

| Component | Role |
|---|---|
| `Manifest` | Static inventory of the abilities that exist — name, class, category, tier, version floors, requirements, lineage. *What exists* is data. *When it's on* is consumer policy. |
| `Registrar` | Opt-in registration through the Abilities API: `Registrar::register_all()` or `Registrar::register( $name )`. Idempotent, timing-aware (`wp_abilities_api_init` or later), version-gated, and provenance-stamping (`meta['source']`, `meta['package_version']`, `meta['stability']`). |
| `Abstract_Core_Ability` | Base class every ability extends. Abilities are written core-landing-ready: self-contained, closures for callbacks, core coding style, text domain `default`, no plugin helpers, no AI plumbing. |

The Registrar skips any ability that core already provides (`wp_has_ability()`),
so the same package copy is safe on older and newer WordPress alike. It also
accepts consumer-side selection args (`public_only`, a `filter` callable for
`register_all()`). Registration is never automatic: nothing registers until a
consumer calls the Registrar.

## Consuming this package

*(Policy, not mechanism: how the package is autoloaded is your business — the
package has no opinion.)*

1. **Load it however your plugin loads Composer code** — a plain Composer
   autoloader, a shared autoloader, or a guarded `require`. If multiple
   active plugins may bundle this package, **ensure only one copy loads** —
   a `class_exists( 'WordPress\Abilities\Registrar' )` guard or a shared
   autoloader; your choice. The package takes no position on which
   mechanism you use.
2. **Register on your own gating and timing** — call
   `Registrar::register_all()` or `Registrar::register( 'core/content-query' )`
   as early as your plugin has the API, typically at plugin load; the
   Registrar is timing-aware and defers the registrations to
   `wp_abilities_api_init` itself. One timing constraint: the Registrar
   declares the manifest categories during `wp_abilities_api_categories_init`,
   which fires *before* `wp_abilities_api_init` — so on hosts that lack
   those categories (WordPress < 7.2), the first Registrar call must
   precede the registry boot, i.e. before anything calls an abilities API
   function. What exists is the manifest; **when it's on is your policy**.
3. **Ship `vendor/` in your distribution** — don't require end-users to run
   Composer.

The **Abilities API must be present at registration time**: it ships in
WordPress core since 6.9 (the package's support floor), so any supported
WordPress provides it in core. If `wp_register_ability()` is missing when you
call the Registrar, it fires a `_doing_it_wrong()` notice and registers
nothing. Version skew is tolerated: registration is per-ability and
idempotent, so a differently-versioned copy of the package in the process
simply registers nothing new.

### Tiers at a glance

Full rules, the promotion path, and the rename protocol are in
[NAME-LIFECYCLE.md](NAME-LIFECYCLE.md).

| Prefix | Tier | Promises |
|---|---|---|
| `wp/` | experimental | **None.** Name AND schema may change or vanish in any release. |
| `core/` | stable (core-destined) | Semver applies; schema frozen at core-proposal filing. |
| `wordpress/` | stable (package-owned) | Semver applies. |
| *(tier flips to)* — | core | Package copy is a mechanically generated backport; never diverges from core's. |

### Current abilities

| Name | Tier | Category | Notes |
|---|---|---|---|
| `core/content-query` | core | content | Backport of the readonly content query ability landed in WordPress core 7.2 (`core_since: 7.2`). On 7.2+ core provides it and the Registrar skips it; on 6.9–7.1 the package serves it under the same name. Requires the `show_in_abilities` support feature (polyfilled where core lacks it). |
| `core/settings-get` | core | site | Backport of the readonly settings get ability landed in WordPress core 7.2 (`core_since: 7.2`). On 7.2+ core provides it and the Registrar skips it; on 6.9–7.1 the package serves it under the same name. Requires the `setting_show_in_abilities` support feature (polyfilled where core lacks it: core's own settings are flagged and registered before the ability reads them, as on 7.2). |
| `core/content-create` | stable | content | Port of the content create ability from [WordPress/ai PR #1025](https://github.com/WordPress/ai/pull/1025) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Creates posts of types exposed to abilities under the field names `core/content-query` returns. |
| `core/content-update` | stable | content | Port of the content update ability from [WordPress/ai PR #1025](https://github.com/WordPress/ai/pull/1025) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Updates a post by ID; fields left out keep their current values. |
| `core/content-delete` | stable | content | Port of the content delete ability from [WordPress/ai PR #1025](https://github.com/WordPress/ai/pull/1025) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Trashes a post by ID, or deletes it permanently with `force`. |

## Roadmap (not yet)

Planned, **not** available in the current package: the MCP adapter consuming
this package via `Registrar::register_all()`; further write abilities
beyond the content trio; the `core/users-query` backport; the WordPress AI
plugin migration; Packagist publishing.

## Development

```bash
composer install
composer run lint:phpcs      # WordPress core coding standards, text domain 'default'
composer run lint:phpstan    # PHPStan + phpstan-wordpress
composer test                # PHPUnit (wp-env based: trunk + latest-stable environments)
composer run verify:manifest # tier/prefix and manifest consistency rules
```

See [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request.

## License

[GPL-2.0-or-later](LICENSE)