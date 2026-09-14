(function () {
    'use strict';

    var filesPicker = document.getElementById('cbp-files');
    var folderPicker = document.getElementById('cbp-folder');
    var form = document.getElementById('cbp-preview-form');
    var list = document.getElementById('cbp-file-list');
    var progress = document.getElementById('cbp-progress');
    var config = window.cbpAdmin || {};
    var selectionPreviewUrls = [];
    var coverPreviewUrls = {front: '', back: ''};
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

    function revokeSelectionPreviewUrls() {
        selectionPreviewUrls.forEach(function (url) {
            window.URL.revokeObjectURL(url);
        });
        selectionPreviewUrls = [];
    }

    function thumbnailPdfUrl(url) {
        return url + '#page=1&view=FitH&toolbar=0&navpanes=0&scrollbar=0';
    }

    function createPdfThumbnail(sourceUrl, label, linkLabel) {
        var card = document.createElement('div');
        card.className = 'cbp-pdf-thumb';

        var viewer = document.createElement('object');
        viewer.className = 'cbp-pdf-thumb-viewer';
        viewer.type = 'application/pdf';
        viewer.data = thumbnailPdfUrl(sourceUrl);
        viewer.setAttribute('aria-label', label);

        var fallback = document.createElement('span');
        fallback.className = 'cbp-pdf-thumb-fallback';
        fallback.textContent = 'PDF';
        viewer.appendChild(fallback);
        card.appendChild(viewer);

        var caption = document.createElement('div');
        caption.className = 'cbp-pdf-thumb-caption';
        caption.textContent = label;
        card.appendChild(caption);

        var open = document.createElement('a');
        open.className = 'cbp-pdf-thumb-open';
        open.href = sourceUrl;
        open.target = '_blank';
        open.rel = 'noopener';
        open.textContent = linkLabel || 'Open PDF';
        card.appendChild(open);

        return card;
    }

    function renderSelection() {
        revokeSelectionPreviewUrls();
        list.replaceChildren();
        orderedPdfs()
            .forEach(function (file) {
                var item = document.createElement('li');
                item.className = 'cbp-file-preview-item';
                var objectUrl = window.URL.createObjectURL(file);
                selectionPreviewUrls.push(objectUrl);
                item.appendChild(createPdfThumbnail(objectUrl, fileLabel(file), 'Open selected PDF'));
                list.appendChild(item);
            });
    }

    function existingCoverIsPdf(input) {
        var label = input.closest('label');
        var description = label ? label.nextElementSibling : null;
        return !!(description && /\.pdf$/i.test(description.textContent.trim()));
    }

    function coverPreviewContainer(input) {
        var label = input.closest('label');
        var description = label ? label.nextElementSibling : null;
        if (!description) {
            return null;
        }

        var container = description.nextElementSibling;
        if (!container || !container.classList.contains('cbp-cover-preview')) {
            container = document.createElement('div');
            container.className = 'cbp-cover-preview';
            description.insertAdjacentElement('afterend', container);
        }
        return container;
    }

    function renderCoverPreview(which, input, savedUrl, title) {
        var container = coverPreviewContainer(input);
        if (!container) {
            return;
        }

        if (coverPreviewUrls[which]) {
            window.URL.revokeObjectURL(coverPreviewUrls[which]);
            coverPreviewUrls[which] = '';
        }

        container.replaceChildren();
        var selected = Array.prototype.slice.call(input.files || []).find(function (file) {
            return /\.pdf$/i.test(file.name);
        });
        if (selected) {
            coverPreviewUrls[which] = window.URL.createObjectURL(selected);
            container.appendChild(createPdfThumbnail(
                coverPreviewUrls[which],
                'New ' + title + ': ' + selected.name,
                'Open selected PDF'
            ));
            return;
        }

        if (savedUrl && existingCoverIsPdf(input)) {
            container.appendChild(createPdfThumbnail(savedUrl, 'Current ' + title, 'Open current PDF'));
        }
    }

    function initializeCoverPreviews() {
        var front = document.querySelector('input[name="front_cover"]');
        var back = document.querySelector('input[name="back_cover"]');

        if (front) {
            renderCoverPreview('front', front, config.frontCoverUrl, 'front cover');
            front.addEventListener('change', function () {
                renderCoverPreview('front', front, config.frontCoverUrl, 'front cover');
            });
        }

        if (back) {
            renderCoverPreview('back', back, config.backCoverUrl, 'back cover');
            back.addEventListener('change', function () {
                renderCoverPreview('back', back, config.backCoverUrl, 'back cover');
            });
        }
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

    function newUploadId() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID().replace(/-/g, '');
        }
        return Date.now().toString(16) + Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2);
    }

    async function responseFailure(response, fallback) {
        var detail = '';
        try {
            detail = (await response.text()).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 240);
        } catch (ignored) {
            detail = '';
        }
        return new Error(fallback + ' HTTP ' + response.status + (detail ? ': ' + detail : ''));
    }

    async function uploadInChunks(bytes) {
        var chunkSize = 512 * 1024;
        var total = Math.ceil(bytes.length / chunkSize);
        var uploadId = newUploadId();
        for (var index = 0; index < total; index += 1) {
            progress.textContent = config.messages.uploading + ' ' + (index + 1) + ' of ' + total + '...';
            var url = new window.URL(config.chunkUploadUrl, window.location.href);
            url.searchParams.set('upload_id', uploadId);
            url.searchParams.set('index', index);
            url.searchParams.set('total', total);
            var response = await window.fetch(url.toString(), {
                method: 'POST',
                body: bytes.slice(index * chunkSize, Math.min((index + 1) * chunkSize, bytes.length)),
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/octet-stream'}
            });
            if (!response.ok) {
                throw await responseFailure(response, 'Chunk ' + (index + 1) + ' upload failed.');
            }
            var result = await response.json();
            if (!result.success) {
                throw new Error('Chunk ' + (index + 1) + ' was rejected by WordPress.');
            }
        }
        return {uploadId: uploadId, total: total};
    }

    initializeCoverPreviews();
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
            var upload = await uploadInChunks(bytes);
            progress.textContent = 'Finalizing private preview...';
            var data = new window.URLSearchParams();
            data.append('action', 'cbp_finalize_browser_preview');
            data.append('_wpnonce', config.finalizeNonce);
            data.append('bulletin_date', form.querySelector('[name="bulletin_date"]').value);
            data.append('upload_id', upload.uploadId);
            data.append('total', upload.total);
            data.append('component_manifest', JSON.stringify(files.map(fileLabel)));
            var response = await window.fetch(config.finalizeUrl, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                redirect: 'follow'
            });
            if (!response.ok) {
                throw await responseFailure(response, 'WordPress could not finalize the generated preview.');
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

    window.addEventListener('beforeunload', function () {
        revokeSelectionPreviewUrls();
        Object.keys(coverPreviewUrls).forEach(function (which) {
            if (coverPreviewUrls[which]) {
                window.URL.revokeObjectURL(coverPreviewUrls[which]);
            }
        });
    });
}());
