(() => {
    'use strict';

    if (typeof cbpWeeklyPrototype === 'undefined') {
        return;
    }

    const initialItems = JSON.parse(JSON.stringify(cbpWeeklyPrototype.items || []));
    let items = JSON.parse(JSON.stringify(initialItems));
    let editingIndex = null;
    let previewMode = false;

    const bulletinDate = document.getElementById('cbp-proto-bulletin-date');
    const massScheduleEl = document.getElementById('cbp-proto-mass-schedule');
    const summaryLinesEl = document.getElementById('cbp-proto-summary-lines');
    const calendarEl = document.getElementById('cbp-proto-calendar');
    const weekLabel = document.getElementById('cbp-proto-week-label');
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

    const massTypes = new Set(['Mass', 'No Mass', 'Liturgy of the Word']);
    const calendarTypes = new Set(['Parish Event', 'Cancelled Event', 'Adoration', 'Liturgy of the Word']);

    weekLabel.textContent = cbpWeeklyPrototype.weekLabel || '';
    bulletinDate.textContent = cbpWeeklyPrototype.bulletinDate || '';

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

    function dayFor(date) {
        return (cbpWeeklyPrototype.days || []).find((day) => day.date === date) || null;
    }

    function dayName(date) {
        const day = dayFor(date);
        if (! day) {
            return '';
        }
        return String(day.label || '').split(',')[0];
    }

    function timeSortValue(value) {
        const raw = String(value || '').trim();
        if (! raw) {
            return 0;
        }
        if (/after\s+mass/i.test(raw)) {
            return (24 * 60) + 1;
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

    function indexedItems() {
        return items.map((item, index) => ({ item, index }));
    }

    function sortedMassItems(indexed) {
        return indexed.filter((entry) => massTypes.has(entry.item.type)).sort((a, b) => {
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

    function bulletinLocation(location) {
        if (location === 'St. Peter') {
            return 'St. Peter’s';
        }
        if (location === 'St. Mary’s') {
            return 'St. Mary’s';
        }
        if (location === 'Heritage Living Center') {
            return 'Heritage Senior Living Center';
        }
        return String(location || '').replace('Other / no location', '');
    }

    function formatCalendarTime(value) {
        return String(value || '')
            .replace(/\bAM\b/g, 'am')
            .replace(/\bPM\b/g, 'pm')
            .replace(/\s*–\s*/g, ' – ')
            .trim();
    }

    function formatProseTime(value) {
        return String(value || '')
            .replace(/\bAM\b/g, 'am')
            .replace(/\bPM\b/g, 'pm')
            .replace(/\s*–\s*/g, ' - ')
            .trim();
    }

    function formatMassTime(value) {
        return String(value || '')
            .replace(/\bAM\b/g, 'a.m.')
            .replace(/\bPM\b/g, 'p.m.')
            .trim();
    }

    function normalizeIntention(detail) {
        return String(detail || '').trim().replace(/^†\s*/, '+ ');
    }

    function ordinalParts(date) {
        const day = dayFor(date);
        if (! day) {
            return { base: '', suffix: '' };
        }

        const label = String(day.label || '');
        const match = label.match(/^(.+?\s)(\d{1,2})$/);
        if (! match) {
            return { base: label, suffix: '' };
        }

        const number = Number(match[2]);
        const mod100 = number % 100;
        let suffix = 'th';
        if (mod100 < 11 || mod100 > 13) {
            if (number % 10 === 1) suffix = 'st';
            if (number % 10 === 2) suffix = 'nd';
            if (number % 10 === 3) suffix = 'rd';
        }

        return { base: match[1] + match[2], suffix };
    }

    function massDescription(item) {
        const type = String(item.type || '');
        const location = bulletinLocation(item.location);
        if (type === 'No Mass') {
            return location ? 'NO MASS at ' + location : 'NO MASS';
        }
        if (type === 'Liturgy of the Word') {
            return [location, 'Liturgy of the Word'].filter(Boolean).join(', ');
        }
        if (type === 'Mass') {
            return [location, normalizeIntention(item.detail)].filter(Boolean).join(', ');
        }
        return '';
    }

    function calendarLine(item) {
        const prefix = locationPrefix(item.location);
        const time = formatCalendarTime(item.time);
        const type = String(item.type || '').trim();
        const detail = String(item.detail || '').trim();

        if (type === 'Cancelled Event') {
            return [prefix, detail || 'Cancelled'].filter(Boolean).join(' ');
        }

        if (type === 'Parish Event') {
            return [prefix, time, detail || 'Parish Event'].filter(Boolean).join(' ');
        }

        if (type === 'Adoration') {
            return [prefix, time, 'Adoration'].filter(Boolean).join(' ');
        }

        if (type === 'Liturgy of the Word') {
            return [prefix, time].filter(Boolean).join(' ');
        }

        if (massTypes.has(type)) {
            return [formatMassTime(item.time), massDescription(item)].filter(Boolean).join(' ');
        }

        return [prefix, time, type, detail].filter(Boolean).join(' ');
    }

    function createEditHint() {
        const hint = document.createElement('span');
        hint.className = 'cbp-proto-edit-hint';
        hint.textContent = 'Edit';
        return hint;
    }

    function renderMassSchedule(indexed) {
        massScheduleEl.innerHTML = '';

        sortedMassItems(indexed).forEach((entry) => {
            const item = entry.item;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'cbp-proto-mass-entry cbp-proto-editable';
            button.dataset.index = String(entry.index);

            const heading = document.createElement('span');
            heading.className = 'cbp-proto-mass-date-line';
            const parts = ordinalParts(item.date);
            const dateText = document.createElement('span');
            dateText.className = 'cbp-proto-underlined';
            dateText.textContent = parts.base;
            heading.appendChild(dateText);

            if (parts.suffix) {
                const sup = document.createElement('sup');
                sup.textContent = parts.suffix;
                heading.appendChild(sup);
            }

            if (item.time) {
                heading.appendChild(document.createTextNode(', ' + formatMassTime(item.time)));
            }

            const description = document.createElement('span');
            description.className = 'cbp-proto-mass-description';
            description.textContent = massDescription(item);

            button.appendChild(heading);
            button.appendChild(description);
            button.appendChild(createEditHint());
            button.addEventListener('click', () => {
                if (! previewMode) {
                    openEdit(entry.index);
                }
            });
            massScheduleEl.appendChild(button);
        });
    }

    function renderSummaryLines(indexed) {
        summaryLinesEl.innerHTML = '';

        const reconciliation = indexed.find((entry) => entry.item.type === 'Reconciliation');
        if (reconciliation) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'cbp-proto-summary-line cbp-proto-editable';
            const label = document.createElement('span');
            label.className = 'cbp-proto-summary-label';
            label.textContent = 'Reconciliation';
            button.appendChild(label);
            button.appendChild(document.createTextNode(
                ' ' + formatProseTime(reconciliation.item.time) + ' on ' + dayName(reconciliation.item.date)
            ));
            button.appendChild(createEditHint());
            button.addEventListener('click', () => {
                if (! previewMode) {
                    openEdit(reconciliation.index);
                }
            });
            summaryLinesEl.appendChild(button);
        }

        const rosaryLine = document.createElement('div');
        rosaryLine.className = 'cbp-proto-summary-line cbp-proto-generated-line';
        const rosaryLabel = document.createElement('span');
        rosaryLabel.className = 'cbp-proto-summary-label';
        rosaryLabel.textContent = 'The Rosary';
        rosaryLine.appendChild(rosaryLabel);
        rosaryLine.appendChild(document.createTextNode(
            ' is prayed each day ½ hour prior to Mass. On Friday’s, the Rosary is prayed after Mass.'
        ));
        summaryLinesEl.appendChild(rosaryLine);

        const adoration = indexed.filter((entry) => entry.item.type === 'Adoration');
        if (adoration.length) {
            const adorationLine = document.createElement('div');
            adorationLine.className = 'cbp-proto-summary-line cbp-proto-generated-line';
            const adorationLabel = document.createElement('span');
            adorationLabel.className = 'cbp-proto-summary-label';
            adorationLabel.textContent = 'Adoration';
            adorationLine.appendChild(adorationLabel);

            let prose = ' is held at St. Peter’s';
            if (adoration.length >= 1) {
                const first = adoration[0].item;
                prose += ' on ' + dayName(first.date) + 's, ' + formatCalendarTime(first.time);
            }
            if (adoration.length >= 2) {
                const second = adoration[1].item;
                prose += ' and ' + dayName(second.date) + 's from ' + formatCalendarTime(second.time).replace(' – ', ' to ');
            }
            prose += '.';
            adorationLine.appendChild(document.createTextNode(prose));
            summaryLinesEl.appendChild(adorationLine);
        }
    }

    function renderCalendar(indexed) {
        calendarEl.innerHTML = '';

        (cbpWeeklyPrototype.days || []).forEach((day) => {
            const rows = indexed.filter((entry) => (
                entry.item.date === day.date && calendarTypes.has(entry.item.type)
            ));

            if (! rows.length) {
                return;
            }

            const section = document.createElement('section');
            section.className = 'cbp-proto-calendar-day';
            section.dataset.date = day.date;

            const heading = document.createElement('button');
            heading.type = 'button';
            heading.className = 'cbp-proto-calendar-heading';
            heading.textContent = day.label;
            heading.title = 'Add another item for ' + day.label;
            heading.addEventListener('click', () => {
                if (! previewMode) {
                    openAdd(day.date);
                }
            });
            section.appendChild(heading);

            rows.forEach((entry) => {
                const item = entry.item;
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'cbp-proto-calendar-item cbp-proto-editable';
                button.dataset.index = String(entry.index);

                const firstLine = document.createElement('span');
                firstLine.className = 'cbp-proto-calendar-line';
                firstLine.textContent = calendarLine(item);
                button.appendChild(firstLine);

                if (item.type === 'Liturgy of the Word') {
                    const continuation = document.createElement('span');
                    continuation.className = 'cbp-proto-calendar-continuation';
                    continuation.textContent = 'Liturgy of the Word';
                    button.appendChild(continuation);
                }

                button.appendChild(createEditHint());
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

            calendarEl.appendChild(section);
        });
    }

    function render() {
        const indexed = indexedItems();
        renderMassSchedule(indexed);
        renderSummaryLines(indexed);
        renderCalendar(indexed);

        document.body.classList.toggle('cbp-proto-preview-mode', previewMode);
        previewButton.textContent = previewMode ? 'Back to Edit' : 'Bulletin Preview';
        addButton.disabled = previewMode;
        resetButton.disabled = previewMode;
        help.textContent = previewMode
            ? 'Preview mode — editing cues are hidden so you can judge the bulletin appearance.'
            : 'Click a Mass or calendar line to edit it. Click an underlined calendar day to add another item for that day.';
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
        const item = currentFormItem();
        liveLine.textContent = massTypes.has(item.type)
            ? [formatMassTime(item.time), massDescription(item)].filter(Boolean).join(' — ')
            : calendarLine(item) || '—';
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