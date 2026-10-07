(function () {
    'use strict';

    var root = document.querySelector('[data-pform-editor]');
    if (!root) {
        return;
    }

    var definitionNode = document.getElementById('pform-editor-definition');
    var hidden = document.getElementById('pform-definition-json');
    var state;

    try {
        state = JSON.parse(definitionNode.textContent || '{}');
    } catch (error) {
        state = {};
    }

    state.sections = Array.isArray(state.sections) ? state.sections : [];

    var types = [
        ['text', 'Text'],
        ['email', 'Email'],
        ['tel', 'Phone'],
        ['date', 'Date'],
        ['textarea', 'Long text'],
        ['radio', 'Radio choices'],
        ['checkboxes', 'Checkbox choices'],
        ['consent', 'Consent checkbox'],
        ['repeater', 'Repeatable group']
    ];
    var widths = [
        ['full', 'Full width'],
        ['half', 'Half width'],
        ['third', 'One third'],
        ['two-thirds', 'Two thirds'],
        ['phone-wide', 'Phone wide'],
        ['ministries', 'Ministry choices layout'],
        ['other-interests', 'Long notes layout']
    ];

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function slug(value, fallback) {
        var result = String(value || '').toLowerCase()
            .replace(/[^a-z0-9_-]+/g, '-')
            .replace(/^-+|-+$/g, '');
        return result || fallback;
    }

    function optionTags(list, current) {
        return list.map(function (item) {
            return '<option value="' + esc(item[0]) + '"' + (item[0] === current ? ' selected' : '') + '>' + esc(item[1]) + '</option>';
        }).join('');
    }

    function optionsText(options) {
        if (!options || typeof options !== 'object') {
            return '';
        }
        return Object.keys(options).map(function (key) {
            return key + '|' + options[key];
        }).join('\n');
    }

    function parseOptions(value) {
        var out = {};
        String(value || '').split(/\r?\n/).forEach(function (line) {
            line = line.trim();
            if (!line) {
                return;
            }
            var split = line.indexOf('|');
            var key = split >= 0 ? line.slice(0, split).trim() : slug(line, 'option');
            var label = split >= 0 ? line.slice(split + 1).trim() : line;
            key = slug(key, 'option');
            if (label) {
                out[key] = label;
            }
        });
        return out;
    }

    function conditionSources() {
        var ids = [];
        state.sections.forEach(function (section) {
            (section.fields || []).forEach(function (field) {
                if (field.id && field.type === 'radio') {
                    ids.push(field.id);
                }
            });
        });
        return ids;
    }

    function conditionSelect(target) {
        var current = target.condition && target.condition.field ? target.condition.field : '';
        var html = '<option value="">Always show</option>';
        conditionSources().forEach(function (id) {
            html += '<option value="' + esc(id) + '"' + (id === current ? ' selected' : '') + '>' + esc(id) + '</option>';
        });
        return html;
    }

    function blankField(index) {
        return {
            id: 'field-' + index,
            type: 'text',
            label: 'New Field',
            required: false,
            width: 'full',
            max_length: 180,
            autocomplete: ''
        };
    }

    function fieldHtml(field, sectionIndex, fieldIndex, nested) {
        var prefix = nested ? 'n' : 'f';
        var data = ' data-section="' + sectionIndex + '" data-field="' + fieldIndex + '"' + (nested ? ' data-nested="' + nested.parent + '"' : '');
        var showOptions = field.type === 'radio' || field.type === 'checkboxes';
        var showTextSettings = ['text', 'email', 'tel', 'textarea'].indexOf(field.type) >= 0;
        var showDate = field.type === 'date';
        var showRepeater = field.type === 'repeater' && !nested;
        var condition = field.condition || {};

        var html = '<div class="pform-builder-field"' + data + '>';
        html += '<div class="pform-builder-field__top">';
        html += '<span class="dashicons dashicons-move pform-builder-handle" aria-hidden="true"></span>';
        html += '<strong>' + esc(field.label || 'Field') + '</strong>';
        html += '<span class="pform-builder-field__type">' + esc(field.type || 'text') + '</span>';
        html += '<div class="pform-builder-actions">';
        html += '<button type="button" class="button button-small" data-move="up">↑</button>';
        html += '<button type="button" class="button button-small" data-move="down">↓</button>';
        html += '<button type="button" class="button-link-delete" data-remove-field>Remove</button>';
        html += '</div></div>';

        html += '<div class="pform-builder-field__settings">';
        html += '<label>Label<input type="text" data-prop="label" value="' + esc(field.label || '') + '"></label>';
        html += '<label>Field ID<input type="text" data-prop="id" value="' + esc(field.id || '') + '"' + (nested ? '' : '') + '></label>';
        html += '<label>Type<select data-prop="type">' + optionTags(types.filter(function (item) { return !nested || item[0] !== 'repeater'; }), field.type) + '</select></label>';
        html += '<label>Width<select data-prop="width">' + optionTags(widths, field.width || 'full') + '</select></label>';
        html += '<label class="pform-builder-check"><input type="checkbox" data-prop="required"' + (field.required ? ' checked' : '') + '> Required</label>';

        if (showTextSettings) {
            html += '<label>Maximum characters<input type="number" min="1" max="10000" data-prop="max_length" value="' + esc(field.max_length || (field.type === 'textarea' ? 2000 : 180)) + '"></label>';
            if (field.type !== 'textarea') {
                html += '<label>Autocomplete hint<input type="text" data-prop="autocomplete" value="' + esc(field.autocomplete || '') + '" placeholder="name, email, tel…"></label>';
            }
        }
        if (showDate) {
            html += '<label class="pform-builder-check"><input type="checkbox" data-prop="not_future"' + (field.not_future ? ' checked' : '') + '> Do not allow future dates</label>';
        }
        if (showOptions) {
            html += '<label class="pform-builder-wide">Choices <span class="description">one per line: value|Label</span><textarea rows="4" data-prop="options">' + esc(optionsText(field.options)) + '</textarea></label>';
        }
        if (!nested) {
            html += '<label>Show when field<select data-prop="condition_field">' + conditionSelect(field) + '</select></label>';
            html += '<label>Equals value<input type="text" data-prop="condition_equals" value="' + esc(condition.equals || '') + '" placeholder="choice value"></label>';
        }

        if (showRepeater) {
            html += '<div class="pform-builder-repeater">';
            html += '<label>Item label<input type="text" data-prop="item_label" value="' + esc(field.item_label || 'Item') + '"></label>';
            html += '<label>Add button label<input type="text" data-prop="add_label" value="' + esc(field.add_label || 'Add Another') + '"></label>';
            html += '<label>Minimum items<input type="number" min="0" max="20" data-prop="min_items" value="' + esc(field.min_items || 0) + '"></label>';
            html += '<label>Maximum items<input type="number" min="1" max="50" data-prop="max_items" value="' + esc(field.max_items || 10) + '"></label>';
            html += '<div class="pform-builder-wide"><h4>Fields inside each item</h4>';
            html += '<div data-repeater-fields>';
            (field.fields || []).forEach(function (child, childIndex) {
                html += fieldHtml(child, sectionIndex, childIndex, {parent: fieldIndex});
            });
            html += '</div><button type="button" class="button" data-add-repeater-field>Add Repeater Field</button></div></div>';
        }

        html += '</div></div>';
        return html;
    }

    function sectionHtml(section, sectionIndex) {
        var html = '<section class="pform-builder-section" data-section="' + sectionIndex + '">';
        html += '<div class="pform-builder-section__heading">';
        html += '<span class="dashicons dashicons-move pform-builder-handle" aria-hidden="true"></span>';
        html += '<h2>' + esc(section.title || 'Section') + '</h2>';
        html += '<div class="pform-builder-actions">';
        html += '<button type="button" class="button" data-section-move="up">Move Up</button>';
        html += '<button type="button" class="button" data-section-move="down">Move Down</button>';
        html += '<button type="button" class="button-link-delete" data-remove-section>Remove Section</button>';
        html += '</div></div>';
        html += '<div class="pform-builder-section__settings">';
        html += '<label>Section title<input type="text" data-section-prop="title" value="' + esc(section.title || '') + '"></label>';
        html += '<label>Section ID<input type="text" data-section-prop="id" value="' + esc(section.id || '') + '"></label>';
        html += '<label class="pform-builder-wide">Description<textarea rows="2" data-section-prop="description">' + esc(section.description || '') + '</textarea></label>';
        html += '<label>Show section when field<select data-section-prop="condition_field">' + conditionSelect(section) + '</select></label>';
        html += '<label>Equals value<input type="text" data-section-prop="condition_equals" value="' + esc(section.condition && section.condition.equals ? section.condition.equals : '') + '" placeholder="choice value"></label>';
        html += '</div>';
        html += '<div class="pform-builder-fields">';
        (section.fields || []).forEach(function (field, fieldIndex) {
            html += fieldHtml(field, sectionIndex, fieldIndex, null);
        });
        html += '</div>';
        html += '<button type="button" class="button" data-add-field>Add Field</button>';
        html += '</section>';
        return html;
    }

    function render() {
        root.innerHTML = state.sections.map(sectionHtml).join('');
        hidden.value = JSON.stringify(state.sections);
    }

    function syncField(target) {
        var fieldEl = target.closest('.pform-builder-field');
        if (!fieldEl) {
            return;
        }
        var sectionIndex = parseInt(fieldEl.getAttribute('data-section'), 10);
        var fieldIndex = parseInt(fieldEl.getAttribute('data-field'), 10);
        var nestedParent = fieldEl.getAttribute('data-nested');
        var field;

        if (nestedParent !== null) {
            field = state.sections[sectionIndex].fields[parseInt(nestedParent, 10)].fields[fieldIndex];
        } else {
            field = state.sections[sectionIndex].fields[fieldIndex];
        }

        var prop = target.getAttribute('data-prop');
        if (!prop) {
            return;
        }

        if (prop === 'required' || prop === 'not_future') {
            field[prop] = target.checked;
        } else if (prop === 'max_length' || prop === 'min_items' || prop === 'max_items') {
            field[prop] = parseInt(target.value, 10) || 0;
        } else if (prop === 'options') {
            field.options = parseOptions(target.value);
        } else if (prop === 'condition_field') {
            field.condition = field.condition || {};
            field.condition.field = target.value;
            if (!target.value) {
                delete field.condition;
            }
        } else if (prop === 'condition_equals') {
            field.condition = field.condition || {};
            field.condition.equals = target.value;
            if (!field.condition.field && !field.condition.equals) {
                delete field.condition;
            }
        } else {
            field[prop] = target.value;
        }

        hidden.value = JSON.stringify(state.sections);

        if (prop === 'label' || prop === 'type') {
            render();
        }
    }

    root.addEventListener('input', function (event) {
        if (event.target.hasAttribute('data-prop')) {
            syncField(event.target);
        }
        var sectionProp = event.target.getAttribute('data-section-prop');
        if (sectionProp) {
            var sectionEl = event.target.closest('.pform-builder-section');
            var index = parseInt(sectionEl.getAttribute('data-section'), 10);
            var section = state.sections[index];
            if (sectionProp === 'condition_field') {
                section.condition = section.condition || {};
                section.condition.field = event.target.value;
                if (!event.target.value) {
                    delete section.condition;
                }
            } else if (sectionProp === 'condition_equals') {
                section.condition = section.condition || {};
                section.condition.equals = event.target.value;
                if (!section.condition.field && !section.condition.equals) {
                    delete section.condition;
                }
            } else {
                section[sectionProp] = event.target.value;
            }
            hidden.value = JSON.stringify(state.sections);
            if (sectionProp === 'title') {
                sectionEl.querySelector('h2').textContent = event.target.value || 'Section';
            }
        }
    });

    root.addEventListener('change', function (event) {
        if (event.target.hasAttribute('data-prop')) {
            syncField(event.target);
            if (event.target.getAttribute('data-prop') === 'type') {
                render();
            }
        }
        if (event.target.hasAttribute('data-section-prop')) {
            event.target.dispatchEvent(new Event('input', {bubbles: true}));
        }
    });

    root.addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button) {
            return;
        }
        var sectionEl = button.closest('.pform-builder-section');
        var sectionIndex = sectionEl ? parseInt(sectionEl.getAttribute('data-section'), 10) : -1;

        if (button.hasAttribute('data-add-field')) {
            var fields = state.sections[sectionIndex].fields || (state.sections[sectionIndex].fields = []);
            fields.push(blankField(fields.length + 1));
            render();
            return;
        }
        if (button.hasAttribute('data-remove-section')) {
            if (window.confirm('Remove this section and all of its fields?')) {
                state.sections.splice(sectionIndex, 1);
                render();
            }
            return;
        }
        if (button.hasAttribute('data-section-move')) {
            var sectionDirection = button.getAttribute('data-section-move');
            var sectionTarget = sectionDirection === 'up' ? sectionIndex - 1 : sectionIndex + 1;
            if (sectionTarget >= 0 && sectionTarget < state.sections.length) {
                var movedSection = state.sections.splice(sectionIndex, 1)[0];
                state.sections.splice(sectionTarget, 0, movedSection);
                render();
            }
            return;
        }

        var fieldEl = button.closest('.pform-builder-field');
        if (!fieldEl) {
            return;
        }
        var fieldIndex = parseInt(fieldEl.getAttribute('data-field'), 10);
        var nestedParent = fieldEl.getAttribute('data-nested');
        var fieldsArray = nestedParent !== null
            ? state.sections[sectionIndex].fields[parseInt(nestedParent, 10)].fields
            : state.sections[sectionIndex].fields;

        if (button.hasAttribute('data-remove-field')) {
            if (window.confirm('Remove this field? Existing published submissions will keep their historical snapshot.')) {
                fieldsArray.splice(fieldIndex, 1);
                render();
            }
            return;
        }
        if (button.hasAttribute('data-move')) {
            var direction = button.getAttribute('data-move');
            var targetIndex = direction === 'up' ? fieldIndex - 1 : fieldIndex + 1;
            if (targetIndex >= 0 && targetIndex < fieldsArray.length) {
                var moved = fieldsArray.splice(fieldIndex, 1)[0];
                fieldsArray.splice(targetIndex, 0, moved);
                render();
            }
            return;
        }
        if (button.hasAttribute('data-add-repeater-field')) {
            var parentField = state.sections[sectionIndex].fields[fieldIndex];
            parentField.fields = parentField.fields || [];
            parentField.fields.push(blankField(parentField.fields.length + 1));
            render();
        }
    });

    document.querySelector('[data-add-section]').addEventListener('click', function () {
        var number = state.sections.length + 1;
        state.sections.push({
            id: 'section-' + number,
            title: 'New Section',
            description: '',
            fields: []
        });
        render();
    });

    var form = document.querySelector('[data-pform-editor-form]');
    if (form) {
        form.addEventListener('submit', function () {
            hidden.value = JSON.stringify(state.sections);
        });
    }

    render();
}());
