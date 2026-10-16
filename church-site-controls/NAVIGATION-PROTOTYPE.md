# Church Site Controls v0.3.1 — experimental navigation prototype

Built from the v0.2.2 staging plugin. Adds *progressive* navigation on `/design-demo` only; no classic menu item data is modified.

## Desktop
- Warm ivory (#f7f5ef) navigation panel, navy (#213b5c) text, and gold (#c49b45) separators distinguish the expanded menu from the blue header.
- Menu labels are extracted without Kadence's nested screen-reader \"Expand\" controls (fixes \"GETTING STARTEDEXPAND\").
- Mouse hover over Worship / Parish Life / About opens the entire section as a stable panel, not cascading flyouts.
- Top-level click toggles the panel; Escape, outside click, or leaving nav/panel for 260ms closes it.
- Current classic menu subitems and links are reused, grouping nested children into columns.
- The panel fits the available viewport with its own vertical scroll.
- The native Kadence desktop dropdowns remain a no-JS fallback, and are hidden **only after JS initialization**.
- Keyboard focus, Down-arrow, Escape, and ARIA-expanded/controls have initial support, pending browser/assistive-tech checks.

## Mobile / narrow screens
- Retain Kadence's built-in hamburger, popup drawer, and nested menu controls.
- Add drawer overflow scrolling and larger touch targets.
- Desktop enhancement activates above 1024px only when primary nav is visible. Any site-specific Kadence breakpoint overriding that assumption needs live verification.

## Staging acceptance required before use
1. Verify actual desktop DOM of Kadence 1.5.2 and validate triggers for Worship, Parish Life, About.
2. Check hover transitions, click toggling, nested columns and all links.
3. At 1366x768, 1280x720, 1024x768, and 390x844, ensure no clipped/hidden entries or horizontal scroll; test viewport height ~600.
4. Check scroll and sticky header positions, browser zoom 200%, keyboard Tab/Escape, touch menu and menu close.
5. Check that WP STAGING stays inactive and the staff can access the demo.
6. If selectors vary from assumptions, disable this plugin or revert to 0.2.2. No WordPress settings need restoring because the prototype is client-side.

No production deployment or plugin auto-upgrade is authorized.
