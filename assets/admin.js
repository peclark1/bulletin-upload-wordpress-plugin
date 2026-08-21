(function () {
    'use strict';

    var filesPicker = document.getElementById('cbp-files');
    var folderPicker = document.getElementById('cbp-folder');
    var form = document.getElementById('cbp-preview-form');
    var list = document.getElementById('cbp-file-list');
    if (!filesPicker || !folderPicker || !form || !list) {
        return;
    }

    function selectedPdfs() {
        return Array.prototype.slice.call(filesPicker.files)
            .concat(Array.prototype.slice.call(folderPicker.files))
            .filter(function (file) { return /\.pdf$/i.test(file.name); });
    }

    function renderSelection() {
        list.replaceChildren();
        selectedPdfs()
            .sort(function (a, b) {
                return (a.webkitRelativePath || a.name).localeCompare(b.webkitRelativePath || b.name, undefined, {numeric: true});
            })
            .forEach(function (file) {
                var item = document.createElement('li');
                item.textContent = file.webkitRelativePath || file.name;
                list.appendChild(item);
            });
    }

    filesPicker.addEventListener('change', renderSelection);
    folderPicker.addEventListener('change', renderSelection);
    form.addEventListener('submit', function (event) {
        if (selectedPdfs().length === 0) {
            event.preventDefault();
            window.alert('Choose at least one weekly PDF file or an entire folder containing PDF files.');
            filesPicker.focus();
        }
    });
}());
