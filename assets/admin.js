(function () {
    'use strict';

    var filesPicker = document.getElementById('cbp-files');
    var folderPicker = document.getElementById('cbp-folder');
    var form = document.getElementById('cbp-preview-form');
    var list = document.getElementById('cbp-file-list');
    var progress = document.getElementById('cbp-progress');
    var config = window.cbpAdmin || {};
    if (!filesPicker || !folderPicker || !form || !list || !progress) {
        return;
    }

    function fileLabel(file) {
        return file.webkitRelativePath || file.name;
    }

    function orderedPdfs() {
        return Array.prototype.slice.call(filesPicker.files)
            .concat(Array.prototype.slice.call(folderPicker.files))
            .filter(function (file) { return /\.pdf$/i.test(file.name); })
            .sort(function (a, b) {
                var aLabel = fileLabel(a);
                var bLabel = fileLabel(b);
                var aGroup = /insert/i.test(aLabel) ? 20 : 10;
                var bGroup = /insert/i.test(bLabel) ? 20 : 10;
                return aGroup === bGroup
                    ? aLabel.localeCompare(bLabel, undefined, {numeric: true})
                    : aGroup - bGroup;
            });
    }

    function renderSelection() {
        list.replaceChildren();
        orderedPdfs()
            .forEach(function (file) {
                var item = document.createElement('li');
                item.textContent = fileLabel(file);
                list.appendChild(item);
            });
    }

    async function fetchPdf(url) {
        var response = await window.fetch(url, {credentials: 'same-origin'});
        if (!response.ok) {
            throw new Error('Could not load a saved cover template.');
        }
        return response.arrayBuffer();
    }

    async function appendPdf(output, bytes) {
        var source = await window.PDFLib.PDFDocument.load(bytes);
        var pages = await output.copyPages(source, source.getPageIndices());
        pages.forEach(function (page) { output.addPage(page); });
    }

    async function createBrowserPreview(files) {
        if (!window.PDFLib || !window.PDFLib.PDFDocument) {
            throw new Error('The bundled browser PDF library did not load.');
        }
        var covers = await Promise.all([
            fetchPdf(config.frontCoverUrl),
            fetchPdf(config.backCoverUrl)
        ]);
        var output = await window.PDFLib.PDFDocument.create();
        await appendPdf(output, covers[0]);
        for (var index = 0; index < files.length; index += 1) {
            await appendPdf(output, await files[index].arrayBuffer());
        }
        await appendPdf(output, covers[1]);
        return output.save({useObjectStreams: false});
    }

    filesPicker.addEventListener('change', renderSelection);
    folderPicker.addEventListener('change', renderSelection);
    form.addEventListener('submit', async function (event) {
        var files = orderedPdfs();
        if (files.length === 0) {
            event.preventDefault();
            window.alert(config.messages.choosePdf);
            filesPicker.focus();
            return;
        }
        if (!config.browserMerge) {
            return;
        }

        event.preventDefault();
        var button = form.querySelector('[type="submit"]');
        var oldLabel = button ? button.value : '';
        if (button) {
            button.disabled = true;
            button.value = config.messages.building;
        }
        progress.textContent = config.messages.building;

        try {
            var bytes = await createBrowserPreview(files);
            var data = new window.FormData(form);
            data.delete('components[]');
            data.delete('folder_components[]');
            data.append('merged_preview', new window.Blob([bytes], {type: 'application/pdf'}), 'bulletin-preview.pdf');
            data.append('component_manifest', JSON.stringify(files.map(fileLabel)));
            var response = await window.fetch(form.action, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                redirect: 'follow'
            });
            if (!response.ok) {
                throw new Error('WordPress rejected the generated preview.');
            }
            window.location.assign(response.url);
        } catch (error) {
            progress.textContent = config.messages.failed + ' ' + error.message;
            window.alert(progress.textContent);
            if (button) {
                button.disabled = false;
                button.value = oldLabel;
            }
        }
    });
}());
