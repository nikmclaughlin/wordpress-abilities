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
| `core/settings-get` | core | site | Backport of the readonly settings get ability landed in WordPress core 7.2 (`core_since: 7.2`). On 7.2+ core provides it and the Registrar skips it; on 6.9–7.1 the package serves it under the same name. Requires the `setting_show_in_abilities` support feature (polyfilled where core lacks it: core's own settings are flagged and registered before the ability reads them, as on 7.2). |
| `core/users-query` | core | user | Backport of the readonly users query ability landed in WordPress core 7.2 (`core_since: 7.2`). On 7.2+ core provides it and the Registrar skips it; on 6.9–7.1 the package serves it under the same name. Needs no support feature beyond the Abilities API. |
| `core/content-create` | stable | content | Port of the content create ability from [WordPress/ai PR #1025](https://github.com/WordPress/ai/pull/1025) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Creates posts of types exposed to abilities under the field names `core/content-query` returns. |
| `core/content-update` | stable | content | Port of the content update ability from [WordPress/ai PR #1025](https://github.com/WordPress/ai/pull/1025) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Updates a post by ID; fields left out keep their current values. |
| `core/content-delete` | stable | content | Port of the content delete ability from [WordPress/ai PR #1025](https://github.com/WordPress/ai/pull/1025) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Trashes a post by ID, or deletes it permanently with `force`. |
| `wp/media-query` | experimental | media | Port of the read-only media query ability from [WordPress/ai PR #1098](https://github.com/WordPress/ai/pull/1098) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Reads media library items — a single item by ID, or a paginated filtered collection (`media`/`total`/`total_pages`) with totals; `fields` picks a subset, and raw fields require edit access. Uses the package-authored `media` category (core has no equivalent to copy). |
| `wp/media-upload` | experimental | media | Port of the media upload ability from [WordPress/ai PR #1104](https://github.com/WordPress/ai/pull/1104) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Uploads a file from base64 `data` + `filename` (with attachment fields), or downloads an image from a `url` (parent post only). Returns the new item in the edit context; `fields` picks a subset. Requires `upload_files`; the parent and author are named-field checks. A URL upload downloads from another site (`open_world: true`), capped at the site's upload size. Uses the package-authored `media` category. |
| `wp/media-update` | experimental | media | Port of the media update ability from [WordPress/ai PR #1104](https://github.com/WordPress/ai/pull/1104) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Updates a media item by ID with the same attachment fields (the status is validated against the attachment, so `inherit` can be kept); fields left out keep their values. Returns the updated item in the edit context. Requires edit access to the item (`edit_post` for attachments, via the same projection `wp/media-query` reads with). |
| `wp/media-delete` | experimental | media | Port of the media delete ability from [WordPress/ai PR #1104](https://github.com/WordPress/ai/pull/1104) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Trashes a media item by ID (an error unless the site enables media trash), or deletes it and its files permanently with `force`, returning the item under `previous`. Requires `delete_post` for attachments; the capability is re-checked just before removal. |
| `wp/settings-update` | experimental | site | Port of the settings update ability from [WordPress/ai PR #764](https://github.com/WordPress/ai/pull/764) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Updates only the settings `core/settings-get` reads — every setting except `url` and `email` (read-only, and unregistered by the ability when all of them are read-only) — via a map of setting name to new value; `null` resets a setting with a registered default to it (a setting without one refuses `null`); every check (sanitize, privacy policy, stored value) runs before any write, so an error leaves every setting unchanged; the answer holds only the updated settings. Requires `manage_options`; the privacy policy page additionally requires `manage_privacy_options`. |
| `wp/terms-query` | experimental | content | Port of the read-only terms query ability from [WordPress/ai PR #1099](https://github.com/WordPress/ai/pull/1099) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Reads terms of the taxonomies exposed to abilities via `show_in_abilities` (polyfilled: no currently supported core declares the taxonomy flag yet) — a single term by ID (optionally guarded by `taxonomy`) or by `taxonomy` + `slug`, or a paginated filtered collection (`terms`/`total`/`total_pages`) covering search, include, exclude, hierarchical `parent`, `post`, and `hide_empty`; `fields` picks a subset (the ID always included, `parent` for hierarchical taxonomies only). Requires an authenticated user; a missing or hidden term is denied like an unreadable one. Served through the GET run route, like the other read abilities. |
| `wp/term-create` | experimental | content | Port of the term create ability from [WordPress/ai PR #1103](https://github.com/WordPress/ai/pull/1103) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Creates a term in a taxonomy exposed to abilities via `show_in_abilities` (the same taxonomies `wp/terms-query` reads — taxonomy opt-in governs the writes too): takes a `taxonomy` and a `name`, plus optional `slug`, `description`, and `parent` (hierarchical taxonomies only). Returns the created term in the `wp/terms-query` shape; `fields` picks the subset. `edit_terms` is the floor in a hierarchical taxonomy, `assign_terms` in a flat one, so a contributor can create tags without touching categories. |
| `wp/term-update` | experimental | content | Port of the term update ability from [WordPress/ai PR #1103](https://github.com/WordPress/ai/pull/1103) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Updates a term by `id` with the same fields, which keep their values when left out; `taxonomy` is an optional guard, and a term outside it is denied like a missing one. Returns the updated term in the `wp/terms-query` shape; `fields` picks the subset. Requires `edit_term` for the term. |
| `wp/term-delete` | experimental | content | Port of the term delete ability from [WordPress/ai PR #1103](https://github.com/WordPress/ai/pull/1103) (`core_since: null` — flips to tier `core` when it lands in WordPress core). Deletes a term by `id`; terms cannot be trashed, so **`force` must be true** — a non-forced call answers `terms_trash_not_supported` (501). Returns `{ deleted, previous }`, the deleted term in the `wp/terms-query` shape; `fields` picks the subset. Requires `delete_term` for the term; the default category is not deleted (core's capability mapping denies it, and `wp_delete_term()` refuses it), and the `taxonomy` input is an optional guard like on update. |

## Roadmap (not yet)

Planned, **not** available in the current package: the MCP adapter consuming
this package via `Registrar::register_all()`; further write abilities beyond
the content trio; the WordPress AI plugin migration; Packagist publishing.

Settings writes are no longer roadmanted either: `wp/settings-update` ships
(extended from the `core/settings-get` backport's shared exposed-settings
snapshot). Media read is no longer roadmanted — `wp/media-query` ships in this
package (the first experimental-tier `wp/` ability). Media writes are no longer
roadmanted either: `wp/media-upload`, `wp/media-update`, and `wp/media-delete`
ship on top of the same `media` category and field projection. Terms read is no
longer roadmanted either: `wp/terms-query` ships (the taxonomy opt-in flag is
the `taxonomy_show_in_abilities` support feature, polyfilled). Term writes are
no longer roadmanted either: `wp/term-create`, `wp/term-update`, and
`wp/term-delete` ship on top of the same exposed-taxonomy opt-in and field
projection. Further media, terms, or write abilities incubate under `wp/` the
same way before ever carrying a `core/` proposal.

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