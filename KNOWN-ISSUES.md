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
- `wp/settings-update` (WordPress/ai PR #764):
  - All-or-nothing by design (`settings_invalid_param` 400 →
    `settings_cannot_manage_privacy_options` 403 →
    `settings_invalid_stored_value` 500 → writes): an error leaves every
    setting unchanged, where the settings endpoint keeps the settings it
    already wrote. The PR discussion established the stricter behavior.
  - `url` and `email` are read-only (a wrong `siteurl` makes wp-admin
    unreachable; `admin_email` needs its confirmation step): the input schema
    refuses them, and they live in one `READ_ONLY_OPTIONS` list.
  - `null` resets a setting to its registered default and stores it, where the
    settings endpoint only deletes the stored value; a boolean stored as `''`
    (WordPress's false) resets via `null` where the endpoint refuses it; a
    `null` for a setting without a registered default is rejected with
    `settings_invalid_param` (the endpoint deletes the value).
  - A sanitize-then-refuse: a value sanitizing makes schema-invalid is refused
    with `settings_invalid_param` where the endpoint stores it (e.g. an
    `email`-format value `%ab@x.co` stored as `@x.co`).
  - A privacy policy page change from a user who cannot manage privacy
    options is refused with `settings_cannot_manage_privacy_options`; the
    settings endpoint skips the setting without an error.
  - `settings-update` is not registered when every exposed setting is
    read-only (multisite), where the endpoint always exists.
- `wp/terms-query` (WordPress/ai PR #1099):
  - A missing, hidden, or taxonomy-mismatched term is denied like an unreadable
    one — a generic permission denial (no 404 vs 403 distinction), so terms
    cannot be probed through the ability. A direct (unvalidated) call reports
    it as `terms_term_invalid` instead.
  - The `post` filter's failures are specific only through the execute
    callback (`terms_post_invalid_id` 400 / `terms_forbidden_context` 403);
    the permission callback stays generic and lets them through for a
    logged-in user whose taxonomy access passed, as the PR documents (the
    Abilities API replaces permission errors with a generic one).
  - The REST term search covers names and slugs, not descriptions; the
    `description` field is the stored HTML, and `link` is the term archive
    permalink — taken from `get_term_link()`, whose possible WP_Error is
    assigned unchecked (as in the source PR), so a broken site link filter
    would surface it in the `link` field.
  - With `hide_empty`, empty parents of non-empty children appear in `terms`
    but not in `total` (the term query's count leaves them out), as the PR's
    output schema documents.
  - The taxonomy `show_in_abilities` flag is absent from every currently
    supported core (verified on 7.2-beta3 and 7.1: `WP_Taxonomy` does not
    declare it, and core does not flag `category`/`post_tag`), so the
    `taxonomy_show_in_abilities` polyfill marks them on both environment
    branches of the matrix; a custom taxonomy's non-bool opt-in value (e.g.
    `'yes'`) is not normalized to a bool, which the loose object-list match
    and the ability's truthiness check read exactly like 7.2's bool cast.
- `wp/media-upload` / `wp/media-update` / `wp/media-delete` (WordPress/ai PR #1104):
  - Trashing an attachment requires the site to enable the media trash
    (`MEDIA_TRASH`): without it, a non-forced delete answers
    `media_trash_not_supported` (501), and `force` string values coerce like
    booleans (`"false"` does not delete).
  - An explicit `publish` status on an attachment is stored as `inherit`, as
    attachments are stored; a new `inherit` status is rejected
    (`media_invalid_param`) while keeping the current one is allowed.
  - A URL upload downloads from another site (`open_world: true`), derives the
    file name and type from the URL path, requires the `upload_files`
    capability separately from the create permission, and caps the download at
    `wp_max_upload_size()` so no uncapped server-side fetch can happen.
  - A non-string image-meta value (EXIF/iptc data is filterable) triggers the
    PR's own `(string)` cast warning and is ignored: the title falls back to
    the file name (verified identical on trunk and stable).
  - `media-update` re-checks the refuse-able fields (status, author, parent
    type) inside the execute callback so the error can name the refused field,
    and does not re-check the new parent post's edit capability
    (`media_invalid_param` covers revision/attachment parents only).
  - The delete capability is re-checked right before anything is removed, so
    a hook refusal surfaces as `media_cannot_delete` (500) on the
    `pre_delete_attachment` veto.
