# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

_Nothing is released yet — the package has no published versions or tags.
The entries below describe the current prototype state._

### Added

- Repository scaffold and tooling: Composer contract for `wordpress/abilities`
  (type `library`, zero runtime dependencies, PSR-4 `WordPress\Abilities\` →
  `src/`), WPCS and PHPStan lint configs (`composer run lint:phpcs`,
  `composer run lint:phpstan`), real manifest rule enforcement —
  `bin/verify-manifest.php` (`composer run verify:manifest`), a CLI mirror
  of `ManifestTest` applying the §8.3 rules (tier/prefix rules, name
  uniqueness, rename-lineage collisions, class existence and inheritance,
  category single-sourcing, version sync) — the coreify round-trip gate as
  `composer run verify:coreify`, and a CI workflow wired for phpcs,
  phpstan, the manifest rule gate, the coreify round-trip check, and the
  dual wp-env PHPUnit matrix.
- Engine components under `src/`:
  - `Manifest` — the static inventory of shipped abilities (name, class,
    category, tier, version floors, required core features, rename lineage,
    proposal paper trail). What exists; when it's on is consumer policy.
  - `Registrar` — opt-in, idempotent, timing-aware registration through the
    Abilities API (`register_all()` / `register()`), with version gating,
    skipping of abilities core already provides (the skip precedes consumer
    selection, so a `filter` or `public_only` can never veto a core-provided
    name), consumer-side selection args, and provenance meta stamping
    (`source`, `package_version`, `stability`). The package never registers itself.
  - `Abstract_Core_Ability` — the core-landing-ready base class all
    abilities extend (self-contained, closures, core coding style, text
    domain `default`).
- Ability category ownership and opt-out: the Registrar derives the ability
  categories the manifest declares (the `category` field of every entry) and
  registers them itself, hooked at priority 20 on
  `wp_abilities_api_categories_init` from both `register_all()` and
  `register()`, so a pre-7.2 WordPress (whose only ability categories are
  `site` and `user`) accepts the package's registrations without the
  consumer backfilling categories. The definitions are single-sourced in
  `WordPress\Abilities\Categories` (shipped by composer autoload), replacing
  the test-fixture constant previously used as the only definition source.
  The declaration is a guarded no-op (`wp_has_ability_category()`) wherever
  the host already provides the category — a 7.2+ core, a trunk-snapshot
  consumer API, or a consumer registering its own — and consumers can
  decline the package's declarations entirely with the
  `wordpress_abilities_declare_categories` filter (return false to own every
  category registration yourself).
- Loud missing-category rejection: when `wp_register_ability()` returns null
  for an ability whose manifest-declared category is not registered — e.g. after
  opting out of the package's declarations, or when
  `wp_abilities_api_categories_init` has already fired without declaring the
  category — the Registrar now fires `_doing_it_wrong()` naming the missing
  category, instead of returning silently. All other Gate-8 rejections stay
  silent (core logs its own reason).
- Initial abilities:
  - `core/content-query` — tier `core`: backport of the readonly content
    query ability as landed in WordPress core 7.2 (`core_since: 7.2`). Served
    by the package under the same name on older WordPress, skipped by the
    Registrar wherever core provides it; satisfies the `show_in_abilities`
    support feature via a polyfill where core lacks it.
- Write content abilities `core/content-create`, `core/content-update`,
  and `core/content-delete` — tier `stable` (core-destined, proposal
  filed): faithful ports of the create,
  update, and trash-or-delete content abilities from
  [WordPress/ai PR #1025](https://github.com/WordPress/ai/pull/1025) by
  @jorgefilipecosta, on this package's dotted field paths
  (`title.raw`, `content.raw`, `excerpt.raw`, `author.slug`, `date.local`,
  `date.gmt`); the shared write logic lives in
  `WordPress\Abilities\Content\Abstract_Content_Write`.
- Test infrastructure: PHPUnit suites (`ManifestTest`, `RegistrarTest`,
  per-ability tests with permission negative/positive coverage on every gate
  path), and a dual wp-env matrix — WordPress trunk and latest stable —
  running against the core-provided Abilities API on both (WordPress 6.9+
  ships it in core; a hard bootstrap guard enforces that premise).
- `wp/media-query` — tier `experimental` (incubating under the `wp/` prefix:
  no name or schema promises until it files a core proposal): faithful port
  of the read-only media query ability from
  [WordPress/ai PR #1098](https://github.com/WordPress/ai/pull/1098) by
  @jorgefilipecosta. Reads media library items: a single item by ID, or a
  paginated collection (`media`/`total`/`total_pages`) filtered by search
  (titles, captions, descriptions, and file names), media type, MIME type,
  parent, author, include/exclude, and status; `fields` selects the returned
  subset (always with the ID) with a lean default set, and raw fields require
  edit access. Keeps the PR's documented attachments-endpoint quirks: totals
  count items withheld from the caller; `exclude` is ignored when `include`
  is given; MIME types the site does not allow are ignored; a queryable but
  forbidden status reports `media_invalid_param`; a missing item is denied
  like an unreadable one; and the generated caption of a password-protected
  item stays gated while its rendered description is not. Ships with the
  package-authored `media` ability category (core trunk has no media category
  to copy).
- `wp/media-upload` / `wp/media-update` / `wp/media-delete` — tier
  `experimental` (incubating under the `wp/` prefix, no name or schema
  promises until they file a core proposal): faithful ports of the media
  write abilities from [WordPress/ai PR #1104](https://github.com/WordPress/ai/pull/1104)
  by @jorgefilipecosta, building on the `wp/media-query` field projection —
  written attachments are answered through the same contract, in the edit
  context. Upload takes a file as base64 `data` with a `filename` (plus
  title, caption, description, alt text, parent post, author, status, slug,
  and date) or the `url` of a publicly reachable image (parent post only,
  `open_world: true`, download capped at the site's upload size); update
  takes an `id` and the same fields (the status is validated against the
  attachment, so `inherit` can be kept); delete trashes an `id` — an error
  unless the site enables media trash (`MEDIA_TRASH`) — or deletes it and
  its files permanently with `force`, returning the item under `previous`.
  Every write re-checks its capability inside the execute callback so the
  error can name the refused field, and the delete capability is re-checked
  right before removal. Ships with the package-authored `media` ability
  category (core trunk has no media category to copy).
### Removed

- `wp/post-create` — the self-authored experimental write-ability prototype
  was dropped before any publish: the initial manifest carries only faithful
  ports of existing abilities. Went away together with review finding S2
  (an unchecked null `post_status` reaching `wp_insert_post`), which is
  removed with the file rather than fixed.
- The Abilities API fixture shim (the `tests/Fixtures/abilities-api/`
  shim classes and the mu-plugin loader), resolving KNOWN-ISSUES §3:
  the Abilities API ships in WordPress core since 6.9, so the tests assume
  the core-provided API — enforced by a hard bootstrap guard — instead of
  provisioning one. The tracked trunk snapshots under
  `tests/Fixtures/abilities-api/abilities/` remain as the coreify
  round-trip reference.
