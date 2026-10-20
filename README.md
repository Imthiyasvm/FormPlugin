# Smart Form Conditions for Pro Elements

A standalone WordPress plugin that adds per-field conditional logic to the **classic Pro Elements Form widget**. It does not replace Pro Elements forms, Elementor Pro, or the form-action system.

- **Plugin slug / text domain:** `smart-form-conditions-pro-elements`
- **Version:** 1.0.0
- **License:** GPL-2.0-or-later

## Requirements

- PHP 7.4 or newer
- WordPress 6.8 or newer
- Elementor 4.0 or newer
- Pro Elements 4.3.0 or newer, with its Forms module and classic Form widget available

**Official Elementor Pro alone is not a substitute for Pro Elements.** This integration checks for the Pro Elements runtime and does not require an official Elementor Pro license. If a dependency or required form API is missing, the plugin disables its integration and shows an administrator notice rather than guessing at another implementation.

## What it does

- Adds conditional controls to supported rows in Pro Elements’ existing `form_fields` repeater.
- Supports **Show** or **Hide** actions and **ALL (AND)** / **ANY (OR)** condition groups.
- Evaluates nested dependencies, detects cycles, and fails open for cycle members, duplicate-ID targets, and targets with missing, unsupported, or incompatible rules rather than applying only part of an invalid rule group.
- Initializes each form independently, including forms inserted later by popups or other dynamic rendering.
- Hides the full field group, including its label and validation/help area.
- Makes hidden required fields non-required in the browser and suppresses only Pro Elements’ matching required-field error on the server. Visible required fields and unrelated validation errors remain intact.
- Offers **Clear Value** and **Retain Value** behavior. Clear also removes that hidden field from the server-side form record; Retain preserves its submitted value. Both policies ignore required validation while the field is hidden.
- Leaves Pro Elements’ configured form actions and notifications in control. It does not intercept form submission or add a second submit request.

## Quick start

1. Install the ZIP from **Plugins → Add New Plugin → Upload Plugin**, activate it, and check the requirements above.
2. Edit a page with Elementor and add or select a Pro Elements **Form** widget.
3. Give every field a unique, stable **ID** using letters, digits, and underscores only. Pro Elements uses these IDs in form submissions and shortcodes; duplicate IDs make references ambiguous.
4. Open a supported field’s **Conditional Logic** controls, enable conditions, choose Show/Hide, select ALL or ANY, and add one or more rules.
5. For a choice field, compare against the submitted option value (the text after `|` in Pro Elements’ `Label|value` option syntax), not its label.
6. Choose what happens to the field’s value while hidden, save, and test the live form as well as its normal Pro Elements actions.

See [INSTALLATION.md](INSTALLATION.md) for setup details, behavior, and compatibility notes.

## Supported field types and operators

The type of the **source field** determines the operator list. Only these classic Pro Elements field types can be conditional targets or sources:

- **Text-like:** Text, Email, Textarea, URL, Telephone, Date, Time
  - Equals, Does Not Equal, Contains, Does Not Contain, Starts With, Ends With, Is Empty, Is Not Empty
- **Number:**
  - Equals, Does Not Equal, Greater Than, Less Than, Greater Than or Equal, Less Than or Equal, Is Empty, Is Not Empty
- **Select and Radio:**
  - Equals, Does Not Equal, Contains, Does Not Contain, Is Empty, Is Not Empty, Is Selected, Is Not Selected
- **Checkbox:**
  - Equals, Does Not Equal, Contains, Does Not Contain, Is Empty, Is Not Empty, Is Checked, Is Unchecked, Is Selected, Is Not Selected
- **Acceptance:**
  - Is Empty, Is Not Empty, Is Checked, Is Unchecked

Text comparisons are trimmed and case-sensitive. Numeric comparisons require valid decimal numeric values; empty and malformed values do not become zero. Choice comparisons use submitted option values. For multi-value choices, “selected”/“contains” means the option value is among the selected values.

Upload, Hidden, HTML, Step, password, reCAPTCHA, and third-party custom field types are not supported by this version. They are not modified by the plugin.

## Compatibility and limitations

This release was built against source inspection of Pro Elements 4.3.0 and Elementor 4.0-era APIs. At runtime it verifies the registered `form` widget, control API, render hooks, Form_Record/Ajax_Handler methods, and the Forms module before enabling the integration. Source inspection and API-shaped tests are not the same as a real WordPress installation; **real-site verification has not been performed**. The full manual validation checklist and actual test results are in [TESTING.md](TESTING.md).

The plugin does not change Pro Elements’ form actions, notification configuration, styling, or submission endpoint. It does not provide conditional sections or page-level access control. Conditional visibility is a presentation/validation feature, not an authorization boundary: protect sensitive operations and data on the server independently.

The lightweight evaluator and a small stylesheet are enqueued on front-end pages after dependencies initialize. Loading the evaluator early lets it catch conditional forms that appear later in popups or dynamic/AJAX content; no third-party JavaScript library is added to the plugin.

## Files

- `smart-form-conditions-pro-elements.php` — plugin entry point and metadata
- `includes/` — dependency checks, controls, condition engine, asset loading, render and validation adapters
- `assets/` — frontend evaluator, editor helper, and small stylesheet
- `tests/` — JavaScript DOM/logic tests and PHP engine/integration-adapter tests
- `CHANGELOG.md` — release history

## Development tests

- JavaScript tests use Node’s built-in test runner plus the development-only `jsdom` dependency: `cd tests && npm ci && npm test`.
- PHP tests are standalone and can be run with a PHP CLI: `php tests/php/condition-engine-test.php`, `php tests/php/form-validation-test.php`, `php tests/php/form-integration-test.php`, `php tests/php/dependency-check-test.php`, and `php tests/php/condition-controls-test.php`. Adapter tests use API-shaped test doubles and do not boot WordPress.

Commands, environment, results, and items still requiring a real WordPress test are recorded in [TESTING.md](TESTING.md).
