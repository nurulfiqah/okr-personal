(function () {
    var CFG = window.OKR_VIEW_CONFIG || {};
    var referenceLinks = CFG.referenceLinks ? CFG.referenceLinks.slice() : [];
    var attachments = CFG.attachments ? CFG.attachments.slice() : [];
    // Issuer/owner(s)/admin get add/remove access to Attachments and
    // Reference Links right here (not just Key Result Progress status, see
    // further below) - see okrCanCollaborateOnCard in lib.php.
    var canCollaborate = !!CFG.canCollaborate;

    function setError(id, msg) {
        var el = document.getElementById(id + '-error');
        if (el) el.textContent = msg || '';
    }

    // ---------------------------------------------------------------
    // "Changes saved" toast — shown once after a redirect from Edit.
    // ---------------------------------------------------------------
    if (window.location.search.indexOf('saved=1') !== -1) {
        var savedToastEl = document.getElementById('okr-saved-toast');
        if (savedToastEl) {
            new bootstrap.Toast(savedToastEl).show();
        }
        var cleanUrl = window.location.pathname + window.location.search.replace(/[?&]saved=1/, '').replace(/^&/, '?');
        window.history.replaceState({}, '', cleanUrl);
    }

    function formatSize(bytes) {
        if (bytes >= 1024 * 1024) { return (bytes / (1024 * 1024)).toFixed(1) + ' MB'; }
        if (bytes >= 1024) { return (bytes / 1024).toFixed(1) + ' KB'; }
        return bytes + ' B';
    }

    // ---------------------------------------------------------------
    // Reference links - read-only unless canCollaborate (issuer/owner(s)/
    // admin), who get an Add button + modal (mirrors js/edit.js) and a
    // remove control per row.
    // ---------------------------------------------------------------
    var reflinkListEl = document.getElementById('okr-reflink-list');

    function renderReferenceLinks() {
        if (!reflinkListEl) { return; }
        if (referenceLinks.length === 0) {
            reflinkListEl.innerHTML = '<div class="okr-empty-state">No Reference Link added.</div>';
            return;
        }
        reflinkListEl.innerHTML = '';
        referenceLinks.forEach(function (link) {
            var row = document.createElement('div');
            row.className = 'okr-reflink-row';
            row.innerHTML = '<a href="' + link.url + '" target="_blank" rel="noopener">' + link.name + '</a>' +
                (canCollaborate ? '<span class="okr-reflink-remove" data-id="' + link.id + '">&times;</span>' : '');
            reflinkListEl.appendChild(row);
        });
    }

    renderReferenceLinks();

    if (canCollaborate && reflinkListEl) {
        var reflinkModalEl = document.getElementById('okr-reflink-modal');
        var reflinkModal = reflinkModalEl ? new bootstrap.Modal(reflinkModalEl) : null;

        var addReflinkBtn = document.getElementById('okr-add-reflink-btn');
        if (addReflinkBtn) {
            addReflinkBtn.addEventListener('click', function () {
                document.getElementById('reflink-name').value = '';
                document.getElementById('reflink-url').value = '';
                setError('reflink', '');
                reflinkModal.show();
            });
        }

        var reflinkSaveBtn = document.getElementById('reflink-save-btn');
        if (reflinkSaveBtn) {
            reflinkSaveBtn.addEventListener('click', function () {
                var name = document.getElementById('reflink-name').value.trim();
                var url = document.getElementById('reflink-url').value.trim();
                if (!name || !url) {
                    setError('reflink', 'Both name and URL are required.');
                    return;
                }

                var body = new URLSearchParams();
                body.set('action', 'addReferenceLink');
                body.set('id', CFG.card.id);
                body.set('name', name);
                body.set('url', url);

                reflinkSaveBtn.disabled = true;
                fetch(CFG.apiUrl, { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        reflinkSaveBtn.disabled = false;
                        if (res.success) {
                            referenceLinks.push({ id: res.id, name: name, url: url });
                            renderReferenceLinks();
                            setError('reflink-section', '');
                            reflinkModal.hide();
                        } else {
                            setError('reflink', res.message || 'Failed to save link.');
                        }
                    })
                    .catch(function () {
                        reflinkSaveBtn.disabled = false;
                        setError('reflink', 'Network error. Please try again.');
                    });
            });
        }

        reflinkListEl.addEventListener('click', function (e) {
            if (!e.target.classList.contains('okr-reflink-remove')) { return; }
            var el = e.target;
            var id = parseInt(el.getAttribute('data-id'), 10);
            el.style.pointerEvents = 'none';
            el.style.opacity = '0.4';
            var body = new URLSearchParams();
            body.set('action', 'deleteReferenceLink');
            body.set('id', id);
            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        referenceLinks = referenceLinks.filter(function (l) { return l.id !== id; });
                        renderReferenceLinks();
                    } else {
                        el.style.pointerEvents = '';
                        el.style.opacity = '';
                        setError('reflink-section', res.message || 'Failed to remove link.');
                    }
                })
                .catch(function () {
                    el.style.pointerEvents = '';
                    el.style.opacity = '';
                    setError('reflink-section', 'Network error. Please try again.');
                });
        });
    }

    // ---------------------------------------------------------------
    // Attachments - read-only unless canCollaborate (issuer/owner(s)/admin),
    // who get the drag-and-drop uploader (mirrors js/edit.js) and a remove
    // control per row.
    // ---------------------------------------------------------------
    var fileListEl = document.getElementById('okr-file-list');

    function renderAttachments() {
        if (!fileListEl) { return; }
        if (attachments.length === 0) {
            fileListEl.innerHTML = '<div class="okr-empty-state">No files attached.</div>';
            return;
        }
        fileListEl.innerHTML = '';
        attachments.forEach(function (file) {
            var row = document.createElement('div');
            row.className = 'okr-file-row';
            row.innerHTML = '<a class="okr-file-name" href="okr/download.php?id=' + file.id + '">' + file.original_name + '</a>' +
                '<span class="okr-file-size">' + formatSize(file.size) + '</span>' +
                (canCollaborate ? '<span class="okr-file-remove" data-id="' + file.id + '">&times;</span>' : '');
            fileListEl.appendChild(row);
        });
    }

    renderAttachments();

    if (canCollaborate && fileListEl) {
        var dropzoneEl = document.getElementById('okr-dropzone');
        var fileInputEl = document.getElementById('okr-file-input');

        function uploadFile(file) {
            setError('okr-file', '');
            var body = new FormData();
            body.set('action', 'addAttachment');
            body.set('id', CFG.card.id);
            body.set('file', file);

            var pendingRow = document.createElement('div');
            pendingRow.className = 'okr-file-row';
            pendingRow.innerHTML = '<span class="okr-file-name">Uploading ' + file.name + '...</span>';
            if (fileListEl.querySelector('.okr-empty-state')) { fileListEl.innerHTML = ''; }
            fileListEl.appendChild(pendingRow);

            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    pendingRow.remove();
                    if (res.success) {
                        attachments.push({ id: res.id, original_name: file.name, size: file.size, mime_type: file.type });
                        renderAttachments();
                    } else {
                        renderAttachments();
                        setError('okr-file', res.message || 'Failed to upload file.');
                    }
                })
                .catch(function () {
                    pendingRow.remove();
                    renderAttachments();
                    setError('okr-file', 'Network error while uploading. Please try again.');
                });
        }

        function handleFiles(fileList) {
            Array.prototype.forEach.call(fileList, uploadFile);
        }

        if (dropzoneEl && fileInputEl) {
            dropzoneEl.addEventListener('click', function () { fileInputEl.click(); });
            var filePickEl = document.getElementById('okr-file-pick');
            if (filePickEl) {
                filePickEl.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    fileInputEl.click();
                });
            }
            fileInputEl.addEventListener('change', function () {
                handleFiles(fileInputEl.files);
                fileInputEl.value = '';
            });
            dropzoneEl.addEventListener('dragover', function (e) {
                e.preventDefault();
                dropzoneEl.classList.add('okr-dropzone-active');
            });
            dropzoneEl.addEventListener('dragleave', function () {
                dropzoneEl.classList.remove('okr-dropzone-active');
            });
            dropzoneEl.addEventListener('drop', function (e) {
                e.preventDefault();
                dropzoneEl.classList.remove('okr-dropzone-active');
                handleFiles(e.dataTransfer.files);
            });
        }

        fileListEl.addEventListener('click', function (e) {
            if (!e.target.classList.contains('okr-file-remove')) { return; }
            var el = e.target;
            var id = parseInt(el.getAttribute('data-id'), 10);
            el.style.pointerEvents = 'none';
            el.style.opacity = '0.4';
            var body = new URLSearchParams();
            body.set('action', 'deleteAttachment');
            body.set('id', id);
            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        attachments = attachments.filter(function (f) { return f.id !== id; });
                        renderAttachments();
                    } else {
                        el.style.pointerEvents = '';
                        el.style.opacity = '';
                        setError('okr-file', res.message || 'Failed to remove file.');
                    }
                })
                .catch(function () {
                    el.style.pointerEvents = '';
                    el.style.opacity = '';
                    setError('okr-file', 'Network error. Please try again.');
                });
        });
    }

    // ---------------------------------------------------------------
    // Key Result Progress (read-only list, no edit controls)
    // ---------------------------------------------------------------
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var KR_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function formatKrDate(d) {
        if (!d) { return ''; }
        var parts = d.split('-');
        if (parts.length !== 3) { return d; }
        var m = KR_MONTHS[parseInt(parts[1], 10) - 1] || parts[1];
        return parts[2] + ' ' + m + ' ' + parts[0];
    }

    var krListEl = document.getElementById('okr-kr-list');
    if (krListEl) {
        var krList = CFG.keyResults ? CFG.keyResults.slice() : [];
        var atemMap = {};
        // Owner/Owner2 get the same full Key Result Progress access as
        // issuer/admin right here on view.php (add/edit/delete/status) - see
        // okrCanCollaborateOnCard in lib.php. Link ATEM stays edit.php-only
        // (an existing linked row still shows its read-only ATEM badge here).
        var canEditKrProgress = !!CFG.canCollaborate;
        var krStatusOptions = CFG.keyResultStatuses || [];

        var krRowHtml = function (row, index, isSubtask) {
            var atemCell = '<span class="okr-kr-col-value okr-kr-col-value--muted">&mdash;</span>';
            if (row.atem_id) {
                var atem = atemMap[row.atem_id];
                var atemLabel = atem ? escapeHtml(atem.title) : ('ATEM #' + row.atem_id);
                atemCell = '<div class="okr-kr-atem-badge">'
                    + '<i class="bi bi-link-45deg"></i> '
                    + '<a href="' + CFG.atemViewUrl + '?id=' + row.atem_id + '" target="_blank" rel="noopener">' + atemLabel + '</a>'
                    + '</div>';
            }

            var fromValue = row.start_date
                ? '<span class="okr-kr-col-value">' + formatKrDate(row.start_date) + '</span>'
                : '<span class="okr-kr-col-value okr-kr-col-value--muted">&mdash;</span>';
            var toValue = row.end_date
                ? '<span class="okr-kr-col-value">' + formatKrDate(row.end_date) + '</span>'
                : '<span class="okr-kr-col-value okr-kr-col-value--muted">&mdash;</span>';

            var statusCell;
            if (canEditKrProgress) {
                var options = krStatusOptions.map(function (s) {
                    return '<option value="' + s.id + '"' + (Number(s.id) === Number(row.status_id) ? ' selected' : '') + '>' + escapeHtml(s.value) + '</option>';
                }).join('');
                statusCell = '<select class="form-select form-select-sm okr-kr-status-select" data-kr-id="' + row.id + '">' + options + '</select>'
                    + '<div class="okr-form-error" id="kr-status-' + row.id + '-error"></div>';
            } else {
                statusCell = '<span class="okr-pill ' + row.pill_class + '">' + escapeHtml(row.status_value) + '</span>';
            }

            var actionsCell = '';
            if (canEditKrProgress) {
                actionsCell = '<div class="okr-kr-actions">'
                    + '<span class="okr-kr-col-label">Actions</span>'
                    + '<div class="okr-kr-actions-buttons">'
                    + '<button type="button" class="okr-kr-icon-btn okr-kr-icon-btn--edit okr-kr-edit" title="Edit"><i class="bi bi-pencil"></i></button>'
                    + '<button type="button" class="okr-kr-icon-btn okr-kr-icon-btn--delete okr-kr-delete" title="Delete"><i class="bi bi-x-lg"></i></button>'
                    + (isSubtask ? '' : '<button type="button" class="okr-kr-icon-btn okr-kr-icon-btn--add okr-kr-add-sub" title="Add Subtask"><i class="bi bi-plus-lg"></i></button>')
                    + '</div>'
                    + '</div>';
            }

            return '<div class="okr-kr-row' + (isSubtask ? ' okr-kr-row--subtask' : '') + '" data-id="' + row.id + '">'
                + '<div class="okr-kr-num">' + index + '</div>'
                + '<div class="okr-kr-body">'
                + '<div class="okr-kr-action-cell">'
                + '<span class="okr-kr-col-label">' + (isSubtask ? 'Action' : 'Key Result') + '</span>'
                + '<div class="okr-kr-action-title">' + escapeHtml(row.description) + '</div>'
                + '<div class="okr-kr-action-creator">' + escapeHtml(row.creator_name || '') + '</div>'
                + '</div>'
                + '<div class="okr-kr-col"><span class="okr-kr-col-label">From</span>' + fromValue + '</div>'
                + '<div class="okr-kr-col"><span class="okr-kr-col-label">To</span>' + toValue + '</div>'
                + '<div class="okr-kr-col"><span class="okr-kr-col-label">ATEM</span>' + atemCell + '</div>'
                + '<div class="okr-kr-col"><span class="okr-kr-col-label">Status</span>' + statusCell + '</div>'
                + '</div>'
                + actionsCell
                + '</div>';
        };

        function submitKrStatus(sel) {
            var id = sel.getAttribute('data-kr-id');
            var row = krList.filter(function (r) { return String(r.id) === String(id); })[0];
            if (!row) { return; }
            var newStatusId = sel.value;
            var previousStatusId = row.status_id;
            sel.disabled = true;
            setError('kr-status-' + id, '');

            var payload = new URLSearchParams();
            payload.set('action', 'updateKeyResult');
            payload.set('id', id);
            payload.set('description', row.description || '');
            payload.set('start_date', row.start_date || '');
            payload.set('end_date', row.end_date || '');
            payload.set('status_id', newStatusId);

            fetch(CFG.apiUrl, { method: 'POST', body: payload })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    sel.disabled = false;
                    if (res && res.success) {
                        row.status_id = Number(newStatusId);
                        row.status_value = res.status_value;
                        row.pill_class = res.pill_class;
                    } else {
                        sel.value = previousStatusId;
                        setError('kr-status-' + id, (res && res.message) || 'Could not update status.');
                    }
                })
                .catch(function () {
                    sel.disabled = false;
                    sel.value = previousStatusId;
                    setError('kr-status-' + id, 'Network error - could not update status.');
                });
        }

        function bindKrStatusHandlers() {
            Array.prototype.forEach.call(krListEl.querySelectorAll('.okr-kr-status-select'), function (sel) {
                sel.addEventListener('change', function () { submitKrStatus(sel); });
            });
        }

        function renderKeyResults() {
            var topLevel = krList.filter(function (r) { return !r.parent_id; });
            if (topLevel.length === 0) {
                krListEl.innerHTML = '<div class="okr-kr-empty">No Key Results added yet.</div>';
                return;
            }
            var html = '';
            topLevel.forEach(function (row, i) {
                html += krRowHtml(row, (i + 1), false);
                krList.filter(function (r) { return r.parent_id === row.id; }).forEach(function (sub, j) {
                    html += krRowHtml(sub, (i + 1) + '.' + (j + 1), true);
                });
            });
            krListEl.innerHTML = html;
            if (canEditKrProgress) { bindKrStatusHandlers(); }
        }

        function loadKeyResultsAndRender() {
            if (krList.some(function (r) { return r.atem_id; })) {
                fetch(CFG.atemApiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list-atems' })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        var items = (res && res.success) ? (res.data || []) : [];
                        items.forEach(function (a) { atemMap[a.id] = a; });
                        renderKeyResults();
                    })
                    .catch(function () { renderKeyResults(); });
            } else {
                renderKeyResults();
            }
        }

        loadKeyResultsAndRender();

        function reloadKeyResults() {
            var body = new URLSearchParams();
            body.set('action', 'listKeyResults');
            body.set('id', CFG.card.id);
            fetch(CFG.apiUrl + '?' + body.toString())
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) {
                        setError('okr-kr', res.message || 'Failed to load Key Results.');
                        return;
                    }
                    krList = res.data || [];
                    loadKeyResultsAndRender();
                })
                .catch(function () {
                    setError('okr-kr', 'Network error while loading Key Results.');
                });
        }

        // ---------------------------------------------------------------
        // Add/Edit/Delete (issuer/owner(s)/admin only) - mirrors js/edit.js's
        // own Key Result modal, minus the Link ATEM piece (stays edit.php-only).
        // ---------------------------------------------------------------
        if (canEditKrProgress) {
            var krModalEl = document.getElementById('okr-kr-modal');
            var krModal = krModalEl ? new bootstrap.Modal(krModalEl) : null;
            var krStartInput = document.getElementById('okr-kr-start');
            var krEndInput = document.getElementById('okr-kr-end');
            var krStatusSelect = document.getElementById('okr-kr-status');
            var krCreatedByInput = document.getElementById('okr-kr-created-by');

            function openKrModal(opts) {
                setError('okr-kr-modal', '');
                document.getElementById('okr-kr-id').value = opts.id || '';
                document.getElementById('okr-kr-parent-id').value = opts.parent_id || '';
                document.getElementById('okr-kr-desc').value = opts.description || '';
                krStartInput.value = opts.start_date || '';
                krEndInput.value = opts.end_date || '';
                if (opts.status_id) { krStatusSelect.value = opts.status_id; }
                else { krStatusSelect.selectedIndex = 0; }
                krCreatedByInput.value = opts.id ? (opts.creatorName || '') : (CFG.currentUserName || '');
                document.getElementById('okr-kr-modal-title').textContent = opts.parent_id ? (opts.id ? 'Edit Subtask' : 'Add Subtask') : (opts.id ? 'Edit Key Result' : 'Add Key Result');
                krModal.show();
            }

            var krAddBtn = document.getElementById('okr-kr-add-btn');
            if (krAddBtn) {
                krAddBtn.addEventListener('click', function () { openKrModal({}); });
            }

            krListEl.addEventListener('click', function (e) {
                var row = e.target.closest ? e.target.closest('.okr-kr-row') : null;
                if (!row) { return; }
                var id = parseInt(row.getAttribute('data-id'), 10);
                var data = krList.filter(function (r) { return r.id === id; })[0];
                if (!data) { return; }

                if (e.target.closest('.okr-kr-add-sub')) {
                    openKrModal({ parent_id: id });
                } else if (e.target.closest('.okr-kr-edit')) {
                    openKrModal({
                        id: data.id,
                        parent_id: data.parent_id,
                        description: data.description,
                        start_date: data.start_date,
                        end_date: data.end_date,
                        creatorName: data.creator_name,
                        status_id: data.status_id
                    });
                } else if (e.target.closest('.okr-kr-delete')) {
                    if (!window.confirm('Delete this ' + (data.parent_id ? 'Subtask' : 'Key Result') + '? This cannot be undone.')) { return; }
                    var body = new URLSearchParams();
                    body.set('action', 'deleteKeyResult');
                    body.set('id', id);
                    fetch(CFG.apiUrl, { method: 'POST', body: body })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res.success) { reloadKeyResults(); }
                            else { setError('okr-kr', res.message || 'Failed to delete.'); }
                        })
                        .catch(function () { setError('okr-kr', 'Network error. Please try again.'); });
                }
            });

            var krSaveBtn = document.getElementById('okr-kr-save-btn');
            if (krSaveBtn) {
                krSaveBtn.addEventListener('click', function () {
                    setError('okr-kr-modal', '');
                    var description = document.getElementById('okr-kr-desc').value.trim();
                    if (!description) {
                        setError('okr-kr-modal', 'Action Details is required.');
                        return;
                    }

                    var id = document.getElementById('okr-kr-id').value;
                    var parentId = document.getElementById('okr-kr-parent-id').value;

                    var body = new URLSearchParams();
                    body.set('action', id ? 'updateKeyResult' : 'createKeyResult');
                    if (id) {
                        body.set('id', id);
                    } else {
                        body.set('card_id', CFG.card.id);
                        if (parentId) { body.set('parent_id', parentId); }
                    }
                    body.set('description', description);
                    body.set('start_date', krStartInput.value);
                    body.set('end_date', krEndInput.value);
                    body.set('status_id', krStatusSelect.value);

                    krSaveBtn.disabled = true;
                    fetch(CFG.apiUrl, { method: 'POST', body: body })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            krSaveBtn.disabled = false;
                            if (res.success) {
                                krModal.hide();
                                reloadKeyResults();
                            } else {
                                setError('okr-kr-modal', res.message || 'Failed to save.');
                            }
                        })
                        .catch(function () {
                            krSaveBtn.disabled = false;
                            setError('okr-kr-modal', 'Network error. Please try again.');
                        });
                });
            }
        }
    }

    // ---------------------------------------------------------------
    // Star rating (0-5, half-star steps). Read-only display for everyone;
    // CEO/admin (CFG.canRate) get clickable half-star hit zones. Clicking
    // the currently-set value again clears the rating.
    // ---------------------------------------------------------------
    var starDisplayEl = document.getElementById('okr-star-display');
    var starMetaEl = document.getElementById('okr-star-meta');
    var currentRating = (CFG.card && CFG.card.rating !== null && CFG.card.rating !== undefined) ? Number(CFG.card.rating) : null;
    var starIcons = [];

    function starIconClass(value, i) {
        if (value === null) { return 'bi-star'; }
        if (value >= i) { return 'bi-star-fill'; }
        if (value >= i - 0.5) { return 'bi-star-half'; }
        return 'bi-star';
    }

    // Only repaints the 5 icon classNames - the hit-zone spans themselves are
    // built once in buildStars() and never touched again, so hover/click
    // never destroys the element the mouse is currently over.
    function paintStars(value) {
        for (var i = 0; i < starIcons.length; i++) {
            starIcons[i].className = 'bi ' + starIconClass(value, i + 1);
        }
    }

    function buildStars() {
        if (!starDisplayEl) { return; }
        starDisplayEl.innerHTML = '';
        starIcons = [];
        for (var i = 1; i <= 5; i++) {
            (function (starIndex) {
                var wrap = document.createElement('span');
                wrap.className = 'okr-star';
                var icon = document.createElement('i');
                starIcons.push(icon);
                wrap.appendChild(icon);

                if (CFG.canRate) {
                    ['left', 'right'].forEach(function (half) {
                        var hitValue = half === 'left' ? starIndex - 0.5 : starIndex;
                        var hit = document.createElement('span');
                        hit.className = 'okr-star-hit okr-star-hit-' + half;
                        hit.addEventListener('mouseenter', function () { paintStars(hitValue); });
                        hit.addEventListener('click', function () { submitRating(currentRating === hitValue ? null : hitValue); });
                        wrap.appendChild(hit);
                    });
                }

                starDisplayEl.appendChild(wrap);
            }(i));
        }
        paintStars(currentRating);
    }

    if (starDisplayEl && CFG.canRate) {
        starDisplayEl.addEventListener('mouseleave', function () { paintStars(currentRating); });
    }

    // "2026-07-27 14:05:00" -> "2026-07-27". Keeps the meta line to a date,
    // same as the PHP-rendered version on initial page load.
    function ratingDateOnly(datetime) {
        return datetime ? String(datetime).substring(0, 10) : '';
    }

    function ratingMetaText(value, ratedByName, ratedAt) {
        if (value === null || value === undefined) { return 'Not yet rated'; }
        var pct = Math.round((value / 5) * 100);
        var text = 'Rated ' + value.toFixed(1) + ' / 5 (' + pct + '%)';
        if (ratedByName) { text += ' by ' + ratedByName; }
        if (ratedAt) { text += ' on ' + ratingDateOnly(ratedAt); }
        return text;
    }

    function submitRating(value) {
        var payload = new URLSearchParams();
        payload.set('action', 'rateCard');
        payload.set('id', CFG.card.id);
        payload.set('rating', value === null ? '' : value);

        fetch(CFG.apiUrl, { method: 'POST', body: payload })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    currentRating = value;
                    paintStars(currentRating);
                    if (starMetaEl) {
                        starMetaEl.textContent = ratingMetaText(value, res.rated_by_name, res.rated_at);
                    }
                } else {
                    alert(res.message || 'Failed to save rating.');
                }
            })
            .catch(function () {
                alert('Network error. Please try again.');
            });
    }

    buildStars();

    // ---------------------------------------------------------------
    // Suspend / unsuspend
    // ---------------------------------------------------------------
    var suspendBtn = document.getElementById('okr-suspend-btn');
    var suspendReasonWrap = document.getElementById('okr-suspend-reason-wrap');
    var suspendReasonInput = document.getElementById('okr-suspend-reason');
    var suspendConfirmBtn = document.getElementById('okr-suspend-confirm-btn');
    var suspendModalEl = document.getElementById('okr-suspend-modal');
    var suspendModal = suspendModalEl ? new bootstrap.Modal(suspendModalEl) : null;
    var suspendFinalBtn = document.getElementById('okr-suspend-final-btn');

    if (suspendBtn && suspendReasonWrap) {
        suspendBtn.addEventListener('click', function () {
            setError('okr-suspend', '');
            suspendReasonWrap.style.display = 'block';
            suspendBtn.style.display = 'none';
        });
    }

    if (suspendConfirmBtn && suspendModal) {
        suspendConfirmBtn.addEventListener('click', function () {
            setError('okr-suspend-reason', '');
            var reason = suspendReasonInput.value.trim();
            if (!reason) {
                setError('okr-suspend-reason', 'A reason is required to suspend an OKR.');
                return;
            }
            suspendModal.show();
        });
    }

    if (suspendFinalBtn) {
        suspendFinalBtn.addEventListener('click', function () {
            var reason = suspendReasonInput.value.trim();

            var payload = new URLSearchParams();
            payload.set('action', 'suspendCard');
            payload.set('id', CFG.card.id);
            payload.set('reason', reason);

            fetch(CFG.apiUrl, { method: 'POST', body: payload })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        suspendModal.hide();
                        setError('okr-suspend-reason', res.message || 'Failed to suspend OKR.');
                    }
                })
                .catch(function () {
                    suspendModal.hide();
                    setError('okr-suspend-reason', 'Network error. Please try again.');
                });
        });
    }

    var unsuspendBtn = document.getElementById('okr-unsuspend-btn');
    if (unsuspendBtn) {
        unsuspendBtn.addEventListener('click', function () {
            setError('okr-suspend', '');

            var payload = new URLSearchParams();
            payload.set('action', 'unsuspendCard');
            payload.set('id', CFG.card.id);

            fetch(CFG.apiUrl, { method: 'POST', body: payload })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        setError('okr-suspend', res.message || 'Failed to unsuspend OKR.');
                    }
                })
                .catch(function () {
                    setError('okr-suspend', 'Network error. Please try again.');
                });
        });
    }

    // ---------------------------------------------------------------
    // Force Terminate - the other branch of Suspend > Appeal > Active/Force
    // Terminate, only reachable from Suspended, remark required (same
    // pattern as the Suspend reason box above).
    // ---------------------------------------------------------------
    var forceTerminateBtn = document.getElementById('okr-force-terminate-btn');
    var forceTerminateWrap = document.getElementById('okr-force-terminate-wrap');
    var forceTerminateRemarkInput = document.getElementById('okr-force-terminate-remark');
    var forceTerminateConfirmBtn = document.getElementById('okr-force-terminate-confirm-btn');

    if (forceTerminateBtn && forceTerminateWrap) {
        forceTerminateBtn.addEventListener('click', function () {
            setError('okr-suspend', '');
            forceTerminateWrap.style.display = 'block';
            forceTerminateBtn.style.display = 'none';
        });
    }

    if (forceTerminateConfirmBtn) {
        forceTerminateConfirmBtn.addEventListener('click', function () {
            setError('okr-force-terminate-remark', '');
            var remark = forceTerminateRemarkInput.value.trim();
            if (!remark) {
                setError('okr-force-terminate-remark', 'A remark is required to force terminate an OKR.');
                return;
            }
            if (!confirm('Force terminate this OKR? This cannot be undone.')) { return; }

            var payload = new URLSearchParams();
            payload.set('action', 'forceTerminateCard');
            payload.set('id', CFG.card.id);
            payload.set('remark', remark);

            fetch(CFG.apiUrl, { method: 'POST', body: payload })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        setError('okr-force-terminate-remark', res.message || 'Failed to force terminate OKR.');
                    }
                })
                .catch(function () {
                    setError('okr-force-terminate-remark', 'Network error. Please try again.');
                });
        });
    }

    // ---------------------------------------------------------------
    // Appeal Suspension - Issuer-only, one pending appeal per suspension
    // cycle.
    // ---------------------------------------------------------------
    var appealBtn = document.getElementById('okr-appeal-btn');
    var appealWrap = document.getElementById('okr-appeal-wrap');
    var appealJustificationInput = document.getElementById('okr-appeal-justification');
    var appealConfirmBtn = document.getElementById('okr-appeal-confirm-btn');

    if (appealBtn && appealWrap) {
        appealBtn.addEventListener('click', function () {
            setError('okr-appeal', '');
            appealWrap.style.display = 'block';
            appealBtn.style.display = 'none';
        });
    }

    if (appealConfirmBtn) {
        appealConfirmBtn.addEventListener('click', function () {
            setError('okr-appeal-justification', '');
            var justification = appealJustificationInput.value.trim();
            if (!justification) {
                setError('okr-appeal-justification', 'A justification is required to appeal.');
                return;
            }

            var payload = new URLSearchParams();
            payload.set('action', 'appealSuspension');
            payload.set('id', CFG.card.id);
            payload.set('justification', justification);

            fetch(CFG.apiUrl, { method: 'POST', body: payload })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        setError('okr-appeal-justification', res.message || 'Failed to submit appeal.');
                    }
                })
                .catch(function () {
                    setError('okr-appeal-justification', 'Network error. Please try again.');
                });
        });
    }

    // ---------------------------------------------------------------
    // Closure Date - Issuer/CEO/admin only, and only outside Draft/Active/
    // Failed/Suspended (see okrCanEditClosureDate() in lib.php). Standalone
    // save (not part of the read-only page's other fields).
    // ---------------------------------------------------------------
    var closureDateInput = document.getElementById('okr-closure-date');
    var closureDateSaveBtn = document.getElementById('okr-closure-date-save-btn');
    if (closureDateSaveBtn && closureDateInput) {
        closureDateSaveBtn.addEventListener('click', function () {
            setError('okr-closure-date', '');
            var value = closureDateInput.value;
            if (!value) {
                setError('okr-closure-date', 'Closure Date is required.');
                return;
            }
            if (CFG.isAdmin && CFG.currentStaffId && CFG.card.issuer_staff_id !== CFG.currentStaffId) {
                var warn = 'You are ADMIN and about to overwrite the Closure Date of an OKR issued by ' +
                    (CFG.card.issuer_name || 'another staff member') + ', not yourself. Continue?';
                if (!window.confirm(warn)) { return; }
            }

            var payload = new URLSearchParams();
            payload.set('action', 'updateClosureDate');
            payload.set('id', CFG.card.id);
            payload.set('closure_date', value);

            closureDateSaveBtn.disabled = true;
            fetch(CFG.apiUrl, { method: 'POST', body: payload })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    closureDateSaveBtn.disabled = false;
                    if (!res.success) {
                        setError('okr-closure-date', res.message || 'Failed to save Closure Date.');
                    }
                })
                .catch(function () {
                    closureDateSaveBtn.disabled = false;
                    setError('okr-closure-date', 'Network error. Please try again.');
                });
        });
    }

    // ---------------------------------------------------------------
    // Chat Box - per-card discussion thread, modeled after ATEM's Chat Box.
    // Own messages can be edited/unsent within a 60s window (also enforced
    // server-side); a 4s poll does a full resync so edits/unsends from other
    // viewers propagate, mirroring ATEM's polling pattern.
    // ---------------------------------------------------------------
    var chatWrapEl = document.getElementById('okr-chat-wrap');
    if (chatWrapEl && CFG.card && CFG.card.id) {
        var CHAT_EDIT_WINDOW_MS = 60000;
        var chatMessages = CFG.chatMessages ? CFG.chatMessages.slice() : [];
        var chatInput = document.getElementById('okr-chat-input');
        var chatSendBtn = document.getElementById('okr-chat-send-btn');
        var chatPollTimer = null;
        var chatVisibilityTimer = null;

        function chatWithinEditWindow(createdAt) {
            var createdMs = new Date(String(createdAt).replace(' ', 'T')).getTime();
            return (Date.now() - createdMs) < CHAT_EDIT_WINDOW_MS;
        }

        function chatBubbleHtml(m) {
            var mine = CFG.currentStaffId && m.sender_staff_id === CFG.currentStaffId;
            var canEdit = mine && chatWithinEditWindow(m.created_at);
            var actions = canEdit
                ? '<div class="okr-chat-bubble-actions">'
                    + '<button type="button" class="okr-chat-edit-btn" data-id="' + m.id + '">Edit</button>'
                    + '<button type="button" class="okr-chat-unsend-btn" data-id="' + m.id + '">Unsend</button>'
                    + '</div>'
                : '';
            return '<div class="okr-chat-bubble' + (mine ? ' okr-chat-bubble-mine' : '') + '" data-message-id="' + m.id + '" data-created-at="' + escapeHtml(m.created_at) + '">'
                + '<div class="okr-chat-bubble-header"><strong>' + escapeHtml(m.sender_name) + '</strong> <span class="okr-chat-bubble-time">' + escapeHtml(m.created_at) + '</span></div>'
                + '<div class="okr-chat-bubble-body" id="okr-chat-body-' + m.id + '">' + escapeHtml(m.message) + '</div>'
                + actions
                + '</div>';
        }

        function renderChat() {
            if (chatMessages.length === 0) {
                chatWrapEl.innerHTML = '<div class="okr-empty-state">No messages yet.</div>';
                return;
            }
            var html = '';
            chatMessages.forEach(function (m) { html += chatBubbleHtml(m); });
            chatWrapEl.innerHTML = html;
            chatWrapEl.scrollTop = chatWrapEl.scrollHeight;
        }

        // Strips expired Edit/Unsend buttons every 5s without a full
        // re-render, so the 60s window closes without waiting for a poll.
        function refreshChatActionVisibility() {
            chatMessages.forEach(function (m) {
                var mine = CFG.currentStaffId && m.sender_staff_id === CFG.currentStaffId;
                var bubble = chatWrapEl.querySelector('[data-message-id="' + m.id + '"]');
                if (!bubble) { return; }
                var actionsEl = bubble.querySelector('.okr-chat-bubble-actions');
                var stillEditable = mine && chatWithinEditWindow(m.created_at);
                if (!stillEditable && actionsEl) { actionsEl.remove(); }
            });
        }

        function loadChatMessages() {
            var body = new URLSearchParams();
            body.set('action', 'listChatMessages');
            body.set('id', CFG.card.id);
            fetch(CFG.apiUrl + '?' + body.toString())
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) { return; }
                    var incoming = res.data || [];
                    if (JSON.stringify(incoming) !== JSON.stringify(chatMessages)) {
                        chatMessages = incoming;
                        renderChat();
                    }
                })
                .catch(function () {});
        }

        function sendChatMessage() {
            var message = chatInput.value.trim();
            if (!message) { return; }
            setError('okr-chat', '');

            var body = new URLSearchParams();
            body.set('action', 'sendChatMessage');
            body.set('id', CFG.card.id);
            body.set('message', message);

            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        chatMessages.push({
                            id: res.id,
                            sender_staff_id: res.sender_staff_id,
                            sender_name: res.sender_name,
                            message: res.message,
                            created_at: res.created_at,
                            updated_at: res.updated_at
                        });
                        chatInput.value = '';
                        renderChat();
                    } else {
                        setError('okr-chat', res.message || 'Failed to send message.');
                    }
                })
                .catch(function () {
                    setError('okr-chat', 'Network error. Please try again.');
                });
        }

        function startEditChatMessage(id) {
            var m = chatMessages.filter(function (x) { return x.id === id; })[0];
            if (!m) { return; }
            var bodyEl = document.getElementById('okr-chat-body-' + id);
            if (!bodyEl) { return; }
            bodyEl.innerHTML = '<textarea class="okr-chat-edit-textarea" rows="2">' + escapeHtml(m.message) + '</textarea>'
                + '<div class="okr-chat-edit-actions">'
                + '<button type="button" class="okr-chat-save-btn" data-id="' + id + '">Save</button>'
                + '<button type="button" class="okr-chat-cancel-btn" data-id="' + id + '">Cancel</button>'
                + '</div>';
            bodyEl.querySelector('textarea').focus();
        }

        function cancelEditChatMessage(id) {
            var m = chatMessages.filter(function (x) { return x.id === id; })[0];
            var bodyEl = document.getElementById('okr-chat-body-' + id);
            if (m && bodyEl) { bodyEl.textContent = m.message; }
        }

        function saveEditChatMessage(id) {
            var bodyEl = document.getElementById('okr-chat-body-' + id);
            if (!bodyEl) { return; }
            var textarea = bodyEl.querySelector('textarea');
            var message = textarea ? textarea.value.trim() : '';
            if (!message) { return; }

            var body = new URLSearchParams();
            body.set('action', 'editChatMessage');
            body.set('id', id);
            body.set('message', message);

            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        var m = chatMessages.filter(function (x) { return x.id === id; })[0];
                        if (m) { m.message = res.message; }
                        renderChat();
                    } else {
                        alert(res.message || 'Failed to save message.');
                    }
                })
                .catch(function () {
                    alert('Network error. Please try again.');
                });
        }

        function unsendChatMessage(id) {
            if (!confirm('Unsend this message?')) { return; }
            var body = new URLSearchParams();
            body.set('action', 'unsendChatMessage');
            body.set('id', id);
            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        chatMessages = chatMessages.filter(function (x) { return x.id !== id; });
                        renderChat();
                    } else {
                        alert(res.message || 'Failed to unsend message.');
                    }
                })
                .catch(function () {
                    alert('Network error. Please try again.');
                });
        }

        chatWrapEl.addEventListener('click', function (e) {
            var editBtn = e.target.closest('.okr-chat-edit-btn');
            var unsendBtn = e.target.closest('.okr-chat-unsend-btn');
            var saveBtn = e.target.closest('.okr-chat-save-btn');
            var cancelBtn = e.target.closest('.okr-chat-cancel-btn');
            if (editBtn) { startEditChatMessage(parseInt(editBtn.getAttribute('data-id'), 10)); }
            else if (unsendBtn) { unsendChatMessage(parseInt(unsendBtn.getAttribute('data-id'), 10)); }
            else if (saveBtn) { saveEditChatMessage(parseInt(saveBtn.getAttribute('data-id'), 10)); }
            else if (cancelBtn) { cancelEditChatMessage(parseInt(cancelBtn.getAttribute('data-id'), 10)); }
        });

        if (chatSendBtn) {
            chatSendBtn.addEventListener('click', sendChatMessage);
        }
        if (chatInput) {
            chatInput.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    sendChatMessage();
                }
            });
        }

        renderChat();
        chatPollTimer = setInterval(loadChatMessages, 4000);
        chatVisibilityTimer = setInterval(refreshChatActionVisibility, 5000);
    }
})();
