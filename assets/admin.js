(function () {
    'use strict';

    var picker = document.getElementById('cbp-folder');
    var list = document.getElementById('cbp-file-list');
    if (!picker || !list) {
        return;
    }

    picker.addEventListener('change', function () {
        list.replaceChildren();
        Array.prototype.slice.call(picker.files)
            .filter(function (file) { return /\.pdf$/i.test(file.name); })
            .sort(function (a, b) {
                return (a.webkitRelativePath || a.name).localeCompare(b.webkitRelativePath || b.name, undefined, {numeric: true});
            })
            .forEach(function (file) {
                var item = document.createElement('li');
                item.textContent = file.webkitRelativePath || file.name;
                list.appendChild(item);
            });
    });
}());
