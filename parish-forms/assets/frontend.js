(function () {
    'use strict';

    function updateConditions(form) {
        form.querySelectorAll('[data-pform-condition-field]').forEach(function (container) {
            var field = container.getAttribute('data-pform-condition-field');
            var expected = container.getAttribute('data-pform-condition-value');
            var controls = form.querySelectorAll('[name="pf[' + field + ']"], [name="pf[' + field + '][]"]');
            var visible = Array.prototype.some.call(controls, function (control) {
                if (control.type === 'radio' || control.type === 'checkbox') {
                    return control.checked && control.value === expected;
                }
                return control.value === expected;
            });
            container.hidden = !visible;
            container.querySelectorAll('input, select, textarea, button').forEach(function (control) {
                control.disabled = !visible;
            });
        });
    }

    function renumber(repeater) {
        var itemLabel = repeater.getAttribute('data-item-label') || 'Item';
        repeater.querySelectorAll('[data-pform-repeater-item]').forEach(function (item, index) {
            var legend = item.querySelector(':scope > legend');
            if (legend) {
                legend.textContent = itemLabel + ' ' + (index + 1);
            }
        });
        var count = repeater.querySelectorAll('[data-pform-repeater-item]').length;
        var maximum = parseInt(repeater.getAttribute('data-max-items'), 10) || 10;
        var add = repeater.querySelector('[data-pform-add]');
        var limit = repeater.querySelector('[data-pform-limit]');
        add.hidden = count >= maximum;
        limit.hidden = count < maximum;
    }

    function setupRepeater(repeater) {
        var items = repeater.querySelector('[data-pform-repeater-items]');
        var template = repeater.querySelector('[data-pform-repeater-template]');
        var nextIndex = items.querySelectorAll('[data-pform-repeater-item]').length;

        repeater.addEventListener('click', function (event) {
            if (event.target.matches('[data-pform-add]')) {
                var maximum = parseInt(repeater.getAttribute('data-max-items'), 10) || 10;
                if (items.querySelectorAll('[data-pform-repeater-item]').length >= maximum) {
                    return;
                }
                var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));
                nextIndex += 1;
                items.insertAdjacentHTML('beforeend', html);
                renumber(repeater);
                var added = items.lastElementChild;
                var firstInput = added ? added.querySelector('input, textarea, select') : null;
                if (firstInput) {
                    firstInput.focus();
                }
            }
            if (event.target.matches('[data-pform-remove]')) {
                var item = event.target.closest('[data-pform-repeater-item]');
                if (item) {
                    item.remove();
                    renumber(repeater);
                }
            }
        });
        renumber(repeater);
    }

    document.querySelectorAll('.pform').forEach(function (form) {
        form.addEventListener('change', function () {
            updateConditions(form);
        });
        form.addEventListener('input', function (event) {
            if (event.target.matches('input[type="text"], input[type="email"], input[type="tel"], textarea')) {
                updateConditions(form);
            }
        });
        form.querySelectorAll('[data-pform-repeater]').forEach(setupRepeater);
        updateConditions(form);
    });

    var notice = document.querySelector('.pform-notice');
    if (notice && window.location.hash === '#parish-form') {
        notice.focus();
    }
}());
