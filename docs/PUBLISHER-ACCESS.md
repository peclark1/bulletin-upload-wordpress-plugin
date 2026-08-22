# Bulletin Publisher user access

Version 0.3 adds a narrow WordPress role intended for the one or two people who assemble and publish the weekly bulletin.

## User experience

Give each bulletin user their own WordPress account with the role:

**Bulletin Publisher**

They bookmark:

`https://YOUR-SITE/bulletin-publisher/`

If they are not signed in, WordPress shows its normal login page. After login they are sent directly to the Bulletin Publisher tool.

Publisher-only users see a focused interface without the normal WordPress dashboard/navigation. They can:

- select the bulletin Sunday
- select weekly PDF files or a weekly folder
- build and view the private preview
- publish to the isolated test area
- publish the reviewed PDF live
- discard a preview

They cannot change the saved front/back cover templates. Cover maintenance remains an administrator task.

## Security model

The role stores only:

- `read`
- `manage_church_bulletins`

The existing publisher code originally used WordPress's `manage_options` capability. To avoid changing the already-tested PDF/publish code, version 0.3 maps `manage_options` for Bulletin Publisher users only while WordPress is executing:

- the Bulletin Publisher admin screen
- explicitly enumerated Bulletin Publisher `admin-post.php` actions

The mapping does not apply globally and does not apply to `cbp_save_covers`.

A publisher-only account that tries to visit another wp-admin screen is redirected back to the publisher. The Profile screen remains available so the user can maintain their own account/password.

## Friendly URL

`/bulletin-publisher/` is an entry route implemented by the plugin; no WordPress Page needs to be created. It sends `noindex, nofollow` and does not rely on the URL being secret.

A logged-out visitor is redirected through WordPress login. A logged-in account without either `manage_options` or `manage_church_bulletins` receives HTTP 403.

## Administrator setup

1. Upgrade the Church Bulletin Publisher plugin to version 0.3.0.
2. Open **Users → Add New User** (or edit an existing user).
3. Assign the **Bulletin Publisher** role.
4. Give the user the site's `/bulletin-publisher/` URL.
5. Test with a publisher account before handing it to the end user.

Administrators continue to use the existing WordPress Bulletin Publisher screen and retain the cover-template controls.
