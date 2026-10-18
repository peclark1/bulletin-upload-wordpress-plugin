# Parish Forms

Parish Forms is a small, purpose-built WordPress plugin for St. Peter the Apostle and St. Mary's Two Inlets. Version 0.4 adds a constrained Form Manager so authorized parish staff can make routine form changes and create new forms without editing PHP, while retaining the secure rendering, validation, storage, email, administration, and CSV infrastructure.

## Features

- Responsive parish-branded frontend forms
- Parish Registration, Pre-Baptismal Questionnaire, and Confirmation Interest Form
- Form Manager for creating, duplicating, editing, previewing, publishing, and retiring forms
- Versioned published definitions with exact definition snapshots saved on new submissions
- Draft/publish workflow so staff can prepare changes without immediately altering the public form
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

Install the resulting `parish-forms-0.4.0.zip` in **Plugins > Add New > Upload Plugin**, then activate it.

Activation or upgrade creates draft **Parish Registration** and **Pre-Baptismal Questionnaire** pages. Review and publish those pages, or place either shortcode on another page:

```text
[parish_form id="parish-registration"]
[parish_form id="pre-baptismal-questionnaire"]
```

Open **Parish Forms > Forms** to manage form definitions. Existing code-defined forms are migrated into the Form Manager on upgrade. Use **Save Draft** to prepare changes, **Preview Saved Draft** to review them, and **Publish Changes** to create a new immutable form version. Open **Parish Forms > Settings** to set notification addresses and **Parish Forms > Submissions** to review or export submissions.

## Prayer Request form (v0.4.2)

The v0.4.2 maintenance update fixes a readability regression on the Prayer
Request privacy note: the navy submit panel now renders that text in white
instead of dark gray. No behavior or recipient settings are changed.

The built-in **Prayer Request** is a simple, anonymous (no contact fields)
long-text form compatible with the existing Parish Forms renderer,
server-side field validation, nonce, signed start-time check, honeypot,
rate limit, private submission storage, administrator review, and
POST/redirect/GET confirmation. Embed it using:

```text
[parish_form id="prayer-request"]
```

It has **one required textarea**, `intention`, capped at 1,500 characters.
The public text asks visitors to omit last names, medical details, and
other sensitive personal information. The form never posts intentions
publicly, and staff access to submissions requires the existing
`manage_parish_forms` permission.

**Prayer notification routing is separate from all other forms.** In
**Parish Forms → Settings**, enter explicitly approved staff recipients
under **Prayer Request notification addresses** (comma-separated).
By default this list is empty; there is deliberately **no fallback**
to the global notification recipients for registrations and other forms.
Submissions are stored privately even when notification addresses are
empty or delivery fails. Prayer notifications provide a private
administrator submission link, timestamp, and ID but do **not** include
the submitted prayer text in email. Other form notifications retain
their existing behavior.

Before launching publicly, staff must approve the notification recipients,
confirm who responds to intentions, decide a retention/deletion process
for private submissions, and review the parish's public privacy notice.
Do **not** test by sending real or sensitive prayer intentions through
staging; automated tests use fake WordPress email functions and sample
text, and verify routing and sanitization.

## Data handling

Submissions are stored as private, non-public WordPress records. Access to the plugin screens and CSV exports requires the `manage_parish_forms` capability, which is added to the Administrator role on activation. The plugin does not expose submissions through the REST API.

Email delivery depends on the site's configured WordPress mail transport. A failed notification does not discard a submission; the administrator list records whether the email was sent.

Deactivation does not delete registrations. Authorized administrators can trash and permanently delete individual registrations from the plugin interface according to parish record-retention policy.

## Extending with another parish form

Add a definition class under `includes/forms/`, register its returned definition in `PFORM_Form_Registry::all()`, and render it with `[parish_form id="your-form-id"]`. The shared renderer, validator, storage, notification formatter, administration view, and CSV exporter consume the definition automatically.

Form definitions are stored as constrained, sanitized schemas. The editor supports the field types already used by Parish Forms (text, email, phone, date, long text, radio choices, checkbox choices, consent, and one-level repeatable groups), simple show-when conditions, field widths, required flags, and form messages. It intentionally does not support arbitrary PHP, JavaScript, or HTML.

## Developer checks

Run `make test-parish-forms` from the repository root to lint the PHP, exercise validation/rendering edge cases, and check the frontend JavaScript syntax. The repository's GitHub Actions workflow runs the PHP checks for pushes and pull requests.
