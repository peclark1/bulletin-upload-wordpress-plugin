# Church Site Controls v0.3.7 — Parish Promotions carousel design preview (#13)

**Experimental visual pilot on staging Home (page 3498) only. Not a staff
management interface, a scheduled-event system, or a production release.**

Adds a shortcode [parish_promotions_preview] designed to be inserted once in
a full-width Gutenberg Group immediately above Quick Links.

- Three archived Media Library posters used strictly as sample artwork:
  Alpha Fall 2026 (attachment 3487), The Bible Timeline (3449), and the
  2026 Rummage Sale (3437). Historical event dates are not current promotions.
  The carousel explicitly labels this as a design preview.
- Entire images shown using object-fit: contain, never cropped, with
  optional More Information and Sign Up controls below the graphic.
  Because real destination URLs are not configured yet, those example controls
  are disabled visual placeholders, not deceptive or broken links.
- Desktop: featured centered card, smaller clickable previous/next posters,
  gentle right-to-left rotation (~7 seconds), back/forward arrows, slide dots
  and pause/play. Hover/focus pauses autoplay; keyboard arrows and touch swipe
  select slides. Autoplay runs only while in view and the tab is visible.
- Mobile up to 760 px: one focused card with controls, rather than squeezed
  neighbor previews. Reduced-motion preference disables animation and autoplay.
- If underlying images are missing, hide relevant slides; one image
  becomes a static featured card, zero images hides the full section.
- Only enqueues CSS and JS on /design-demo Home (Kadence). Does not modify
  navigation, header settings, existing homepage content except the shortcode
  insertion, bulletin schedule, parish forms, or production.

**Staging deployment & validation:** Keep v0.3.6 rollback ZIP. CI must pass;
prepare the CI artifact via Church Plugin Deployer; installation needs operator
approval through the existing secured WordPress workflow. Insert shortcode above
Quick Links only after plugin is active, read back exact Home content, inspect
rendering and user-test on desktop/mobile. Remove just that shortcode Group to
roll back page placement. Do not mark #13 done: this is the prototype-design gate.

**Later phase (not delivered here):** a standalone Parish Promotions staff UI
with records, image selection, display start/end dates in parish timezone,
separate optional link targets, draft/published states, ordering, preview and
expired history. Real entries should replace the hardcoded prototype assets;
do not let past poster dates accidentally become active announcements.
Check CDN/page caching against automatic expiry. Consider a separate plugin
for the eventual reusable feature.

---

# Church Site Controls v0.3.6 — Sticky mega-menu anchoring (cutover #2)

This maintenance release fixes a **staging-only** interaction between Kadence's
sticky desktop header and the Church Site Controls full-width mega navigation.

Previously, the fixed mega panel used the outer `#masthead` bounding rectangle,
even though Kadence can keep only the inner navigation row stuck in view. As the
outer header scrolled away, the panel's top position could clamp to `0px`,
covering the sticky navigation with its higher stacking order.

The desktop panel now uses the **actual visible primary navigation** as its
anchor. It keeps the panel under the sticky row and closes it if that navigation
moves fully offscreen, instead of leaving an orphaned full-screen overlay.
Internal scrolling *inside* the large menu still works; mouse hover, click,
keyboard Escape and mobile hamburger behaviors are unchanged. This is not a
general CSS override and does not change global Kadence settings or menu links.

**Safe deployment:** only to `https://stpeterpr.org/design-demo`, after CI
passes and administrator approval via Church Plugin Deployer, retaining the
installed v0.3.5 as the rollback target. Test Home and Adult Faith Formation
with Parish Life open before/after window scroll, and menu focus/escape,
mobile viewport and short desktop heights. Source-level regression assertions
are not evidence of a successful rendered staging test; keep cutover #2 open
until post-install visual acceptance. Never deploy to production here.

---

# Church Site Controls v0.3.4 — Worship responsive layout (cutover #26)

This release adds **staging-only, page-specific front-end CSS** to the live-tested v0.3.3 Church Site Controls plugin. Nothing changes Gutenberg blocks, Kadence theme mods, menu assignments, or Bulletin Publisher's dynamic schedule data.

- Site: `https://stpeterpr.org/design-demo` **only**; current theme must be Kadence; applies only to Worship page ID `23`.
- Stylesheet: `assets/worship-responsive.css`, enqueued by `CSC_Page_Polish`. It is versioned with the plugin and absent on all other pages and production.
- At widths up to 1024px: the approved bulletin-powered Mass / Devotions schedule cards become a single comfortable column; the Worship cover's fixed 46px heading, 64px paddings, and 360px min-height become fluid/compact.
- Up to 600px: the hero typography, eyebrow, subtitle, cards, and buttons adapt to narrow phones. Native WordPress title hierarchy, links, and accessible content order remain unchanged.
- Above 1024px: no styles are applied; desktop layout is unchanged.
- Run `make test-church-site-controls` and full GitHub Actions build before stage deploy. Target is CSC 0.3.3 → 0.3.4 using CPD v0.2 (MCP prepare then administrator's click under Tools → Church Plugin Deployer).
- Validate at ~320/375/600/768/960/1280px on live staging: no clipping, horizontal scroll, obscured controls, or collapsed desktop behavior. Use actual content, including the week-navigation links and long Mass intentions. The code regression tests are **not** browser layout proof.
- On any visual regression, recover with the private code-only ZIP prepared by CPD (or restore the earlier plugin build); never overwrite the full site or production.

---

# Church Site Controls 0.2.2 — experimental staging build

## v0.2 global editors

This version extends the proven v0.1 hero code without changing its storage or
undo format. Four new abilities require explicit Administrator grants in the
ChatGPT Design Demo MCP agent: `inspect-global`, `update-global`,
`inspect-navigation`, and `update-navigation`. Do not grant other roles.

`inspect-global` reports the verified Kadence 1.5.2 Customizer control IDs,
raw presence/value, effective composite and a SHA revision of the full
`theme_mods_kadence` option. `update-global` accepts one named target, typed
value and fresh expected revision. The same code handles:

| Target | Storage leaf | Input |
|---|---|---|
| `header.sticky_background_desktop` | `header_sticky_background.desktop.color` | six-digit hex |
| `header.sticky_navigation_color` | `header_sticky_navigation_color.desktop.color` | six-digit hex; verify shape before live use |
| `header.main_background_desktop` | `header_main_background.desktop.color` | six-digit hex |
| `header.main_height_desktop` | `header_main_height.size.desktop` | integer 40–180 |
| `footer.middle_background_desktop` | `footer_middle_background.desktop.color` | six-digit hex |
| `footer.bottom_background_desktop` | `footer_bottom_background.desktop.color` | six-digit hex |
| `footer.wrap_background_desktop` | `footer_wrap_background.desktop.color` | six-digit hex |

Writes refuse a field whose actual effective Kadence shape differs from the
declared path. An option SQL compare-and-swap protects all sibling settings,
followed by WordPress option hooks/cache invalidation. Undo restores only the
selected leaf, removes an override that was originally absent, and preserves
unrelated later edits. A fresh full-option revision is still required.

`inspect-navigation` takes a classic `nav_menu_item` ID and reports its one
menu, assigned locations, metadata, and revision. `update-navigation` supports
`label`, `attr_title`, and local `url` only on a custom menu item; the page
link for St. Mary's (item 3812) therefore cannot be changed by this adapter.
WordPress's menu API is used, retaining parent, order, target, rel, classes and
destination. It lacks an atomic compare-and-swap operation: the adapter checks
the revision immediately before the API call and verifies readback, but a
simultaneous menu editor in that tiny interval remains a limitation.
Menu title attributes and descriptions are read from their raw WordPress post fields, not the wptexturize-filtered display values; title attributes can be cleared to an empty string. The WordPress menu API receives pre-slashed text as its contract requires.

The actual footer consists of Kadence Footer Builder HTML in its middle row
and a block widget in the bottom row. v0.2 edits allowlisted footer appearance
settings; logo placement and raw HTML/widget editing are excluded until the
approved media asset and safe content adapter are verified.

Before global staging edits, confirm a restorable database/site snapshot.
Build `make church-site-controls`; save the 0.1.0 ZIP for plugin rollback.
Install 0.2.2 by replacing the existing Church Site Controls plugin, verify it
is active, then discover and grant the four named MCP abilities only with user
approval. Inspect fresh revisions after installation; test each change in the
browser (desktop/mobile and scroll state) and undo diagnostics independently.
Neither a plugin undo nor the 0.1.0 ZIP is a full database restore.

A separately installable plugin for `https://stpeterpr.org/design-demo` only. Both
WordPress home and site URLs must match. Every ability requires the authenticated
user's `manage_options`; page operations additionally require `edit_post`.
Installing this plugin does not change appearance or grant any user capability.
WordPress 6.9+ supplies the Abilities API. A compatible MCP server is separately
required (the staging site uses MCP Server for WordPress).

## Preserved v0.1 scope and storage

- Legacy `inspect-header`: **inspection only**. Reports selected raw theme mods,
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
- Arbitrary CSS/options, nested/non-Cover heroes and image/overlay controls
  remain unsupported.

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

Install `../church-site-controls-0.2.2.zip` through **staging** Plugins → Add New →
Upload Plugin, then activate. Do not replace Bulletin Publisher or Parish Forms.
`make plugins` builds all three separately; CI runs all suites and uploads each
ZIP. The tests use the observed Home Cover markup plus isolated WordPress/DB
fakes to check boundaries, permissions, races, audit failures, exact sibling
preservation and undo. They are not real WordPress/MCP integration evidence.

## v0.3.5 — Equal-height card rows as a site-wide design standard

The `assets/equal-height-cards.css` stylesheet is loaded through
`CSC_Page_Polish` on **all public pages of the designated `/design-demo`
staging installation** only, provided Kadence is active. It never loads on
production, another theme or the WordPress admin.

When a Gutenberg Columns row has **at least two adjacent columns whose
direct children are background-colored Group blocks**, those Groups are
considered a card row. At screen widths `>= 782px`, the columns stretch
to the height of the tallest sibling card. Every directly nested card
fills its column's height using CSS flex growth and border-box sizing.

- Applies automatically to existing and future side-by-side Gutenberg
  card groups; editors do not need to repeat manual height values.
- Intentionally **excludes** ordinary text+image/story rows, and
  vertically centered image/text layouts.
- At narrower widths the WordPress Columns block stacks as usual,
  and each card uses its natural content height; no fixed pixel
  heights are imposed.
- Card contents, PDF links, buttons, colors and event data remain
  unchanged. This complements (rather than replaces) existing inline
  Gutenberg card backgrounds/borders, including previously set
  `min-height:100%` on some cards.
- The standard currently covers card rows built using core
  `wp-block-columns > wp-block-column > wp-block-group.has-background`.
  Other structures, such as plugin shortcode grids, custom HTML cards
  and entirely unstyled Groups, are outside this automatic rule and
  should receive their own layout rules when needed.
- Uses modern CSS `:has()` support. Unsupported legacy browsers keep
  the original non-destructive column layout.

Regression tests assert enqueue guards and the key responsive/structural
selectors, but these are not computed browser-layout tests. After install,
visually check Youth Faith Formation, Home, New Here, Catholic Resources,
Adult Faith Formation and other multi-card pages at wide, mid-sized and
stacked mobile widths. Check that card bottoms align and no overflow
appears. User acceptance of issue #18 is separate from version/build
checks.

