(function () {
    'use strict';

    function updateConditions(form) {
        form.querySelectorAll('[data-pform-condition-field]').forEach(function (container) {
            var field = container.getAttribute('data-pform-condition-field');
            var expected = container.getAttribute('data-pform-condition-value');
            var selected = form.querySelector('[name="pf[' + field + ']"]:checked');
            var visible = !!selected && selected.value === expected;
            container.hidden = !visible;
            container.querySelectorAll('input, select, textarea, button').forEach(function (control) {
                control.disabled = !visible;
            });
        });
    }

    function renumber(repeater) {
        repeater.querySelectorAll('[data-pform-repeater-item]').forEach(function (item, index) {
            var legend = item.querySelector(':scope > legend');
            if (legend) {
                legend.textContent = 'Child ' + (index + 1);
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
        form.addEventListener('change', function (event) {
            if (event.target.matches('input[type="radio"]')) {
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
