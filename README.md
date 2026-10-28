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

   - **Optional (WordPress-plugin bundling): multi-copy arbitration.**
     In the WordPress-plugin world, several active plugins may each bundle
     the package in their own `vendor/`. Plain autoloading then decides
     "who defines the classes" by load order, and an older winning copy can
     fatal a current consumer (`class not found` for an ability class the
     consumer's own copy contains). For exactly this case the package ships
     an **optional arbitration loader**, following the pattern proven by
     [Action Scheduler](https://actionscheduler.org/usage/) ("Action
     Scheduler will register the version in that file and then load the
     most recent version of itself on the site"), adapted so the newest
     copy always loads. The loader lives at the package root as
     `wordpress-abilities-loader.php`, ships in the dist, and is **never
     registered in composer autoload**: it is opt-in. A plugin that bundles
     the package requires the loader of ITS OWN copy at plugin file scope —
     before any use of the package's classes — and registers that copy:

     ```php
     // Plugin file scope, before anything touches the package's classes.
     require_once __DIR__ . '/vendor/wordpress/abilities/wordpress-abilities-loader.php';
     wordpress_abilities_register( '0.1.0', __DIR__ . '/vendor/wordpress/abilities' );
     ```

     - **Newest wins.** Every bundled copy registers the same way; a single
       autoloader then serves all `WordPress\Abilities\` classes from the
       copy with the highest declared version (`version_compare` semantics;
       the first registration of an equal version wins the tie), regardless
       of which plugin included its loader first.
     - **Arbitration is lazy.** The winner is decided at the first class
       resolution, not at registration time — a library cannot assume
       WordPress hooks exist when its file is included — so every bundled
       copy in the process can register first even when the copies'
       includes arrive ahead of some plugins. Once a class has actually
       loaded, PHP has defined it: later registrations are ignored, with a
       diagnostic notice (WordPress `_doing_it_wrong()` where available,
       otherwise a plain `trigger_error()` notice).
     - **The functions are a frozen public contract.**
       `wordpress_abilities_register()` and
       `wordpress_abilities_loaded_version()` (which returns the winning
       version once resolved, `null` before — handy diagnostics) must
       behave identically across all package versions, because whichever
       copy's loader file is required first defines them for the whole
       process. Action Scheduler's versioned
       `action_scheduler_register_3_x()` functions are the same precedent.
     - **Composer-native consumers are unaffected.** Without an explicit
       `require` of the loader, nothing about the above applies; item 1
       here remains the entire contract.

     *(Spike draft from branch `spike/loader-arbitration`, pending review —
     the loader contract is proven by
     `tools/loader-spike/test-loader.php` on that branch.)*
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
| `core/content-create` | stable | content | Port of the content create ability from [WordPress/ai PR #1025](https://github.com/WordPress/ai/pull/1025) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Creates posts of types exposed to abilities under the field names `core/content-query` returns. |
| `core/content-update` | stable | content | Port of the content update ability from [WordPress/ai PR #1025](https://github.com/WordPress/ai/pull/1025) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Updates a post by ID; fields left out keep their current values. |
| `core/content-delete` | stable | content | Port of the content delete ability from [WordPress/ai PR #1025](https://github.com/WordPress/ai/pull/1025) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Trashes a post by ID, or deletes it permanently with `force`. |

## Roadmap (not yet)

Planned, **not** available in the current package: the MCP adapter consuming
this package via `Registrar::register_all()`; further write abilities
(`wp/post-update`, `wp/post-delete`); `core/users-query` and
`core/settings-get` backports; the WordPress AI plugin migration; Packagist
publishing.

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