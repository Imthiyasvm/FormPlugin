# Test plan and actual results

## Verification boundary

No WordPress site, Elementor installation, or Pro Elements installation is available in this workspace. Therefore **no real-install, real-editor, or real-form-submission test has been run**. The plugin must still be installed and tested on a staging site that meets the requirements in [INSTALLATION.md](INSTALLATION.md).

Before implementation, the integration points were source-inspected in these checkouts:

- Pro Elements 4.3.0, commit `84c616b5a7599398af32ad9655739c0a3c0d27d0`: `pro-elements.php`, Form widget registration and form-field controls, `elementor-pro/forms/pre_render`, `elementor_pro/forms/render/item`, `Form_Record::validate()`, required-field errors, `remove_field()`, and form actions.
- Elementor source snapshot, commit `230a77d8e82ec0a3b5191857e64bffe18bc9af6b`: Elementor initialization, `elementor/widgets/register`, control-stack update APIs, render attributes, and editor repeater models/views.

This verifies what the code was written against, not whether the current installed versions on any specific site behave identically. Runtime guards disable the integration with an administrator notice if the expected APIs/hooks are not available.

## Automated checks run

Environment used for these checks:

- Node.js `v22.22.3`
- `jsdom` `26.1.0` installed from the development-only lockfile in `tests/`
- PHP WebAssembly CLI `7.4.33` (the declared minimum PHP version, but still not a native WordPress runtime)

Results:

| Check | Result |
|---|---|
| `node --check assets/js/condition-engine.js` | Passed |
| `node --check assets/js/editor-controls.js` | Passed |
| `cd tests && npm ci && npm test` | **17 tests passed, 0 failed** |
| PHP syntax check with PHP WASM for all 8 plugin PHP files and 5 PHP test files | **13 files passed, 0 syntax errors** |
| `tests/php/condition-engine-test.php` | **50 assertions passed** |
| `tests/php/form-validation-test.php` | **13 assertions passed** using API-shaped Form_Record/Ajax_Handler stubs |
| `tests/php/form-integration-test.php` | **12 assertions passed** using an Elementor-shaped widget double |
| `tests/php/dependency-check-test.php` | **3 assertions passed** using API-shaped Elementor/Pro Elements stubs |
| `tests/php/condition-controls-test.php` | **10 assertions passed** using Elementor-shaped control/widget doubles |
| **PHP test total** | **88 assertions passed** across the five standalone scripts |

JavaScript test coverage includes operator behavior, ALL/ANY, nested conditions, invalid/cyclic configurations, numeric validation, Clear/Retain transitions, native-reset behavior, hidden-required behavior, checkbox-group behavior, independent forms, dynamically inserted popup-like markup, and editor source/operator filtering. PHP coverage includes server-side visibility, default choice values, invalid references, cycles, required-error suppression, custom required messages, Clear/Retain record handling, and initial render attributes.

These tests exercise the plugin’s evaluator and adapters in isolation. They do not load WordPress, Pro Elements, Elementor, a real browser engine, or real form actions. PHP WASM 7.4.33 syntax and standalone tests did run, but no native PHP/WordPress runtime test was performed. No PHPCS/WordPress coding-standards run, PHPUnit run, or production browser compatibility run was available/performed.

## Manual real-site checklist — **not run**

Run on a disposable staging site with the exact minimum versions and again with the latest supported versions. Record the versions, theme, browser, and results before release.

### Installation and dependency behavior

- [ ] Activate with all required components present; confirm the plugin loads without PHP warnings/fatals.
- [ ] Test with Elementor missing/inactive, Pro Elements missing/inactive, Forms module disabled, and each component below/at the declared minimum. Confirm useful admin notices and no conditional hooks when checks fail.
- [ ] Confirm official Elementor Pro alone is not accepted as a substitute for Pro Elements.
- [ ] Test with official Elementor Pro installed alongside Pro Elements if that combination is supported by the site.

### Editor controls

- [ ] Open the classic Pro Elements Form widget and confirm controls appear only for supported field types.
- [ ] Add a condition, save, reload the editor, and confirm source, operator, value, Show/Hide, ALL/ANY, and hidden-value policy persist.
- [ ] Confirm source options use stable IDs/labels, exclude unsupported/self/cyclic choices, and update after fields are added, removed, renamed, or reordered.
- [ ] Confirm operator choices change by source field type and existing incompatible rules can be corrected.
- [ ] Test adding, removing, sorting, and duplicating rows in both repeaters.

### Frontend visibility and value policies

- [ ] Verify text, email, textarea, URL, tel, number, select, radio, checkbox, acceptance, date, and time fields as sources/targets.
- [ ] Verify every advertised operator, case-sensitive text matching, numeric decimal/exponent comparisons, blank values, option values after `|`, and multi-value choice rules.
- [ ] Verify Show and Hide with ALL and ANY using two or more rules.
- [ ] Verify nested dependencies, a deliberately introduced cycle, self-reference, duplicate IDs, deleted sources, unsupported field types, and incompatible operators.
- [ ] Verify initial defaults, including the first option of an unconfigured single Select, do not cause incorrect first-paint visibility.
- [ ] Verify a hidden group includes its label/help/error area, is removed from focus/accessibility navigation, and does not flash open with the site theme.
- [ ] Verify Clear empties hidden values in the browser and removes them from submissions; verify Retain keeps values in the normal Pro Elements record.
- [ ] Verify visible required fields retain normal validation and hidden required fields do not block submission for each supported field type.
- [ ] Verify a required checkbox group does not accidentally require every option.
- [ ] Verify unrelated custom validation failures still block submission.

### Form lifecycle and actions

- [ ] Test two forms on one page that reuse field IDs; confirm each is independent.
- [ ] Test initial page load, Elementor preview, popup open/close/reopen, AJAX/dynamic insertion, and native form reset.
- [ ] Test Pro Elements’ configured email, redirect, webhook/integration, and success/error notifications; confirm no duplicate submission or action.
- [ ] Test with script optimization/defer/combine enabled and disabled; review console/network output.
- [ ] Confirm no conditional behavior is applied to unsupported custom field renderers.

Do not mark this checklist complete based only on the automated tests above. Record actual outcomes and any incompatibilities here before publishing a release.
