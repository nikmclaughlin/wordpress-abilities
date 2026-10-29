# KNOWN-ISSUES.md

Known deviations, deferred work, and provenance notes for the
`wordpress/abilities` package. Everything here is a conscious, documented
decision — none of it is a regression to fix silently.

This file tracks open items only: an entry is removed once fixed. The
closing commit and changelog carry the resolution; git history is the
archive of resolved entries.

## Readiness probe constructs the ability twice

For an ability that overrides `should_register()`, or under `public_only`
selection, the Registrar's Gate 7 reads a probe instance before the registry
builds its own, so an ability with registration-time state computes it
twice per registration. For `core/settings-get` this is the exposed-settings
snapshot: both snapshots are identical, but a duplicate exposed setting name
fires its `_doing_it_wrong()` notice once per snapshot (twice, where core
fires once). The registry's `ability_class` mechanism gives no way to hand
it the probed instance. Abilities that keep the default `should_register()`
are not probed outside `public_only`.

## Ported-as-is PR behaviors (documented, not fixed)

Behaviors inherited verbatim from the upstream PR; intentional for parity
until core decides otherwise:

- `has_explicit_edit_fields()` is an exact match: submitting `fields` as
  `['title']` does NOT count as an edit request.
- `db_insert_error` and `db_update_error` are asymmetric (500 vs 400).
- Drafts' slugs are uniquified against `publish`.
- Deletion formats the post before deleting it.
- `get_post_by_slug` is unreachable via the write schemas — a dead path kept
  for parity with core.
- `wp/media-query` (WordPress/ai PR #1098):
  - A status the caller may not query reports `media_invalid_param` (with a
    400 status), not the ability framework's `ability_invalid_permissions` —
    the PR wraps its internal status error in the execute callback.
  - `exclude` is ignored when `include` is given, and MIME types the site
    does not allow are ignored by the `mime_type` filter.
  - Totals count items withheld from the caller, and a page past the last one
    still errors even when every row of it would have been withheld.
  - A missing item is denied like an unreadable one (same error), so IDs
    cannot be probed.
  - The generated caption of a password-protected item stays gated even for
    editors, while its rendered description is not (verified identical on
    trunk and stable).
  - With raw fields requested, a collection is answered in the edit context:
    items the caller cannot edit are withheld from the list but still counted
    in the totals.
