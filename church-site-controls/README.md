# Church Site Controls 0.1.0 — experimental staging build

A separately installable plugin for `https://stpeterpr.org/design-demo` only. Both
WordPress home and site URLs must match. Every ability requires the authenticated
user's `manage_options`; page operations additionally require `edit_post`.
Installing this plugin does not change appearance or grant any user capability.
WordPress 6.9+ supplies the Abilities API. A compatible MCP server is separately
required (the staging site uses MCP Server for WordPress).

## Scope and storage

- Global Kadence header: **inspection only**. Reports selected raw theme mods,
  effective values through Kadence's accessor, custom storage mappings, active
  theme/version, and Additional CSS fingerprint. Missing/default values are not
  invented. An option-backed configuration is identified but its raw option
  array is not exposed. No global theme settings are written in v0.1.
- Hero: the **first top-level Gutenberg Cover** in a selected page's
  `posts.post_content`. The supported property is `padding_top_px` (integer
  0–200), represented in both `style.spacing.padding.top` and the saved wrapper
  `padding-top`. The observed Home page 3498 has 70px. The adapter patches those
  two value tokens only, preserving every other byte. Unsupported or mismatched
  structures are rejected. This is a block property, not a Kadence theme mod.
- Other hero layers: the Home page uses `_kad_post_transparent=enable`; Additional
  CSS gives the Cover text a shadow. Neither is changed by this plugin.
- Footer, arbitrary CSS/options, nested/non-Cover heroes, image/overlay controls,
  and general header mutation are unsupported.

The adapter intentionally accepts a narrow saved markup format. It does not
serialize the whole block tree, inject CSS, or change page status/title/meta.

## Abilities

WordPress names use `/`; the connected MCP server exposes them with `__`.

| WordPress name | Input | Behavior |
|---|---|---|
| `church-site-controls/capabilities` | `{}` | Version, target, supported fields, authorization |
| `church-site-controls/inspect-header` | `{}` | Global Kadence header storage/effective values |
| `church-site-controls/inspect-hero` | `page_id` | Supported field/current value/full-content revision |
| `church-site-controls/update-hero` | `page_id`, `field`, `value`, `expected_revision` | Narrow edit and durable change ID |
| `church-site-controls/recent-changes` | `{}` | Last 20 minimal audit records; recovery after lost responses |
| `church-site-controls/get-change` | `change_id` | One audit/undo record |
| `church-site-controls/undo` | `change_id`, `expected_revision` | Exact restoration or conflict error |

Read abilities have readonly/idempotent annotations; mutation abilities do not.
`mcp.public` and `show_in_rest` enable discovery, **not anonymous authorization**.
Permissions and input validation also run inside execution callbacks.

## Reversible demonstration after installation

1. Discover `church-site-controls` through the connected MCP service.
2. Call capabilities and inspect-header; confirm staging, version, and permissions.
3. Inspect-hero for 3498; capture value, content hash and expected_revision.
4. Update-hero with `field: padding_top_px`, `value: 78`, and that revision
   (choose another small value if 78 is already current).
5. Inspect again. Verify the two saved tokens and compare rendered top padding.
6. Undo using the returned change_id and the **after** revision. Inspect again:
   the original full-content hash must match. Check rendered restoration.

Never count a local test as this real MCP acceptance test. A page cache can hide
a successful stored update; rendering must be checked separately.

## Concurrency, history and recovery

A SHA-256 revision covers site, page ID and **all page content**. The database
write uses an atomic binary old-content comparison, preventing concurrent
content loss. Only `post_content`, `post_modified` and `post_modified_gmt` change.
Post cache is invalidated and `church_site_controls_changed(page_id)` fires.
The narrow SQL CAS intentionally bypasses generic `save_post` hooks and standard
WordPress revision creation; cache plugins may need their own purge integration.
This limitation must be assessed in staging, not assumed tested in production.

Minimal before/after values, full-content revision hashes, actor ID, timestamp,
and change ID are saved in a non-autoloaded `csc_change_<uuid>` option **before**
writing. No passwords, tokens, submissions or full page content are stored.
If the final audit status write fails, the prepared record remains undoable.
Recent-changes recovers IDs after interrupted responses. If undo's status update
fails, readback still proves restoration; the retained record is conservative
and cannot overwrite the restored content. Audit records are deliberately not
removed on deactivation/uninstallation. Review/retention tooling is deferred.

Undo refuses intervening edits, even if the caller supplies a fresh revision.
It requires the current after hash and proves the reversed patch reproduces the
original hash. Do not force a rollback over another editor's work. A failed
atomic write can leave a harmless prepared audit record with no applied change.

## Build and tests

```sh
make test-church-site-controls
make church-site-controls
make plugins
```

Install `../church-site-controls-0.1.0.zip` through **staging** Plugins → Add New →
Upload Plugin, then activate. Do not replace Bulletin Publisher or Parish Forms.
`make plugins` builds all three separately; CI runs all suites and uploads each
ZIP. The tests use the observed Home Cover markup plus isolated WordPress/DB
fakes to check boundaries, permissions, races, audit failures, exact sibling
preservation and undo. They are not real WordPress/MCP integration evidence.
