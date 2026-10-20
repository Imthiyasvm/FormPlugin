const test = require('node:test');
const assert = require('node:assert/strict');
const engine = require('../../assets/js/condition-engine.js');

function field(id, type, rules, options = {}) {
	return {
		id,
		type,
		conditions: Object.assign(
			{
				enabled: true,
				action: 'show',
				match: 'all',
				hidden_behavior: 'clear',
				rules: rules || [],
			},
			options
		),
	};
}

function rule(source, operator, value = '') {
	return { source, operator, value };
}

test('basic show and hide conditions evaluate without changing unrelated fields', () => {
	const fields = [
		{ id: 'account', type: 'select' },
		field('company', 'text', [rule('account', 'equals', 'business')]),
		field('personal_note', 'text', [rule('account', 'equals', 'business')], { action: 'hide' }),
	];
	const result = engine.calculateVisibility(fields, { account: 'business' });

	assert.equal(result.visibility.account, true);
	assert.equal(result.visibility.company, true);
	assert.equal(result.visibility.personal_note, false);
});

test('ALL and ANY match modes implement AND and OR', () => {
	const fields = [
		{ id: 'account', type: 'select' },
		{ id: 'country', type: 'select' },
		field('all_target', 'text', [
			rule('account', 'equals', 'business'),
			rule('country', 'equals', 'UAE'),
		]),
		field('any_target', 'text', [
			rule('account', 'equals', 'business'),
			rule('country', 'equals', 'UAE'),
		], { match: 'any' }),
	];

	assert.equal(engine.calculateVisibility(fields, { account: 'business', country: 'UK' }).visibility.all_target, false);
	assert.equal(engine.calculateVisibility(fields, { account: 'business', country: 'UK' }).visibility.any_target, true);
	assert.equal(engine.calculateVisibility(fields, { account: 'personal', country: 'UK' }).visibility.any_target, false);
	assert.equal(engine.calculateVisibility(fields, { account: 'business', country: 'UAE' }).visibility.all_target, true);
});

test('text operators cover equals, not-equals, contains, not-contains, starts, ends, and empty', () => {
	const checks = [
		['equals', 'Hello', true],
		['not_equals', 'Other', true],
		['contains', 'ell', true],
		['not_contains', 'xyz', true],
		['starts_with', 'He', true],
		['ends_with', 'lo', true],
		['is_empty', '', false],
		['is_not_empty', '', true],
	];

	checks.forEach(([operator, expected, answer]) => {
		assert.equal(engine.evaluateRule('  Hello  ', 'text', operator, expected), answer, operator);
	});
	assert.equal(engine.evaluateRule(' \t ', 'text', 'is_empty', ''), true);
	assert.equal(engine.evaluateRule('hello', 'text', 'contains', 'HEL'), false, 'text matching is case-sensitive');
	assert.equal(engine.evaluateRule('hello', 'text', 'starts_with', ''), false, 'empty prefixes are rejected');
});

test('numeric operators require numeric operands and do not coerce an empty value to zero', () => {
	const checks = [
		['equals', '12.5', true],
		['equals', '12.50', true],
		['not_equals', '13', true],
		['greater_than', '12', true],
		['less_than', '13', true],
		['greater_than_or_equal', '12.5', true],
		['less_than_or_equal', '12.5', true],
		['is_empty', '', false],
		['is_not_empty', '', true],
	];

	checks.forEach(([operator, expected, answer]) => {
		assert.equal(engine.evaluateRule('12.5', 'number', operator, expected), answer, operator);
	});
	assert.equal(engine.evaluateRule('', 'number', 'greater_than', '0'), false);
	assert.equal(engine.evaluateRule('not-a-number', 'number', 'less_than', '5'), false);
	assert.equal(engine.evaluateRule('5', 'number', 'greater_than', 'five'), false);
	assert.equal(engine.evaluateRule('5', 'number', 'not_equals', 'five'), false, 'invalid numeric comparisons fail closed');
	assert.equal(engine.evaluateRule('0x10', 'number', 'equals', '16'), false, 'hexadecimal strings are not numeric form values');
});

test('choice operators work with radio, select, and multiple checkbox selections', () => {
	assert.equal(engine.evaluateRule('business', 'radio', 'equals', 'business'), true);
	assert.equal(engine.evaluateRule(['web', 'seo'], 'checkbox', 'contains', 'seo'), true);
	assert.equal(engine.evaluateRule(['web', 'seo'], 'select', 'is_selected', 'web'), true);
	assert.equal(engine.evaluateRule(['web', 'seo'], 'checkbox', 'is_not_selected', 'email'), true);
	assert.equal(engine.evaluateRule(['web', 'seo'], 'checkbox', 'not_contains', 'seo'), false);
	assert.equal(engine.evaluateRule(['', '  '], 'select', 'is_empty', ''), true);
});

test('checked and unchecked handle acceptance and checkbox values including zero', () => {
	assert.equal(engine.evaluateRule(['on'], 'acceptance', 'is_checked', ''), true);
	assert.equal(engine.evaluateRule('', 'acceptance', 'is_unchecked', ''), true);
	assert.equal(engine.evaluateRule(['0'], 'checkbox', 'is_checked', ''), true);
	assert.equal(engine.evaluateRule([], 'checkbox', 'is_unchecked', ''), true);
	assert.equal(engine.evaluateRule(['yes'], 'radio', 'is_checked', ''), false);
});

test('nested field dependencies recalculate through visible ancestors', () => {
	const fields = [
		{ id: 'account', type: 'select' },
		field('company', 'text', [rule('account', 'equals', 'business')]),
		field('tax_id', 'text', [rule('company', 'equals', 'ready')]),
	];

	const visible = engine.calculateVisibility(fields, { account: 'business', company: 'ready' });
	assert.equal(visible.visibility.company, true);
	assert.equal(visible.visibility.tax_id, true);

	const hidden = engine.calculateVisibility(fields, { account: 'personal', company: 'ready' });
	assert.equal(hidden.visibility.company, false);
	assert.equal(hidden.visibility.tax_id, false, 'a hidden source cannot activate a downstream Show rule');
});

test('cyclic dependencies are detected and all cycle members fail open as visible', () => {
	const fields = [
		field('first', 'text', [rule('second', 'equals', 'yes')]),
		field('second', 'text', [rule('first', 'equals', 'yes')]),
		field('third', 'text', [rule('first', 'is_not_empty', '')]),
	];
	const result = engine.calculateVisibility(fields, { first: '', second: '' });

	assert.deepEqual(new Set(result.cycles), new Set(['first', 'second']));
	assert.equal(result.visibility.first, true);
	assert.equal(result.visibility.second, true);
	assert.equal(result.visibility.third, false);
});

test('self references, missing sources, unsupported sources, and incompatible operators fail open', () => {
	const fields = [
		{ id: 'text_source', type: 'text' },
		{ id: 'unsupported', type: 'upload' },
		field('self_target', 'text', [rule('self_target', 'equals', 'x')]),
		field('missing_target', 'text', [rule('deleted_source', 'equals', 'x')]),
		field('unsupported_target', 'text', [rule('unsupported', 'equals', 'x')]),
		field('wrong_operator_target', 'text', [rule('text_source', 'greater_than', '5')]),
	];
	const result = engine.calculateVisibility(fields, {});

	['self_target', 'missing_target', 'unsupported_target', 'wrong_operator_target'].forEach((id) => {
		assert.equal(result.visibility[id], true, id);
	});
});

test('invalid references fail open instead of applying only part of an ALL or ANY rule group', () => {
	const fields = [
		{ id: 'choice', type: 'select' },
		field('all_target', 'text', [rule('choice', 'equals', 'yes'), rule('deleted', 'equals', 'x')]),
		field('any_target', 'text', [rule('choice', 'equals', 'yes'), rule('deleted', 'equals', 'x')], { match: 'any' }),
		field('any_hide_target', 'text', [rule('choice', 'equals', 'yes'), rule('deleted', 'equals', 'x')], { match: 'any', action: 'hide' }),
	];
	const result = engine.calculateVisibility(fields, { choice: 'yes' });
	assert.equal(result.visibility.all_target, true, 'an invalid condition keeps the target visible instead of applying a partial ALL group');
	assert.equal(result.visibility.any_target, true);
	assert.equal(result.visibility.any_hide_target, true, 'invalid ANY rules do not unexpectedly hide a target');
});

test('field identifiers from separate forms remain independent', () => {
	const firstForm = engine.calculateVisibility([
		{ id: 'choice', type: 'radio' },
		field('followup', 'text', [rule('choice', 'equals', 'yes')]),
	], { choice: 'yes' });
	const secondForm = engine.calculateVisibility([
		{ id: 'choice', type: 'radio' },
		field('followup', 'text', [rule('choice', 'equals', 'yes')]),
	], { choice: 'no' });

	assert.equal(firstForm.visibility.followup, true);
	assert.equal(secondForm.visibility.followup, false);
});
