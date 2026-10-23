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
