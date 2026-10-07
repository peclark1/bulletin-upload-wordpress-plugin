(function () {
    'use strict';

    var data = window.PFORM_EDITOR_DATA || {};
    var state = JSON.parse(JSON.stringify(data.definition || {}));
    var root = document.getElementById('pform-form-editor');
    var form = document.getElementById('pform-form-editor-form');
    var jsonInput = document.getElementById('pform-definition-json');
    var operationInput = document.getElementById('pform-editor-operation');
    var counter = 1;
    var dragState = null;

    if (!root || !form) {
        return;
    }

    var typeLabels = {
        text: 'Text',
        email: 'Email',
        tel: 'Phone',
        date: 'Date',
        textarea: 'Long Text',
        radio: 'Multiple Choice',
        checkboxes: 'Checkboxes',
        consent: 'Consent Checkbox',
        repeater: 'Repeatable Group'
    };

    var widths = {
        full: 'Full width',
        half: 'Half width',
        third: 'One third',
        'two-thirds': 'Two thirds',
        'phone-wide': 'Half width (phone layout)',
        ministries: 'Full width (ministry choices)',
        'other-interests': 'Full width (large notes)'
    };

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    function input(value, onChange, type) {
        var node = document.createElement('input');
        node.type = type || 'text';
        node.value = value || '';
        node.addEventListener('change', function () {
            onChange(node.value);
        });
        return node;
    }

    function textarea(value, onChange, rows) {
        var node = document.createElement('textarea');
        node.rows = rows || 3;
        node.value = value || '';
        node.addEventListener('change', function () {
            onChange(node.value);
        });
        return node;
    }

    function checkbox(value, onChange) {
        var node = document.createElement('input');
        node.type = 'checkbox';
        node.checked = !!value;
        node.addEventListener('change', function () {
            onChange(node.checked);
        });
        return node;
    }

    function select(value, choices, onChange) {
        var node = document.createElement('select');
        Object.keys(choices).forEach(function (key) {
            var option = document.createElement('option');
            option.value = key;
            option.textContent = choices[key];
            option.selected = String(value || '') === String(key);
            node.appendChild(option);
        });
        node.addEventListener('change', function () {
            onChange(node.value);
        });
        return node;
    }

    function fieldRow(labelText, control, help) {
        var wrap = el('div', 'pform-editor-control');
        var label = el('label', '', labelText);
        label.appendChild(control);
        wrap.appendChild(label);
        if (help) {
            wrap.appendChild(el('p', 'description', help));
        }
        return wrap;
    }

    function slugify(value) {
        return String(value || '')
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'field-' + counter++;
    }

    function topLevelFields() {
        var fields = [];
        (state.sections || []).forEach(function (section) {
            (section.fields || []).forEach(function (field) {
                fields.push(field);
            });
        });
        return fields;
    }

    function uniqueFieldId(base, current) {
        var candidate = slugify(base);
        var used = {};
        topLevelFields().forEach(function (field) {
            if (field !== current) {
                used[field.id] = true;
            }
        });
        var original = candidate;
        var suffix = 2;
        while (used[candidate]) {
            candidate = original + '-' + suffix++;
        }
        return candidate;
    }

    function uniqueNestedId(fields, base, current) {
        var candidate = slugify(base);
        var used = {};
        fields.forEach(function (field) {
            if (field !== current) {
                used[field.id] = true;
            }
        });
        var original = candidate;
        var suffix = 2;
        while (used[candidate]) {
            candidate = original + '-' + suffix++;
        }
        return candidate;
    }

    function newField(nested, fields) {
        var id = nested ? uniqueNestedId(fields || [], 'field-' + counter++, null) : uniqueFieldId('field-' + counter++, null);
        return {
            id: id,
            type: 'text',
            label: 'New Field',
            required: false,
            width: 'full',
            max_length: 180,
            autocomplete: '',
            _autoId: true
        };
    }

    function newSection() {
        var id = 'section-' + counter++;
        return {
            id: id,
            title: 'New Section',
            description: '',
            fields: []
        };
    }

    function optionLines(options) {
        return Object.keys(options || {}).map(function (value) {
            return value + ' | ' + options[value];
        }).join('\n');
    }

    function parseOptionLines(value) {
        var result = {};
        String(value || '').split(/\r?\n/).forEach(function (line) {
            line = line.trim();
            if (!line) {
                return;
            }
            var parts = line.split('|');
            var stored;
            var label;
            if (parts.length > 1) {
                stored = slugify(parts.shift().trim());
                label = parts.join('|').trim();
            } else {
                label = line;
                stored = slugify(label);
            }
            if (!label) {
                return;
            }
            var base = stored;
            var suffix = 2;
            while (result[stored]) {
                stored = base + '-' + suffix++;
            }
            result[stored] = label;
        });
        return result;
    }

    function move(list, from, to) {
        if (to < 0 || to >= list.length || from === to) {
            return;
        }
        var item = list.splice(from, 1)[0];
        list.splice(to, 0, item);
        render();
    }

    function drag(card, kind, index, parentKey, mover) {
        card.draggable = true;
        card.addEventListener('dragstart', function (event) {
            event.stopPropagation();
            dragState = {kind: kind, index: index, parentKey: parentKey};
            card.classList.add('is-dragging');
        });
        card.addEventListener('dragend', function (event) {
            event.stopPropagation();
            dragState = null;
            card.classList.remove('is-dragging');
        });
        card.addEventListener('dragover', function (event) {
            if (dragState && dragState.kind === kind && dragState.parentKey === parentKey) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
        card.addEventListener('drop', function (event) {
            if (!dragState || dragState.kind !== kind || dragState.parentKey !== parentKey) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            mover(dragState.index, index);
            dragState = null;
        });
    }

    function button(text, className, handler) {
        var node = el('button', className || 'button', text);
        node.type = 'button';
        node.addEventListener('click', handler);
        return node;
    }

    function renderGeneral() {
        var panel = el('section', 'pform-editor-panel');
        panel.appendChild(el('h2', '', 'Form Settings'));

        var grid = el('div', 'pform-editor-settings-grid');

        grid.appendChild(fieldRow('Form title', input(state.title, function (v) { state.title = v; }), 'Shown at the top of the form and in Parish Forms administration.'));
        grid.appendChild(fieldRow('Eyebrow / small heading', input(state.eyebrow, function (v) { state.eyebrow = v; })));
        grid.appendChild(fieldRow('Introduction', textarea(state.description, function (v) { state.description = v; }, 4)));
        grid.appendChild(fieldRow('Submit button text', input(state.submit_label, function (v) { state.submit_label = v; })));
        grid.appendChild(fieldRow('Success heading', input(state.success_title, function (v) { state.success_title = v; })));
        grid.appendChild(fieldRow('Success message', textarea(state.confirmation, function (v) { state.confirmation = v; }, 3)));
        grid.appendChild(fieldRow('Privacy / follow-up note', textarea(state.privacy_note, function (v) { state.privacy_note = v; }, 3)));

        panel.appendChild(grid);

        var advanced = el('details', 'pform-editor-advanced');
        advanced.appendChild(el('summary', '', 'Submission display settings'));

        var emailChoices = {'': 'No reply-to address'};
        var allChoices = {'': '—'};
        topLevelFields().forEach(function (field) {
            allChoices[field.id] = field.label + ' (' + field.id + ')';
            if (field.type === 'email') {
                emailChoices[field.id] = field.label;
            }
        });

        advanced.appendChild(fieldRow('Reply-To email field', select(state.reply_to_field || '', emailChoices, function (v) {
            state.reply_to_field = v;
        }), 'Staff notification emails use this submitted address as Reply-To when available.'));

        advanced.appendChild(multiSelectControl('Submission title fields', state.admin_primary_fields || [], allChoices, function (values) {
            state.admin_primary_fields = values;
        }, 'These fields identify a submission in the admin list.'));

        advanced.appendChild(multiSelectControl('Contact summary fields', state.admin_contact_fields || [], allChoices, function (values) {
            state.admin_contact_fields = values;
        }, 'These fields appear in the Contact column of the submissions list.'));

        panel.appendChild(advanced);
        return panel;
    }

    function multiSelectControl(labelText, selected, choices, onChange, help) {
        var wrap = el('div', 'pform-editor-control');
        var label = el('label', '', labelText);
        var node = document.createElement('select');
        node.multiple = true;
        node.size = Math.min(6, Math.max(3, Object.keys(choices).length));
        Object.keys(choices).forEach(function (key) {
            if (key === '') {
                return;
            }
            var option = document.createElement('option');
            option.value = key;
            option.textContent = choices[key];
            option.selected = selected.indexOf(key) !== -1;
            node.appendChild(option);
        });
        node.addEventListener('change', function () {
            var values = Array.prototype.slice.call(node.selectedOptions).map(function (option) { return option.value; });
            onChange(values);
        });
        label.appendChild(node);
        wrap.appendChild(label);
        if (help) {
            wrap.appendChild(el('p', 'description', help));
        }
        return wrap;
    }

    function renderCondition(owner, excludeFieldIds) {
        var wrap = el('div', 'pform-editor-condition');
        excludeFieldIds = Array.isArray(excludeFieldIds) ? excludeFieldIds : [excludeFieldIds || ''];
        var enabled = !!owner.condition;
        var enabledControl = checkbox(enabled, function (checked) {
            if (checked) {
                var candidates = topLevelFields().filter(function (field) {
                    return excludeFieldIds.indexOf(field.id) === -1 && ['radio', 'text', 'email', 'tel'].indexOf(field.type) !== -1;
                });
                owner.condition = {
                    field: candidates.length ? candidates[0].id : '',
                    equals: ''
                };
            } else {
                delete owner.condition;
            }
            render();
        });
        var label = el('label', 'pform-editor-check', 'Show conditionally');
        label.insertBefore(enabledControl, label.firstChild);
        wrap.appendChild(label);

        if (!owner.condition) {
            return wrap;
        }

        var choices = {'': 'Choose a field'};
        topLevelFields().forEach(function (field) {
            if (excludeFieldIds.indexOf(field.id) === -1 && ['radio', 'text', 'email', 'tel'].indexOf(field.type) !== -1) {
                choices[field.id] = field.label + ' (' + field.id + ')';
            }
        });

        wrap.appendChild(fieldRow('Show when field', select(owner.condition.field || '', choices, function (v) {
            owner.condition.field = v;
            owner.condition.equals = '';
            render();
        })));

        var source = topLevelFields().filter(function (field) { return field.id === owner.condition.field; })[0];
        if (source && source.options && (source.type === 'radio' || source.type === 'checkboxes')) {
            var optionChoices = {'': 'Choose a value'};
            Object.keys(source.options).forEach(function (key) {
                optionChoices[key] = source.options[key];
            });
            wrap.appendChild(fieldRow('Equals', select(owner.condition.equals || '', optionChoices, function (v) {
                owner.condition.equals = v;
            })));
        } else {
            wrap.appendChild(fieldRow('Equals', input(owner.condition.equals || '', function (v) {
                owner.condition.equals = v;
            }), 'Enter the exact value that should make this item visible.'));
        }
        return wrap;
    }

    function renderField(field, fields, index, nested, parentKey) {
        var card = el('div', 'pform-editor-field');
        var heading = el('div', 'pform-editor-field__heading');
        var handle = el('span', 'dashicons dashicons-move pform-editor-drag', '');
        handle.setAttribute('aria-hidden', 'true');
        heading.appendChild(handle);
        heading.appendChild(el('strong', '', field.label || 'Untitled Field'));
        heading.appendChild(el('code', '', field.id || ''));

        var actions = el('div', 'pform-editor-mini-actions');
        actions.appendChild(button('↑', 'button button-small', function () { move(fields, index, index - 1); }));
        actions.appendChild(button('↓', 'button button-small', function () { move(fields, index, index + 1); }));
        actions.appendChild(button('Remove', 'button button-small button-link-delete', function () {
            if (window.confirm((data.labels && data.labels.confirmRemoveField) || 'Remove this field?')) {
                fields.splice(index, 1);
                render();
            }
        }));
        heading.appendChild(actions);
        card.appendChild(heading);

        var grid = el('div', 'pform-editor-field__grid');
        var labelInput = input(field.label, function (v) {
            field.label = v;
            if (field._autoId) {
                field.id = nested ? uniqueNestedId(fields, v, field) : uniqueFieldId(v, field);
                field._autoId = false;
                render();
            }
        });
        grid.appendChild(fieldRow('Label', labelInput));

        var allowedTypes = {};
        Object.keys(typeLabels).forEach(function (key) {
            if (!(nested && key === 'repeater')) {
                allowedTypes[key] = typeLabels[key];
            }
        });
        grid.appendChild(fieldRow('Field type', select(field.type, allowedTypes, function (v) {
            field.type = v;
            if ((v === 'radio' || v === 'checkboxes') && !field.options) {
                field.options = {yes: 'Yes', no: 'No'};
            }
            if (v === 'repeater' && !field.fields) {
                field.item_label = 'Item';
                field.add_label = 'Add Another';
                field.min_items = 0;
                field.max_items = 10;
                field.fields = [newField(true, [])];
            }
            render();
        })));

        grid.appendChild(fieldRow('Width', select(field.width || 'full', widths, function (v) { field.width = v; })));

        var requiredWrap = el('div', 'pform-editor-control');
        var requiredLabel = el('label', 'pform-editor-check', 'Required');
        requiredLabel.insertBefore(checkbox(field.required, function (v) { field.required = v; }), requiredLabel.firstChild);
        requiredWrap.appendChild(requiredLabel);
        grid.appendChild(requiredWrap);

        if (['text', 'email', 'tel', 'textarea'].indexOf(field.type) !== -1) {
            grid.appendChild(fieldRow('Maximum length', input(field.max_length || (field.type === 'textarea' ? 2000 : 180), function (v) {
                field.max_length = parseInt(v, 10) || 180;
            }, 'number')));
        }

        if (field.type === 'date') {
            var dateWrap = el('div', 'pform-editor-control');
            var dateLabel = el('label', 'pform-editor-check', 'Do not allow future dates');
            dateLabel.insertBefore(checkbox(field.not_future, function (v) { field.not_future = v; }), dateLabel.firstChild);
            dateWrap.appendChild(dateLabel);
            grid.appendChild(dateWrap);
        }

        card.appendChild(grid);

        if (field.type === 'radio' || field.type === 'checkboxes') {
            card.appendChild(fieldRow('Choices', textarea(optionLines(field.options || {}), function (v) {
                field.options = parseOptionLines(v);
            }, 5), (data.labels && data.labels.optionHelp) || 'One choice per line.'));
        }

        if (!nested) {
            card.appendChild(renderCondition(field, [field.id]));
        }

        if (field.type === 'repeater') {
            var rep = el('div', 'pform-editor-repeater');
            var repGrid = el('div', 'pform-editor-field__grid');
            repGrid.appendChild(fieldRow('Item label', input(field.item_label || 'Item', function (v) { field.item_label = v; })));
            repGrid.appendChild(fieldRow('Add button text', input(field.add_label || 'Add Another', function (v) { field.add_label = v; })));
            repGrid.appendChild(fieldRow('Minimum items', input(field.min_items || 0, function (v) { field.min_items = parseInt(v, 10) || 0; }, 'number')));
            repGrid.appendChild(fieldRow('Maximum items', input(field.max_items || 10, function (v) { field.max_items = parseInt(v, 10) || 10; }, 'number')));
            rep.appendChild(repGrid);
            rep.appendChild(el('h4', '', 'Fields inside each item'));
            var nestedList = el('div', 'pform-editor-nested-fields');
            (field.fields || []).forEach(function (nestedField, nestedIndex) {
                nestedList.appendChild(renderField(nestedField, field.fields, nestedIndex, true, field.id));
            });
            rep.appendChild(nestedList);
            rep.appendChild(button('Add Field to Repeating Group', 'button', function () {
                field.fields = field.fields || [];
                field.fields.push(newField(true, field.fields));
                render();
            }));
            card.appendChild(rep);
        }

        drag(card, nested ? 'nested-field' : 'field', index, parentKey, function (from, to) {
            move(fields, from, to);
        });
        return card;
    }

    function renderSection(section, index) {
        var card = el('section', 'pform-editor-section');
        var heading = el('div', 'pform-editor-section__heading');
        heading.appendChild(el('span', 'dashicons dashicons-move pform-editor-drag', ''));
        heading.appendChild(el('h3', '', section.title || 'Untitled Section'));

        var actions = el('div', 'pform-editor-mini-actions');
        actions.appendChild(button('↑', 'button button-small', function () { move(state.sections, index, index - 1); }));
        actions.appendChild(button('↓', 'button button-small', function () { move(state.sections, index, index + 1); }));
        actions.appendChild(button('Remove Section', 'button button-small button-link-delete', function () {
            if (window.confirm((data.labels && data.labels.confirmRemoveSection) || 'Remove this section?')) {
                state.sections.splice(index, 1);
                render();
            }
        }));
        heading.appendChild(actions);
        card.appendChild(heading);

        var grid = el('div', 'pform-editor-settings-grid');
        grid.appendChild(fieldRow('Section title', input(section.title, function (v) { section.title = v; })));
        grid.appendChild(fieldRow('Section description', textarea(section.description || '', function (v) { section.description = v; }, 2)));
        card.appendChild(grid);

        card.appendChild(renderCondition(section, (section.fields || []).map(function (field) { return field.id; })));

        var fieldsWrap = el('div', 'pform-editor-fields');
        (section.fields || []).forEach(function (field, fieldIndex) {
            fieldsWrap.appendChild(renderField(field, section.fields, fieldIndex, false, section.id));
        });
        card.appendChild(fieldsWrap);

        card.appendChild(button('Add Field', 'button button-secondary', function () {
            section.fields = section.fields || [];
            section.fields.push(newField(false, section.fields));
            render();
        }));

        drag(card, 'section', index, 'sections', function (from, to) {
            move(state.sections, from, to);
        });
        return card;
    }

    function render() {
        root.innerHTML = '';
        root.appendChild(renderGeneral());

        var sectionsPanel = el('section', 'pform-editor-panel');
        var titleRow = el('div', 'pform-editor-panel__heading');
        titleRow.appendChild(el('h2', '', 'Sections and Fields'));
        titleRow.appendChild(button('Add Section', 'button button-primary', function () {
            state.sections = state.sections || [];
            state.sections.push(newSection());
            render();
        }));
        sectionsPanel.appendChild(titleRow);

        var sections = el('div', 'pform-editor-sections');
        (state.sections || []).forEach(function (section, index) {
            sections.appendChild(renderSection(section, index));
        });
        sectionsPanel.appendChild(sections);
        root.appendChild(sectionsPanel);
    }

    function cleanForSave(value) {
        if (Array.isArray(value)) {
            return value.map(cleanForSave);
        }
        if (value && typeof value === 'object') {
            var result = {};
            Object.keys(value).forEach(function (key) {
                if (key.charAt(0) === '_') {
                    return;
                }
                result[key] = cleanForSave(value[key]);
            });
            return result;
        }
        return value;
    }

    form.addEventListener('submit', function (event) {
        if (!state.title || !String(state.title).trim()) {
            event.preventDefault();
            window.alert('Please enter a form title.');
            return;
        }
        if (!state.sections || !state.sections.length) {
            event.preventDefault();
            window.alert('Please add at least one section.');
            return;
        }
        jsonInput.value = JSON.stringify(cleanForSave(state));
    });

    form.querySelectorAll('[data-pform-operation]').forEach(function (buttonNode) {
        buttonNode.addEventListener('click', function () {
            operationInput.value = buttonNode.getAttribute('data-pform-operation') || 'save';
        });
    });

    render();
}());
