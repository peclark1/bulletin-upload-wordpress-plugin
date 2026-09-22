# Parish Forms

Parish Forms is a small, purpose-built WordPress plugin for St. Peter the Apostle and St. Mary's Two Inlets. Version 0.1 provides the Parish Registration form without introducing a general-purpose form builder.

## Features

- Responsive parish-branded frontend form
- Conditional marriage and spouse fields
- Repeatable child sections (up to 10 children)
- Schema-based server validation and sanitization
- Non-public WordPress submission storage
- Configurable staff email notifications containing the submitted data
- Administrator-only submission review, status, trash, restore, and deletion tools
- UTF-8 CSV export with spreadsheet-formula protection
- Draft registration page created on activation
- Reusable PHP form-definition architecture for future parish forms

## Requirements

- WordPress 6.2 or later
- PHP 7.4 or later

## Install

From the repository root, build the installable plugin ZIP:

```bash
make parish-forms
```

Install the resulting `parish-forms-0.1.0.zip` in **Plugins > Add New > Upload Plugin**, then activate it.

Activation creates a draft **Parish Registration** page. Review and publish that page, or place this shortcode on another page:

```text
[parish_form id="parish-registration"]
```

Open **Parish Forms > Settings** to set one or more notification addresses. Open **Parish Forms > Submissions** to review registrations or export them as CSV.

## Data handling

Submissions are stored as private, non-public WordPress records. Access to the plugin screens and CSV exports requires the `manage_parish_forms` capability, which is added to the Administrator role on activation. The plugin does not expose submissions through the REST API.

Email delivery depends on the site's configured WordPress mail transport. A failed notification does not discard a submission; the administrator list records whether the email was sent.

Deactivation does not delete registrations. Authorized administrators can trash and permanently delete individual registrations from the plugin interface according to parish record-retention policy.

## Extending with another parish form

Add a definition class under `includes/forms/`, register its returned definition in `PFORM_Form_Registry::all()`, and render it with `[parish_form id="your-form-id"]`. The shared renderer, validator, storage, notification formatter, administration view, and CSV exporter consume the definition automatically.

Definitions are code-reviewed PHP arrays, not administrator-created layouts. This keeps version 0.1 focused, auditable, and predictable.

## Developer checks

Run `make test-parish-forms` from the repository root to lint the PHP, exercise validation/rendering edge cases, and check the frontend JavaScript syntax. The repository's GitHub Actions workflow runs the PHP checks for pushes and pull requests.
