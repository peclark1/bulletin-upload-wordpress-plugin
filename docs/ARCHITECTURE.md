# Architecture and rollout

## Isolation strategy

The plugin uses a narrow test area instead of a full WordPress staging clone.

| Data | Test location | Production location |
|---|---|---|
| Unfinished previews | `wp-content/bulletin-publisher-private/` | Same private area |
| Published test PDFs | `wp-content/uploads/bulletin-publisher-test/` | Not used in v0.1 |
| Existing live PDFs | Never written by v0.1 | `wp-content/bulletins/YYYY/` |
| Listing page | Draft `Bulletin Publisher Test` page | Existing Bulletins page later |

Preview PDFs are served through an authenticated WordPress action. The private directory contains Apache deny rules and cannot be browsed.

## Request flow

1. Browser folder picker uploads only PDFs from the selected local/Google Drive folder.
2. WordPress validates administrator capability, nonce, file size, extension, and `%PDF-` signature.
3. Components are sorted with Weekly Pages first and Inserts second; natural filename order is used within each group.
4. The server invokes qpdf or pdfunite using escaped arguments.
5. The preview path and SHA-256 are held in a user-specific transient for 48 hours.
6. The administrator views the preview through an authenticated streaming endpoint.
7. Publish verifies the SHA-256 and atomically moves a copy into the isolated test tree.
8. The shortcode scans only the selected test or live tree and renders dated links newest-first.

## Production cutover checklist

Production publishing should be enabled only after:

- HostGator diagnostics find a working merger.
- The supplied known-good sample merges correctly on HostGator.
- Folder selection is tested in the browsers used by the administrator.
- The private preview URL is inaccessible when logged out.
- Repeat publication creates a recoverable backup.
- The draft listing page shows correct dates and links.
- The existing `wp-content/bulletins` directory is backed up.
- A live-mode confirmation control and audit log are implemented.
- The existing page is changed only by replacing its manual table with `[church_bulletins]`.

No staging-site database push is needed for this rollout.
