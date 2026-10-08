(() => {
    'use strict';

    if (typeof cbpWeeklyPrototype === 'undefined') {
        return;
    }

    const initialItems = JSON.parse(JSON.stringify(cbpWeeklyPrototype.items || []));
    let items = JSON.parse(JSON.stringify(initialItems));
    let editingIndex = null;
    let previewMode = false;

    const documentEl = document.getElementById('cbp-proto-document');
    const weekLabel = document.getElementById('cbp-proto-week-label');
    const paperWeek = document.getElementById('cbp-proto-paper-week');
    const help = document.getElementById('cbp-proto-help');
    const previewButton = document.getElementById('cbp-proto-preview');
    const addButton = document.getElementById('cbp-proto-add');
    const resetButton = document.getElementById('cbp-proto-reset');
    const dialog = document.getElementById('cbp-proto-dialog');
    const backdrop = document.getElementById('cbp-proto-dialog-backdrop');
    const dialogTitle = document.getElementById('cbp-proto-dialog-title');
    const dateField = document.getElementById('cbp-proto-field-date');
    const timeField = document.getElementById('cbp-proto-field-time');
    const locationField = document.getElementById('cbp-proto-field-location');
    const typeField = document.getElementById('cbp-proto-field-type');
    const detailField = document.getElementById('cbp-proto-field-detail');
    const detailLabel = document.getElementById('cbp-proto-detail-label');
    const liveLine = document.getElementById('cbp-proto-live-line');
    const deleteButton = document.getElementById('cbp-proto-delete');
    const doneButton = document.getElementById('cbp-proto-done');
    const cancelButton = document.getElementById('cbp-proto-cancel');
    const closeButton = document.getElementById('cbp-proto-close');

    weekLabel.textContent = cbpWeeklyPrototype.weekLabel || '';
    paperWeek.textContent = cbpWeeklyPrototype.weekLabel || '';

    function populateSelect(select, values, valueFor, labelFor) {
        select.innerHTML = '';
        values.forEach((value) => {
            const option = document.createElement('option');
            option.value = valueFor ? valueFor(value) : value;
            option.textContent = labelFor ? labelFor(value) : value;
            select.appendChild(option);
        });
    }

    populateSelect(
        dateField,
        cbpWeeklyPrototype.days || [],
        (day) => day.date,
        (day) => day.label
    );
    populateSelect(locationField, cbpWeeklyPrototype.locations || []);
    populateSelect(typeField, cbpWeeklyPrototype.types || []);

    function timeSortValue(value) {
        const raw = String(value || '').trim();
        if (! raw) {
            return 0;
        }
        if (/after\s+mass/i.test(raw)) {
            return 24 * 60 + 1;
        }
        const match = raw.match(/(\d{1,2}):(\d{2})\s*(AM|PM)/i);
        if (! match) {
            return 23 * 60;
        }
        let hour = Number(match[1]);
        const minute = Number(match[2]);
        const meridiem = match[3].toUpperCase();
        if (hour === 12) {
            hour = 0;
        }
        if (meridiem === 'PM') {
            hour += 12;
        }
        return (hour * 60) + minute;
    }

    function locationPrefix(location) {
        if (location === 'St. Peter') {
            return 'SP:';
        }
        if (location === 'St. Mary’s') {
            return 'SM:';
        }
        if (location === 'Heritage Living Center') {
            return 'Heritage Living Center:';
        }
        return '';
    }

    function bulletinLine(item) {
        const prefix = locationPrefix(item.location);
        const time = String(item.time || '').trim();
        const type = String(item.type || '').trim();
        const detail = String(item.detail || '').trim();

        if (type === 'No Mass') {
            return [prefix, 'NO MASS'].filter(Boolean).join(' ');
        }

        if (type === 'Cancelled Event') {
            return [prefix, detail || 'Cancelled'].filter(Boolean).join(' ');
        }

        if (type === 'Mass') {
            return [prefix, time, 'Mass', detail].filter(Boolean).join(' ');
        }

        if (type === 'Parish Event') {
            return [prefix, time, detail || 'Parish Event'].filter(Boolean).join(' ');
        }

        if (type === 'Liturgy of the Word') {
            return [prefix, time, 'Liturgy of the Word', detail].filter(Boolean).join(' ');
        }

        return [prefix, time, type, detail].filter(Boolean).join(' ');
    }

    function render() {
        documentEl.innerHTML = '';

        const indexed = items.map((item, index) => ({ item, index }));
        indexed.sort((a, b) => {
            const dateCompare = String(a.item.date).localeCompare(String(b.item.date));
            if (dateCompare !== 0) {
                return dateCompare;
            }
            const timeCompare = timeSortValue(a.item.time) - timeSortValue(b.item.time);
            if (timeCompare !== 0) {
                return timeCompare;
            }
            return a.index - b.index;
        });

        (cbpWeeklyPrototype.days || []).forEach((day) => {
            const section = document.createElement('section');
            section.className = 'cbp-proto-day';
            section.dataset.date = day.date;

            const heading = document.createElement('div');
            heading.className = 'cbp-proto-day-heading';
            heading.textContent = day.label;
            section.appendChild(heading);

            const rows = indexed.filter((entry) => entry.item.date === day.date);
            if (! rows.length) {
                const empty = document.createElement('button');
                empty.type = 'button';
                empty.className = 'cbp-proto-empty';
                empty.textContent = '+ Add something for this day';
                empty.addEventListener('click', () => openAdd(day.date));
                section.appendChild(empty);
            } else {
                rows.forEach((entry) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'cbp-proto-item';
                    button.dataset.index = String(entry.index);
                    button.innerHTML = '<span class="cbp-proto-line-text"></span><span class="cbp-proto-edit-hint">Edit</span>';
                    button.querySelector('.cbp-proto-line-text').textContent = bulletinLine(entry.item);
                    button.addEventListener('click', () => {
                        if (! previewMode) {
                            openEdit(entry.index);
                        }
                    });
                    section.appendChild(button);
                });

                const addHere = document.createElement('button');
                addHere.type = 'button';
                addHere.className = 'cbp-proto-add-here';
                addHere.textContent = '+ Add item';
                addHere.addEventListener('click', () => openAdd(day.date));
                section.appendChild(addHere);
            }

            documentEl.appendChild(section);
        });

        document.body.classList.toggle('cbp-proto-preview-mode', previewMode);
        previewButton.textContent = previewMode ? 'Back to Edit' : 'Bulletin Preview';
        addButton.disabled = previewMode;
        resetButton.disabled = previewMode;
        help.textContent = previewMode
            ? 'Preview mode — this is the clean bulletin view. Choose “Back to Edit” to make changes.'
            : 'Click any bulletin line to edit it. Hover between days to add another item.';
    }

    function updateDetailLabel() {
        const type = typeField.value;
        if (type === 'Mass') {
            detailLabel.textContent = 'Mass intention (optional)';
            detailField.placeholder = 'e.g. † Tom Spahn';
        } else if (type === 'Parish Event' || type === 'Cancelled Event') {
            detailLabel.textContent = 'Event name';
            detailField.placeholder = 'e.g. Bible Study';
        } else {
            detailLabel.textContent = 'Note (optional)';
            detailField.placeholder = 'Optional note';
        }
    }

    function currentFormItem() {
        return {
            date: dateField.value,
            time: timeField.value.trim(),
            location: locationField.value,
            type: typeField.value,
            detail: detailField.value.trim(),
        };
    }

    function refreshLiveLine() {
        updateDetailLabel();
        liveLine.textContent = bulletinLine(currentFormItem()) || '—';
    }

    function openDialog() {
        dialog.hidden = false;
        backdrop.hidden = false;
        document.body.classList.add('cbp-proto-dialog-open');
        refreshLiveLine();
        window.setTimeout(() => timeField.focus(), 0);
    }

    function closeDialog() {
        dialog.hidden = true;
        backdrop.hidden = true;
        document.body.classList.remove('cbp-proto-dialog-open');
        editingIndex = null;
    }

    function openEdit(index) {
        editingIndex = index;
        const item = items[index];
        dialogTitle.textContent = 'Edit bulletin item';
        dateField.value = item.date;
        timeField.value = item.time || '';
        locationField.value = item.location || 'Other / no location';
        typeField.value = item.type || 'Parish Event';
        detailField.value = item.detail || '';
        deleteButton.hidden = false;
        openDialog();
    }

    function openAdd(date) {
        if (previewMode) {
            return;
        }
        editingIndex = null;
        dialogTitle.textContent = 'Add bulletin item';
        dateField.value = date || cbpWeeklyPrototype.weekStart || '';
        timeField.value = '';
        locationField.value = 'St. Peter';
        typeField.value = 'Parish Event';
        detailField.value = '';
        deleteButton.hidden = true;
        openDialog();
    }

    function saveDialog() {
        const next = currentFormItem();
        if (! next.date || ! next.type) {
            return;
        }

        if (editingIndex === null) {
            items.push(next);
        } else {
            items[editingIndex] = next;
        }
        closeDialog();
        render();
    }

    [dateField, timeField, locationField, typeField, detailField].forEach((field) => {
        field.addEventListener('input', refreshLiveLine);
        field.addEventListener('change', refreshLiveLine);
    });

    addButton.addEventListener('click', () => openAdd(cbpWeeklyPrototype.weekStart));
    previewButton.addEventListener('click', () => {
        previewMode = ! previewMode;
        closeDialog();
        render();
    });
    resetButton.addEventListener('click', () => {
        items = JSON.parse(JSON.stringify(initialItems));
        closeDialog();
        render();
    });

    doneButton.addEventListener('click', saveDialog);
    cancelButton.addEventListener('click', closeDialog);
    closeButton.addEventListener('click', closeDialog);
    backdrop.addEventListener('click', closeDialog);
    deleteButton.addEventListener('click', () => {
        if (editingIndex === null) {
            return;
        }
        items.splice(editingIndex, 1);
        closeDialog();
        render();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && ! dialog.hidden) {
            closeDialog();
        }
    });

    render();
})();