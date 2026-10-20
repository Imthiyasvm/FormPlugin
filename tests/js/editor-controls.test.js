const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const editorSource = fs.readFileSync(path.join(__dirname, '../../assets/js/editor-controls.js'), 'utf8');

class Model {
	constructor(attributes = {}) {
		this.attributes = attributes;
		this.events = new Map();
	}
	get(key) {
		return this.attributes[key];
	}
	set(key, value) {
		this.attributes[key] = value;
		this.emit('change');
	}
	on(eventNames, callback) {
		eventNames.split(/\s+/).forEach((name) => {
			if (!this.events.has(name)) this.events.set(name, new Set());
			this.events.get(name).add(callback);
		});
	}
	off(eventNames, callback) {
		eventNames.split(/\s+/).forEach((name) => this.events.get(name)?.delete(callback));
	}
	emit(name, ...args) {
		(this.events.get(name) || []).forEach((callback) => callback(...args));
	}
}

class Collection {
	constructor(models = []) {
		this.models = models;
		this.events = new Map();
	}
	each(callback) {
		this.models.forEach(callback);
	}
	on(eventNames, callback) {
		eventNames.split(/\s+/).forEach((name) => {
			if (!this.events.has(name)) this.events.set(name, new Set());
			this.events.get(name).add(callback);
		});
	}
	off(eventNames, callback) {
		eventNames.split(/\s+/).forEach((name) => this.events.get(name)?.delete(callback));
	}
}

function controlView(name, options = {}) {
	return {
		model: new Model({ name, options }),
		renders: 0,
		render() {
			this.renders++;
			return this;
		},
	};
}

function makeRule(source, operator) {
	const model = new Model({ sfcpe_source: source, sfcpe_operator: operator });
	return {
		model,
		children: new Collection([
			controlView('sfcpe_source'),
			controlView('sfcpe_operator'),
		]),
	};
}

function makeField(id, type, label, config = {}) {
	return new Model(Object.assign({ custom_id: id, field_type: type, field_label: label }, config));
}

function makeFieldRow(field, ruleRows = []) {
	const ruleCollection = new Collection(ruleRows);
	const rulesControl = controlView('sfcpe_conditions');
	rulesControl.children = ruleCollection;
	return {
		model: field,
		children: new Collection([rulesControl]),
	};
}

function delay(window) {
	return new Promise((resolve) => window.setTimeout(resolve, 20));
}

test('editor populates supported sources and type-specific operators while excluding cycles', async (t) => {
	const dom = new JSDOM('<div id="panel"></div>', { runScripts: 'outside-only', pretendToBeVisual: true });
	t.after(() => dom.window.close());
	const window = dom.window;

	const operatorLabels = {
		equals: 'Equals', not_equals: 'Does Not Equal', contains: 'Contains', not_contains: 'Does Not Contain',
		starts_with: 'Starts With', ends_with: 'Ends With', is_empty: 'Is Empty', is_not_empty: 'Is Not Empty',
		greater_than: 'Greater Than', less_than: 'Less Than', greater_than_or_equal: 'Greater Than or Equal',
		less_than_or_equal: 'Less Than or Equal', is_checked: 'Is Checked', is_unchecked: 'Is Unchecked',
		is_selected: 'Is Selected', is_not_selected: 'Is Not Selected',
	};
	window.SFCPEEditorControls = {
		selectField: 'Select a field',
		missingField: 'Missing or invalid field',
		unsupportedOperator: 'Not supported for this field type',
		operatorLabels,
		operatorsByType: {
			text: ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'is_empty', 'is_not_empty'],
			select: ['equals', 'not_equals', 'contains', 'not_contains', 'is_empty', 'is_not_empty', 'is_selected', 'is_not_selected'],
		},
	};
	const hooks = new Map();
	window.elementor = {
		hooks: {
			addAction(name, callback) {
				if (!hooks.has(name)) hooks.set(name, []);
				hooks.get(name).push(callback);
			},
		},
	};

	const plan = makeField('plan', 'select', 'Plan');
	const detailsRule = makeRule('plan', 'greater_than');
	const detailsRules = new Collection([detailsRule.model]);
	const details = makeField('details', 'text', 'Details', {
		sfcpe_enabled: 'yes',
		sfcpe_conditions: detailsRules,
	});
	const taxRule = makeRule('details', 'equals');
	const taxRules = new Collection([taxRule.model]);
	const tax = makeField('tax', 'text', 'Tax ID', {
		sfcpe_enabled: 'yes',
		sfcpe_conditions: taxRules,
	});
	const file = makeField('upload_file', 'upload', 'Upload');
	const fields = new Collection([plan, details, tax, file]);
	const settings = new Model({ form_fields: fields });
	const widgetModel = new Model({ widgetType: 'form', settings });

	const rows = new Map([
		[plan, makeFieldRow(plan)],
		[details, makeFieldRow(details, [detailsRule])],
		[tax, makeFieldRow(tax, [taxRule])],
		[file, makeFieldRow(file)],
	]);
	const outerRepeater = { children: { findByModel: (field) => rows.get(field) || null } };
	const panelView = {
		el: window.document.querySelector('#panel'),
		getControlView(name) {
			return name === 'form_fields' ? outerRepeater : null;
		},
	};
	const panel = { getCurrentPageView: () => panelView };

	window.eval(editorSource);
	assert.equal(hooks.get('panel/open_editor/widget').length, 1, 'generic editor hook is registered once');
	assert.equal(hooks.get('panel/open_editor/widget/form').length, 1, 'Form-specific editor hook is registered once');
	hooks.get('panel/open_editor/widget').forEach((callback) => callback(panel, widgetModel));
	hooks.get('panel/open_editor/widget/form').forEach((callback) => callback(panel, widgetModel));
	await delay(window);

	const detailsRuleSource = detailsRule.children.models[0].model.get('options');
	assert.equal(detailsRuleSource.plan, 'Plan (plan)');
	assert.equal(Object.prototype.hasOwnProperty.call(detailsRuleSource, 'details'), false, 'a field cannot depend on itself');
	assert.equal(Object.prototype.hasOwnProperty.call(detailsRuleSource, 'tax'), false, 'a candidate that creates a dependency cycle is excluded');
	assert.equal(Object.prototype.hasOwnProperty.call(detailsRuleSource, 'upload_file'), false, 'unsupported source field types are excluded');

	const detailsOperatorOptions = detailsRule.children.models[1].model.get('options');
	assert.equal(detailsOperatorOptions.greater_than, 'Not supported for this field type — Greater Than', 'an existing invalid operator remains visible for correction');
	assert.equal(detailsOperatorOptions.contains, 'Contains');

	const taxOperatorOptions = taxRule.children.models[1].model.get('options');
	assert.equal(taxOperatorOptions.contains, 'Contains', 'text source receives text operators');
	assert.equal(Object.prototype.hasOwnProperty.call(taxOperatorOptions, 'greater_than'), false, 'text source does not offer numeric operators');
});
