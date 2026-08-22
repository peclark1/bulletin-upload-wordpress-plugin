# Architecture and rollout

## Isolation strategy

The plugin uses a narrow test area instead of a full WordPress staging clone.

| Data | Test location | Production location |
|---|---|---|
| Unfinished previews | `wp-content/bulletin-publisher-private/` | Same private area |
| Published test PDFs | `wp-content/uploads/bulletin-publisher-test/` | Not used by live listing |
| Published live PDFs | Never written by test action | `wp-content/bulletins/YYYY/` |
| Listing page | Draft `Bulletin Publisher Test` page | Existing Bulletins page with `[church_bulletins]` |
| Initial live-tree backup | N/A | `wp-content/bulletin-publisher-private/live-backups/` |
| Replaced live-file backups | N/A | `wp-content/bulletin-publisher-private/live-replacements/` |
| Production audit log | N/A | `wp-content/bulletin-publisher-private/audit/production-audit.jsonl` |

Preview PDFs and production safety data remain under the protected private tree. The private directory contains Apache deny rules and cannot be browsed directly.

## Request flow

1. The browser accepts one or more PDFs or all PDFs from a selected local/Google Drive folder.
2. Components are sorted with Weekly Pages first and Inserts second; natural filename order is used within each group.
3. When the server lacks qpdf and pdfunite, the bundled pdf-lib library fetches the authenticated cover templates and assembles the preview in the browser.
4. The browser uploads the finished PDF in 512 KiB authenticated chunks; WordPress reassembles and validates it in private storage. This avoids shared-host request-size limits. When qpdf or pdfunite is available, the original server-side merge path remains available.
5. The preview path and SHA-256 are held in a user-specific transient for 48 hours.
6. The administrator views the preview through an authenticated streaming endpoint.
7. **Publish to Test Area** re-verifies the preview hash and atomically writes to the isolated test tree.
8. **Publish LIVE Bulletin** requires an explicit checkbox plus confirmation dialog, re-verifies the preview hash, ensures the initial live-tree backup exists, backs up any same-date live file, and atomically writes to `wp-content/bulletins/YYYY/`.
9. Production actions are appended to the protected JSON-lines audit log.
10. The shortcode scans only the selected test or live tree and renders dated links newest-first.

## Production cutover status

Verified during the test phase:

- The bundled browser merger loads successfully on the live HostGator WordPress installation.
- The known-good sample merges correctly in the administrator's browser.
- Weekly-folder selection works as intended.
- The private preview workflow and draft test listing operate correctly.
- Repeat test publication creates recoverable backups.
- The draft listing page shows correct dates and links.

Implemented in version 0.2:

- Guarded live publishing to `wp-content/bulletins/YYYY/`.
- SHA-256 re-verification immediately before live publication.
- Automatic one-time protected backup of the existing live bulletin tree before the first live write.
- Protected backup of an existing same-date bulletin before replacement.
- Production audit log.
- Separate test and live publication controls.

Final deliberate cutover step:

- Replace the existing public Bulletins page's manual table with `[church_bulletins]` only after one live PDF has been published and its direct URL has been verified.

No staging-site database push is needed for this rollout.
