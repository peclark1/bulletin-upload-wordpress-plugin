# Parish Forms

Parish Forms is a purpose-built WordPress plugin for St. Peter the Apostle and St. Mary's Two Inlets. Version 0.4 adds a constrained staff Form Manager on top of the secure rendering, validation, private submission storage, email notification, admin review, and CSV export infrastructure introduced in earlier versions.

## Features

- Responsive parish-branded frontend forms
- Staff Form Manager under **Parish Forms > Forms**
- Create, duplicate, edit, preview, publish, retire, and restore forms
- Draft/publish workflow so staff can preview changes before they affect the public form
- Immutable published version history with **Restore to Draft**
- Existing Parish Registration, Pre-Baptismal Questionnaire, and Confirmation Interest Form migrated into the Form Manager on upgrade
- Submission-time definition snapshots so later edits do not change the meaning or labels of historical submissions
- Supported staff-editable field types: text, email, phone, date, long text, multiple choice, checkboxes, consent, and repeatable groups
- Simple conditional display rules based on another field's value
- Section and field reordering by drag-and-drop or move buttons
- Schema-based server validation and sanitization
- Non-public WordPress submission storage
- Configurable staff email notifications containing submitted data
- Administrator-only submission review, status, trash, restore, and deletion tools
- UTF-8 CSV export with spreadsheet-formula protection
- Reusable shortcode rendering: `[parish_form id="your-form-id"]`

The editor is intentionally constrained. It does not allow arbitrary PHP, JavaScript, HTML, payments, calculated fields, or external database queries.

## Requirements

- WordPress 6.2 or later
- PHP 7.4 or later

## Install / upgrade

From the repository root, build the installable plugin ZIP:

```bash
make parish-forms
```

Install the resulting `parish-forms-0.4.0.zip` in **Plugins > Add New > Upload Plugin**. When upgrading an existing installation, choose **Replace current with uploaded**.

On the first 0.4 load, the plugin:

1. Creates versioned Form Manager records for the three existing built-in forms.
2. Publishes their current definitions as version 1.
3. Backfills a definition snapshot onto existing submissions where possible.
4. Leaves existing shortcodes and public pages working with the same form IDs.

## Using the Form Manager

Open **Parish Forms > Forms**.

For each form you can:

- **Edit** the saved draft.
- **Preview** the draft without changing the public form.
- **Publish Changes** to create a new immutable published version.
- **Duplicate** a form as the starting point for a new form.
- **Retire** a form so its shortcode stops rendering for visitors while its submissions and version history remain available.
- **Restore** a retired form.
- Restore an older published version **to the draft editor**, then publish it if you want that version to become live again.

A new form receives a permanent Form ID when it is first saved. Its shortcode is then shown on the Forms screen.

## Existing form shortcodes

```text
[parish_form id="parish-registration"]
[parish_form id="pre-baptismal-questionnaire"]
[parish_form id="confirmation-interest"]
```

## Submission history and form versions

Every new submission stores both its submitted values and a snapshot of the exact published form definition used at submission time. The admin detail view therefore continues to show the labels and structure that were in effect when the person submitted the form, even if staff later rename, reorder, add, or remove fields.

Published form versions are immutable. Editing always changes a draft; publishing creates the next version.

## Data handling

Submissions and form/version records are non-public WordPress content. Access to the plugin screens and CSV exports requires the `manage_parish_forms` capability, which is added to the Administrator role on activation. The plugin does not expose submissions through the REST API.

Email delivery depends on the site's configured WordPress mail transport. A failed notification does not discard a submission; the administrator list records whether the email was sent.

Deactivation does not delete forms or submissions. Authorized administrators can trash and permanently delete individual submissions according to parish record-retention policy.

## Developer architecture

The PHP definitions under `includes/forms/` remain seed/fallback definitions for the original forms. Once version 0.4 migrates a form into the Form Manager, the versioned stored definition becomes authoritative; plugin upgrades do not overwrite staff edits.

The shared renderer, validator, formatter, notification system, submission administration, and CSV exporter consume the same normalized definition schema whether a definition came from the built-in seed or the Form Manager.

## Developer checks

Run:

```bash
make test-parish-forms
```

This lints PHP, exercises validation/rendering and definition-sanitization cases, and syntax-checks both frontend and Form Manager JavaScript. The repository's GitHub Actions workflow runs these checks for pushes and pull requests.
