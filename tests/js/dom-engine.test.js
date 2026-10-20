const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const engineSource = fs.readFileSync(path.join(__dirname, '../../assets/js/condition-engine.js'), 'utf8');

function wrapper(id, type, content, extra = '') {
	return `<div class="elementor-field-group" data-sfcpe-field-id="${id}" data-sfcpe-field-type="${type}" ${extra}>${content}</div>`;
}

function logic(action, source, operator, value, hiddenBehavior = 'clear') {
	return `data-sfcpe-logic='${JSON.stringify({
		enabled: true,
		action,
		match: 'all',
		hidden_behavior: hiddenBehavior,
		rules: [{ source, operator, value }],
	}).replaceAll("'", '&#39;')}'`;
}

async function openDom(html) {
	const dom = new JSDOM(html, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://example.test/' });
	dom.window.eval(engineSource);
	dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded', { bubbles: true }));
	await new Promise((resolve) => dom.window.setTimeout(resolve, 10));
	return dom;
}

test('frontend toggles the whole wrapper, clears values, and disables hidden required fields', async (t) => {
	const dom = await openDom(`
		<form class="elementor-form" id="test-form">
			${wrapper('account', 'select', '<select><option value="business" selected>Business</option><option value="personal">Personal</option></select>')}
			${wrapper('company', 'text', '<label>Company</label><input required value="Existing">', `data-sfcpe-required="1" ${logic('show', 'account', 'equals', 'business')}`)}
			${wrapper('visible_required', 'text', '<input required value="Required">')}
		</form>
	`);
	t.after(() => dom.window.close());

	const form = dom.window.document.querySelector('#test-form');
	const source = form.querySelector('[data-sfcpe-field-id="account"] select');
	const company = form.querySelector('[data-sfcpe-field-id="company"]');
	const companyInput = company.querySelector('input');

	assert.equal(company.hidden, false, 'show rule matches on load');
	assert.equal(companyInput.required, true);
	assert.equal(form.querySelector('[data-sfcpe-field-id="visible_required"] input').required, true, 'unconditional required fields are untouched');

	source.value = 'personal';
	source.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
	assert.equal(company.hidden, true);
	assert.equal(company.getAttribute('aria-hidden'), 'true');
	assert.equal(company.hasAttribute('inert'), true);
	assert.equal(companyInput.required, false, 'hidden required field cannot block native validation');
	assert.equal(companyInput.value, '', 'Clear policy erases the value on transition to hidden');

	source.value = 'business';
	source.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
	assert.equal(company.hidden, false);
	assert.equal(companyInput.required, true, 'required behavior is restored when shown');
});

test('Clear policy remains enforced after native form reset restores field defaults', async (t) => {
	const dom = await openDom(`
		<form class="elementor-form">
			${wrapper('account', 'select', '<select><option value="personal" selected>Personal</option><option value="business">Business</option></select>')}
			${wrapper('company', 'text', '<input value="Default company">', `data-sfcpe-required="0" ${logic('show', 'account', 'equals', 'business')}`)}
		</form>
	`);
	t.after(() => dom.window.close());
	const form = dom.window.document.querySelector('form');
	const source = form.querySelector('[data-sfcpe-field-id="account"] select');
	const company = form.querySelector('[data-sfcpe-field-id="company"]');
	const input = company.querySelector('input');

	assert.equal(company.hidden, true);
	assert.equal(input.value, '', 'initially hidden value is cleared');
	form.reset();
	await new Promise((resolve) => dom.window.setTimeout(resolve, 10));
	assert.equal(company.hidden, true);
	assert.equal(input.value, '', 'reset cannot restore a default while the field remains hidden');

	source.value = 'business';
	source.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
	assert.equal(company.hidden, false);
	assert.equal(input.value, '', 'the reset default was not retained when the field becomes visible');
});

test('Retain policy keeps hidden values while removing required validation', async (t) => {
	const dom = await openDom(`
		<form class="elementor-form">
			${wrapper('choice', 'radio', '<input type="radio" value="no" checked><input type="radio" value="yes">')}
			${wrapper('details', 'textarea', '<textarea required>Keep this</textarea>', `data-sfcpe-required="1" ${logic('show', 'choice', 'equals', 'yes', 'retain')}`)}
		</form>
	`);
	t.after(() => dom.window.close());

	const form = dom.window.document.querySelector('form');
	const source = form.querySelector('[data-sfcpe-field-id="choice"] input[value="yes"]');
	const details = form.querySelector('[data-sfcpe-field-id="details"]');
	const textarea = details.querySelector('textarea');

	assert.equal(details.hidden, true);
	assert.equal(textarea.value, 'Keep this');
	assert.equal(textarea.required, false);

	source.checked = true;
	source.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
	assert.equal(details.hidden, false);
	assert.equal(textarea.value, 'Keep this');
	assert.equal(textarea.required, true);
});

test('visible checkbox groups keep Pro Elements server-side required semantics', async (t) => {
	const dom = await openDom(`
		<form class="elementor-form">
			${wrapper('choice', 'radio', '<input type="radio" name="choice" value="yes" checked><input type="radio" name="choice" value="no">')}
			${wrapper('interests', 'checkbox', '<input type="checkbox" value="a"><input type="checkbox" value="b">', `data-sfcpe-required="1" ${logic('show', 'choice', 'equals', 'yes')}`)}
		</form>
	`);
	t.after(() => dom.window.close());
	const form = dom.window.document.querySelector('form');
	const source = form.querySelector('[data-sfcpe-field-id="choice"] input[value="no"]');
	const group = form.querySelector('[data-sfcpe-field-id="interests"]');
	const choices = Array.from(group.querySelectorAll('input'));

	assert.equal(group.hidden, false);
	assert.deepEqual(choices.map((input) => input.required), [false, false], 'the plugin must not require every checkbox option');
	source.checked = true;
	source.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
	assert.equal(group.hidden, true);
	assert.deepEqual(choices.map((input) => input.required), [false, false]);
});

test('independent forms and dynamically inserted popup forms initialize without duplicate handlers', async (t) => {
	const dom = await openDom(`
		<div id="page">
			<form class="elementor-form" id="first">
				${wrapper('choice', 'select', '<select><option value="yes" selected>Yes</option><option value="no">No</option></select>')}
				${wrapper('followup', 'text', '<input value="first">', `data-sfcpe-required="0" ${logic('show', 'choice', 'equals', 'yes')}`)}
			</form>
			<form class="elementor-form" id="second">
				${wrapper('choice', 'select', '<select><option value="no" selected>No</option><option value="yes">Yes</option></select>')}
				${wrapper('followup', 'text', '<input value="second">', `data-sfcpe-required="0" ${logic('show', 'choice', 'equals', 'yes')}`)}
			</form>
		</div>
	`);
	t.after(() => dom.window.close());

	const document = dom.window.document;
	const firstTarget = document.querySelector('#first [data-sfcpe-field-id="followup"]');
	const secondTarget = document.querySelector('#second [data-sfcpe-field-id="followup"]');
	assert.equal(firstTarget.hidden, false);
	assert.equal(secondTarget.hidden, true);

	const secondSource = document.querySelector('#second [data-sfcpe-field-id="choice"] select');
	secondSource.value = 'yes';
	secondSource.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
	assert.equal(firstTarget.hidden, false, 'another form with the same field IDs is unaffected');
	assert.equal(secondTarget.hidden, false);

	// A popup may insert its form after the initial scan. The observer should
	// initialize the new form, and an explicit second scan must not duplicate it.
	const popup = document.createElement('div');
	popup.className = 'elementor-popup';
	popup.innerHTML = `<form class="elementor-form" id="popup-form">
		${wrapper('choice', 'radio', '<input type="radio" value="no" checked><input type="radio" value="yes">')}
		${wrapper('followup', 'text', '<input value="popup">', `data-sfcpe-required="0" ${logic('show', 'choice', 'equals', 'yes')}`)}
	</form>`;
	document.body.appendChild(popup);
	await new Promise((resolve) => dom.window.setTimeout(resolve, 20));

	const popupForm = document.querySelector('#popup-form');
	const popupSource = popupForm.querySelector('[data-sfcpe-field-id="choice"] input[value="yes"]');
	const popupTarget = popupForm.querySelector('[data-sfcpe-field-id="followup"]');
	assert.equal(popupTarget.hidden, true);

	dom.window.SFCPEConditions.initInNode(popupForm);
	dom.window.SFCPEConditions.initInNode(popupForm);
	popupSource.checked = true;
	popupSource.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
	assert.equal(popupTarget.hidden, false);
});
