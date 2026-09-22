# Parish Forms architecture

## Design boundary

Parish Forms is definition-driven, but it is not a form builder. Each supported parish form is a reviewed PHP definition containing sections, fields, options, validation constraints, and conditional-display rules.

The shared services consume that definition:

| Service | Responsibility |
|---|---|
| `PFORM_Form_Registry` | Registers versioned form definitions |
| `PFORM_Renderer` | Builds accessible frontend HTML from a definition |
| `PFORM_Validator` | Applies server-side allowlists, required rules, lengths, email/date checks, and conditional rules |
| `PFORM_Submissions` | Stores normalized submissions as private, non-REST WordPress records |
| `PFORM_Notifications` | Creates plain-text staff notifications from normalized data |
| `PFORM_Formatter` | Produces definition-aware labels and values for email and administration |
| `PFORM_Admin` | Lists, reviews, manages, and exports authorized submissions |

## Submission flow

1. `[parish_form]` loads a registered definition and enqueues only the frontend assets it needs.
2. JavaScript progressively enhances conditional sections and repeatable children; every security and validation rule is repeated on the server.
3. The POST handler verifies the form ID, WordPress nonce, signed start time, honeypot, and hourly connection limit.
4. The validator ignores fields outside the definition, sanitizes each allowed value, enforces option allowlists and limits, and discards conditional fields that do not apply.
5. Valid normalized data is stored in a private `pform_submission` record. Personally identifying values are kept in protected post metadata, not in the public title or URL.
6. A staff notification is attempted. Mail failure is recorded but never causes loss of the stored registration.
7. The browser follows a POST/redirect/GET flow. Validation state uses a short-lived random transient so submitted data is never placed in the URL.

## Access and privacy

- The custom post type is non-public, non-queryable, hidden from the standard post editor, and unavailable through REST.
- Administration, submission actions, settings, and CSV export require `manage_parish_forms`.
- Every mutating administrator action and CSV export uses a WordPress nonce.
- CSV cells that could be interpreted as spreadsheet formulas are prefixed safely.
- Submission IP addresses and browser details are not retained. A salted hash of the current connection address is used only in a one-hour transient for rate limiting.
- Deactivation preserves records. Deletion is an explicit administrator action.

## Parish Registration definition

The first definition mirrors the approved registration form:

- Household and marital information
- Primary contact and sacraments
- Conditional spouse information and sacraments
- Repeatable children living at home
- Parish directory photo willingness
- Flocknote information
- Ministry interests and other skills
- Offertory preference
- Registration status, parish, typed name, date, and consent

The definition version is saved with each submission so future migrations can distinguish data captured under earlier schemas.
