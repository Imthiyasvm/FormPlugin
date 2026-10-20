# Installation and compatibility

## Requirements

| Component | Minimum |
|---|---:|
| PHP | 7.4 |
| WordPress | 6.8 |
| Elementor | 4.0 |
| Pro Elements | 4.3.0, including the Forms module and classic Form widget |

Pro Elements itself requires Elementor. **Official Elementor Pro by itself is not compatible and does not replace Pro Elements.** The plugin checks the `IS_PRO_ELEMENTS` runtime marker, Pro Elements’ Forms module, the registered `form` widget, and the specific control/render/validation APIs it uses. It does not require or validate an official Elementor Pro license.

The minimums reflect the inspected Pro Elements 4.3.0 source snapshot (commit `84c616b5a7599398af32ad9655739c0a3c0d27d0`) and Elementor source snapshot (commit `230a77d8e82ec0a3b5191857e64bffe18bc9af6b`). They are not a substitute for testing against the versions installed on your site.

## Install the ZIP

1. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
2. Select `smart-form-conditions-pro-elements-1.0.0.zip` and choose **Install Now**.
3. Activate **Smart Form Conditions for Pro Elements**.
4. If a dependency or API check fails, an administrator notice explains what is missing. Do not bypass that notice by editing Pro Elements source; update/enable the required components instead.

For manual installation, extract the ZIP and upload the `smart-form-conditions-pro-elements` directory to `wp-content/plugins/`, then activate it in WordPress.

## Configure conditions

1. Edit a page using Elementor and select the existing Pro Elements **Form** widget.
2. Open a supported field row in the **Form Fields** control.
3. Give fields stable, unique IDs. Pro Elements accepts letters, digits, and underscores; this plugin uses the same format. A condition refers to an ID, not a field’s visible label or repeater position. Avoid duplicate IDs, including fields that may be rendered in the same form.
4. Under **Conditional Logic**, enable conditions. Choose:
   - **Action:** Show or Hide the field when the rule group matches.
   - **Match Rules:** ALL (AND) requires every rule; ANY (OR) requires at least one rule.
   - **Conditions:** add one or more source-field/operator/value rules. The editor filters sources and operators by supported field type and prevents newly selecting a dependency that would create a cycle.
   - **Behavior When Hidden:** Clear Value or Retain Value.
5. Save the page and test the form on the front end. Submit through the configured Pro Elements actions and verify the intended notification, email, redirect, and other actions.

### Choice values

For Select, Radio, and Checkbox options, Pro Elements accepts `Label|value` lines. Conditions compare the **value** part. For example, for `Business customer|business`, enter `business`, not `Business customer`. Without a separator, the option text is both label and value.

### Operators

The source field controls the available operators:

- Text, Email, Textarea, URL, Telephone, Date, Time: Equals, Does Not Equal, Contains, Does Not Contain, Starts With, Ends With, Is Empty, Is Not Empty.
- Number: Equals, Does Not Equal, Greater Than, Less Than, Greater Than or Equal, Less Than or Equal, Is Empty, Is Not Empty.
- Select and Radio: Equals, Does Not Equal, Contains, Does Not Contain, Is Empty, Is Not Empty, Is Selected, Is Not Selected.
- Checkbox: Equals, Does Not Equal, Contains, Does Not Contain, Is Empty, Is Not Empty, Is Checked, Is Unchecked, Is Selected, Is Not Selected.
- Acceptance: Is Empty, Is Not Empty, Is Checked, Is Unchecked.

Text comparisons are trimmed and case-sensitive. Number comparisons require valid finite decimal values and do not coerce an empty string to zero. For multi-value choices, selected/contains comparisons match an option when it is among the selected values.

## Clear Value vs Retain Value

- **Clear Value** clears enabled inputs in the browser when the field transitions to hidden, and the server adapter removes that hidden field from the Pro Elements form record. The field’s configured value is not passed to normal form actions while hidden.
- **Retain Value** hides the field but keeps its current submitted value in the Pro Elements record. This may pass a hidden value to configured form actions.
- Both modes remove browser `required` while hidden and suppress only the matching Pro Elements required-field error on the server. Visible required fields retain standard browser and server validation. Other validation failures are preserved.

The server recomputes visibility from the saved form settings and submitted values; client-side hiding alone is not trusted. Values can still be manipulated by a visitor, so conditional logic must not be used to authorize a sensitive action.

## Rendering and form actions

The integration uses Pro Elements’ existing classic form renderer and validation lifecycle. It does not replace or intercept the form submission, actions, or notification system. It marks fields independently within each form, initializes forms on initial page load and after DOM insertion (including popup content), and recalculates on field input/change and form reset.

The lightweight evaluator and a tiny stylesheet are enqueued early on front-end pages so initially hidden groups respect the native `hidden` attribute and forms rendered later in popups/AJAX content can be initialized. Editor helpers load in the Elementor editor to populate field/operator choices.

## Unsupported field types and limitations

This version supports only Text, Email, Textarea, URL, Telephone (`tel`), Number, Select, Radio, Checkbox, Acceptance, Date, and Time. Upload, Hidden, HTML, Step, password, reCAPTCHA, and third-party custom field types are not condition sources or targets. A third-party renderer may change Pro Elements’ wrapper or hook behavior; compatibility is not assumed.

Cycle members, duplicate-ID targets, and targets with any missing, unsupported, self-referential, or incompatible rule remain visible rather than applying only part of an invalid rule group. In the editor, source choices exclude unsupported/self/cyclic candidates, but server-side checks remain authoritative.

Deactivating or removing the plugin disables its logic. Saved `sfcpe_*` controls remain in the page data; because the plugin no longer adds hiding attributes or validation handling, fields may become visible and normal Pro Elements behavior resumes. Review conditional forms before deactivation.

## Troubleshooting

- **No Conditional Logic controls:** Confirm the selected widget is the classic Pro Elements Form widget, the Forms module is enabled, all minimum versions are met, and the administrator notice reports no API mismatch. Also confirm the target field type is supported.
- **A source field is missing:** Check that its custom ID is valid and unique, its field type is supported, and it does not create a dependency cycle.
- **A choice rule does not match:** Compare the submitted option value after `|`, not the label. Comparisons are case-sensitive and trimmed.
- **A hidden field still appears:** Confirm the evaluator is not blocked by an optimization/security plugin, inspect the form wrapper’s `data-sfcpe-logic` attribute, and check browser console errors. The page should use the classic Pro Elements Form widget.
- **An email or other action is not sent:** Review Pro Elements’ own form validation/action errors. This plugin intentionally preserves unrelated validation errors and does not replace configured actions.
- **Popup/dynamic form behavior differs:** Retest with scripts deferred/combined features disabled and provide the Pro Elements, Elementor, WordPress, and PHP versions when reporting a compatibility issue.

## Data and uninstall

The plugin creates no custom database tables and sends no telemetry. Conditions are stored with the form widget’s settings in the normal Elementor document data. Deactivation does not automatically erase those settings; deleting the plugin’s settings is not required for data cleanup, but the saved controls will no longer be applied while the plugin is inactive.
