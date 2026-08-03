(function () {
    var CFG = window.OKR_CONFIG || { staff: [], departments: [], levels: [] };

    // Bootstrap 5 popovers need explicit JS init - data-bs-toggle="popover"
    // alone does nothing without this.
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
        new bootstrap.Popover(el);
    });

    var referenceLinks = []; // { token, name, url }
    var stagedFiles = []; // { token, name, size }

    // Session-first draft lifecycle, mirrors ATEM's create page: every field
    // change marks the form dirty and (debounced) silently autosaves it to
    // the session via saveDraftState, so a refresh/reopen restores it. The
    // actual Leave-modal/navigation-guard wiring lives in bindLeaveGuard()
    // near the bottom, once saveOkr()/cancelOkr() exist to call into.
    var dirty = false;
    var leaving = false;
    var _syncTimer = null;

    function scheduleSync() {
        if (_syncTimer) { clearTimeout(_syncTimer); }
        _syncTimer = setTimeout(function () {
            var body = new URLSearchParams();
            body.set('action', 'saveDraftState');
            body.set('state', JSON.stringify(buildDraftState()));
            fetch(CFG.apiUrl, { method: 'POST', body: body }).catch(function () {});
        }, 500);
    }

    function markChanged() {
        dirty = true;
        scheduleSync();
    }
    document.addEventListener('input', markChanged, true);
    document.addEventListener('change', markChanged, true);

    function setError(id, msg) {
        var el = document.getElementById(id + '-error');
        if (el) el.textContent = msg || '';
    }

    // Disables a button and swaps its label while an async action runs, so a
    // slow request never looks like nothing happened - or worse, like it
    // silently finished - before it actually has. restoreButton undoes it;
    // callers only need to call it on failure paths, since success usually
    // hides the modal/re-renders the row the button lived in anyway.
    function setButtonLoading(btn, loadingText) {
        if (!btn) { return; }
        if (btn.dataset.originalText === undefined) { btn.dataset.originalText = btn.textContent; }
        btn.disabled = true;
        btn.textContent = loadingText;
    }
    function restoreButton(btn) {
        if (!btn) { return; }
        btn.disabled = false;
        if (btn.dataset.originalText !== undefined) { btn.textContent = btn.dataset.originalText; }
    }

    // Shared styled confirmation modal (never the browser's native confirm())
    // for any destructive/consequential action - Attachment/Reference Link
    // removal, Link ATEM picks, plain Key Result/Subtask deletion. onConfirm
    // may return a Promise; when it does, the OK button shows a loading
    // state and the modal stays open until it settles, instead of hiding
    // immediately and leaving the action to finish invisibly in the background.
    var _confirmModal = null, _confirmCb = null;
    function getConfirmModal() {
        if (!_confirmModal && typeof bootstrap !== 'undefined') {
            _confirmModal = new bootstrap.Modal(document.getElementById('atem-confirm-modal'));
            document.getElementById('atem-confirm-ok').addEventListener('click', function () {
                var cb = _confirmCb; _confirmCb = null;
                var okBtn = document.getElementById('atem-confirm-ok');
                var originalText = okBtn ? okBtn.textContent : '';
                if (okBtn) { okBtn.disabled = true; okBtn.textContent = 'Please wait...'; }
                var result = cb ? cb() : null;
                function settle() {
                    if (okBtn) { okBtn.disabled = false; okBtn.textContent = originalText; }
                    if (_confirmModal) { _confirmModal.hide(); }
                }
                if (result && typeof result.then === 'function') {
                    result.then(settle, settle);
                } else {
                    settle();
                }
            });
        }
        return _confirmModal;
    }
    function confirmAction(message, onConfirm, okLabel, okClass) {
        document.getElementById('atem-confirm-message').textContent = message;
        _confirmCb = onConfirm;
        var okBtn = document.getElementById('atem-confirm-ok');
        if (okBtn) {
            okBtn.disabled = false;
            okBtn.textContent = okLabel || 'Remove';
            okBtn.className = 'btn ' + (okClass || 'btn-danger');
        }
        var m = getConfirmModal();
        if (m) { m.show(); } else { onConfirm(); }
    }

    function clearErrors() {
        document.querySelectorAll('.okr-form-error').forEach(function (el) {
            el.textContent = '';
        });
    }

    // Jumps the user straight to the first invalid field on a failed save,
    // instead of leaving them to hunt for the (possibly off-screen) error.
    function scrollToFirstError() {
        var el = document.querySelector('.okr-form-error:not(:empty)');
        if (!el) { return; }
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        var fieldId = el.id.replace(/-error$/, '');
        var field = document.getElementById(fieldId);
        if (field && typeof field.focus === 'function') { field.focus(); }
    }

    function fillSelect(select, items, valueKey, labelKey) {
        items.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = item[valueKey];
            opt.textContent = item[labelKey];
            select.appendChild(opt);
        });
    }

    function formatSize(bytes) {
        if (bytes >= 1024 * 1024) { return (bytes / (1024 * 1024)).toFixed(1) + ' MB'; }
        if (bytes >= 1024) { return (bytes / 1024).toFixed(1) + ' KB'; }
        return bytes + ' B';
    }

    // ---------------------------------------------------------------
    // Start/End dates: End Date can't be before Start Date. Start Date
    // also can't be before today unless the admin's Backdate toggle is on
    // (atem/admin/index.php).
    // ---------------------------------------------------------------
    var startDateInput = document.getElementById('okr-start');
    var endDateInput = document.getElementById('okr-end');
    if (!CFG.backdateEnabled) {
        var todayStr = new Date().toISOString().slice(0, 10);
        startDateInput.min = todayStr;
        endDateInput.min = todayStr;
    }
    startDateInput.addEventListener('change', function () {
        endDateInput.min = startDateInput.value;
        if (endDateInput.value && startDateInput.value && endDateInput.value < startDateInput.value) {
            endDateInput.value = startDateInput.value;
        }
        refreshKrDateBounds();
    });
    endDateInput.addEventListener('change', function () {
        refreshKrDateBounds();
    });

    // Key Results can be staged before the OKR's own Start/End Date are
    // filled in (no min/max to constrain them yet) - once those dates are
    // set/changed, re-check every staged Key Result/Subtask against the new
    // range and surface a reminder banner if any of them now fall outside it.
    var krDateWarningEl = document.getElementById('okr-kr-date-warning');
    function refreshKrDateBounds() {
        var min = startDateInput.value || '';
        var max = endDateInput.value || '';

        var outOfRange = keyResults.some(function (row) {
            return (min && row.start_date && row.start_date < min)
                || (max && row.start_date && row.start_date > max)
                || (min && row.end_date && row.end_date < min)
                || (max && row.end_date && row.end_date > max);
        });
        if (krDateWarningEl) { krDateWarningEl.style.display = outOfRange ? '' : 'none'; }
    }

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

    // ---------------------------------------------------------------
    // Owner(s): ATEM ARCI-style tagging widget, restricted to a single
    // "A - Accountable" role capped at 2 members (OKR has no R/C/I).
    // ---------------------------------------------------------------
    var ownerState = []; // [{ staff_id, staff_name, dept_id, department_name }]

    var ownerDeptSelect = document.getElementById('okr-owner-dept-select');
    var ownerDeptSearch = document.getElementById('okr-owner-dept-search');
    var ownerStaffSearch = document.getElementById('okr-owner-staff-search');
    var ownerStaffList = document.getElementById('okr-owner-staff-list');
    var ownerAddBtn = document.getElementById('okr-owner-add-btn');
    var ownerMembersEl = document.getElementById('okr-owner-members');

    // Only departments with at least one staff member are worth showing -
    // an empty department would just lead to a dead-end staff picker.
    var ownerDeptsWithStaff = (CFG.departments || []).filter(function (d) {
        return (CFG.staff || []).some(function (s) { return (s.deptIds || []).indexOf(d.id) !== -1; });
    });
    fillSelect(ownerDeptSelect, ownerDeptsWithStaff, 'id', 'name');

    function ownerAssignedIds() {
        return ownerState.map(function (m) { return m.staff_id; });
    }

    function departmentName(deptId) {
        var d = (CFG.departments || []).filter(function (x) { return String(x.id) === String(deptId); })[0];
        return d ? d.name : '';
    }

    ownerDeptSearch.addEventListener('keyup', function () {
        var term = ownerDeptSearch.value.toLowerCase();
        var opts = ownerDeptSelect.options;
        for (var i = 0; i < opts.length; i++) {
            if (opts[i].value === '') { continue; }
            opts[i].hidden = opts[i].textContent.toLowerCase().indexOf(term) < 0;
        }
    });

    function renderOwnerStaffList() {
        var deptId = ownerDeptSelect.value;
        if (!deptId) {
            ownerStaffList.innerHTML = '<div class="text-muted" style="font-size:13px;">Select a department to load staff</div>';
            return;
        }
        var deptIdNum = parseInt(deptId, 10);
        var assigned = ownerAssignedIds();
        var term = ownerStaffSearch.value.toLowerCase();
        var staff = (CFG.staff || []).filter(function (s) {
            return (s.deptIds || []).indexOf(deptIdNum) !== -1;
        });

        var html = '';
        staff.forEach(function (s) {
            if (assigned.indexOf(s.id) !== -1) { return; }
            if (term && s.name.toLowerCase().indexOf(term) < 0) { return; }
            html += '<label class="okr-arci-staff-item">'
                + '<input type="checkbox" value="' + s.id + '" data-name="' + escapeHtml(s.name) + '"> '
                + '<span>' + escapeHtml(s.name) + '</span>'
                + '</label>';
        });
        ownerStaffList.innerHTML = html || '<div class="text-muted" style="font-size:13px;">No staff available</div>';
    }
    ownerDeptSelect.addEventListener('change', renderOwnerStaffList);
    ownerStaffSearch.addEventListener('keyup', renderOwnerStaffList);

    function renderOwnerMembers() {
        if (ownerState.length === 0) {
            ownerMembersEl.innerHTML = '<div class="okr-arci-empty">No owners assigned</div>';
        } else {
            var html = '';
            ownerState.forEach(function (m) {
                html += '<div class="okr-arci-member">'
                    + '<div class="okr-arci-member-info">'
                    + '<div class="okr-arci-member-dept">(' + escapeHtml(m.department_name || '') + ')</div>'
                    + '<div class="okr-arci-member-name">' + escapeHtml(m.staff_name) + '</div>'
                    + '</div>'
                    + '<span class="okr-arci-remove" data-staff="' + m.staff_id + '" title="Remove">&times;</span>'
                    + '</div>';
            });
            ownerMembersEl.innerHTML = html;
        }
    }

    ownerMembersEl.addEventListener('click', function (e) {
        if (e.target.classList.contains('okr-arci-remove')) {
            var staffId = parseInt(e.target.getAttribute('data-staff'), 10);
            ownerState = ownerState.filter(function (m) { return m.staff_id !== staffId; });
            markChanged();
            refreshOwnerUI();
        }
    });

    ownerAddBtn.addEventListener('click', function () {
        setError('okr-owner', '');
        var deptId = ownerDeptSelect.value;
        var checks = ownerStaffList.querySelectorAll('input[type="checkbox"]:checked');
        if (checks.length === 0) {
            setError('okr-owner', 'Please select at least one staff member.');
            return;
        }
        if (ownerState.length + checks.length > 2) {
            setError('okr-owner', 'Owner (Accountable) supports up to 2 members.');
            return;
        }
        var deptName = departmentName(deptId);
        for (var i = 0; i < checks.length; i++) {
            ownerState.push({
                staff_id: parseInt(checks[i].value, 10),
                staff_name: checks[i].getAttribute('data-name'),
                dept_id: deptId ? parseInt(deptId, 10) : null,
                department_name: deptName
            });
        }
        ownerDeptSelect.value = '';
        ownerStaffSearch.value = '';
        markChanged();
        refreshOwnerUI();
    });

    function refreshOwnerUI() {
        renderOwnerMembers();
        renderOwnerStaffList();
    }
    refreshOwnerUI();

    // ---------------------------------------------------------------
    // Reference links
    // ---------------------------------------------------------------
    var reflinkListEl = document.getElementById('okr-reflink-list');
    var reflinkModalEl = document.getElementById('okr-reflink-modal');
    var reflinkModal = new bootstrap.Modal(reflinkModalEl);

    function renderReferenceLinks() {
        if (referenceLinks.length === 0) {
            reflinkListEl.innerHTML = '<div class="okr-empty-state">No Reference Link added.</div>';
            return;
        }
        reflinkListEl.innerHTML = '';
        referenceLinks.forEach(function (link) {
            var row = document.createElement('div');
            row.className = 'okr-reflink-row';
            row.innerHTML = '<a href="' + link.url + '" target="_blank" rel="noopener">' + link.name + '</a>' +
                '<span class="okr-reflink-remove" data-token="' + link.token + '">&times;</span>';
            reflinkListEl.appendChild(row);
        });
    }

    document.getElementById('okr-add-reflink-btn').addEventListener('click', function () {
        document.getElementById('reflink-name').value = '';
        document.getElementById('reflink-url').value = '';
        setError('reflink', '');
        reflinkModal.show();
    });

    var reflinkSaveBtn = document.getElementById('reflink-save-btn');
    reflinkSaveBtn.addEventListener('click', function () {
        var name = document.getElementById('reflink-name').value.trim();
        var url = document.getElementById('reflink-url').value.trim();
        if (!name || !url) {
            setError('reflink', 'Both name and URL are required.');
            return;
        }

        var body = new URLSearchParams();
        body.set('action', 'stageReferenceLink');
        body.set('name', name);
        body.set('url', url);

        setButtonLoading(reflinkSaveBtn, 'Saving...');
        fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                restoreButton(reflinkSaveBtn);
                if (res.success) {
                    referenceLinks.push({ token: res.token, name: res.name, url: res.url });
                    markChanged();
                    renderReferenceLinks();
                    setError('reflink-section', '');
                    reflinkModal.hide();
                } else {
                    setError('reflink', res.message || 'Failed to save link.');
                }
            })
            .catch(function () {
                restoreButton(reflinkSaveBtn);
                setError('reflink', 'Network error. Please try again.');
            });
    });

    reflinkListEl.addEventListener('click', function (e) {
        if (!e.target.classList.contains('okr-reflink-remove')) { return; }
        var el = e.target;
        var token = el.getAttribute('data-token');
        el.style.pointerEvents = 'none';
        el.style.opacity = '0.4';
        var body = new URLSearchParams();
        body.set('action', 'removeStagedReferenceLink');
        body.set('token', token);
        fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function () {
                referenceLinks = referenceLinks.filter(function (l) { return l.token !== token; });
                markChanged();
                renderReferenceLinks();
            })
            .catch(function () {
                referenceLinks = referenceLinks.filter(function (l) { return l.token !== token; });
                markChanged();
                renderReferenceLinks();
            });
    });

    renderReferenceLinks();

    // ---------------------------------------------------------------
    // Attachments
    // ---------------------------------------------------------------
    var fileListEl = document.getElementById('okr-file-list');
    var dropzoneEl = document.getElementById('okr-dropzone');
    var fileInputEl = document.getElementById('okr-file-input');

    function renderFiles() {
        if (stagedFiles.length === 0) {
            fileListEl.innerHTML = '<div class="okr-empty-state">No files attached.</div>';
            return;
        }
        fileListEl.innerHTML = '';
        stagedFiles.forEach(function (file) {
            var row = document.createElement('div');
            row.className = 'okr-file-row';
            row.innerHTML = '<span class="okr-file-name">' + file.name + '</span>' +
                '<span class="okr-file-size">' + formatSize(file.size) + '</span>' +
                '<span class="okr-file-remove" data-token="' + file.token + '">&times;</span>';
            fileListEl.appendChild(row);
        });
    }

    function uploadFile(file) {
        setError('okr-file', '');
        var body = new FormData();
        body.set('action', 'stageAttachment');
        body.set('file', file);

        var pendingRow = document.createElement('div');
        pendingRow.className = 'okr-file-row';
        pendingRow.innerHTML = '<span class="okr-file-name">Uploading ' + escapeHtml(file.name) + '...</span>';
        if (fileListEl.querySelector('.okr-empty-state')) { fileListEl.innerHTML = ''; }
        fileListEl.appendChild(pendingRow);

        fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                pendingRow.remove();
                if (res.success) {
                    stagedFiles.push({ token: res.token, name: res.name, size: res.size });
                    markChanged();
                    renderFiles();
                } else {
                    renderFiles();
                    setError('okr-file', res.message || 'Failed to upload file.');
                }
            })
            .catch(function () {
                pendingRow.remove();
                renderFiles();
                setError('okr-file', 'Network error while uploading. Please try again.');
            });
    }

    function handleFiles(fileList) {
        Array.prototype.forEach.call(fileList, uploadFile);
    }

    dropzoneEl.addEventListener('click', function () { fileInputEl.click(); });
    document.getElementById('okr-file-pick').addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        fileInputEl.click();
    });
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

    fileListEl.addEventListener('click', function (e) {
        if (e.target.classList.contains('okr-file-remove')) {
            var el = e.target;
            var token = el.getAttribute('data-token');
            el.style.pointerEvents = 'none';
            el.style.opacity = '0.4';
            var body = new URLSearchParams();
            body.set('action', 'removeStagedAttachment');
            body.set('token', token);
            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function () {
                    stagedFiles = stagedFiles.filter(function (f) { return f.token !== token; });
                    markChanged();
                    renderFiles();
                })
                .catch(function () {
                    stagedFiles = stagedFiles.filter(function (f) { return f.token !== token; });
                    markChanged();
                    renderFiles();
                });
        }
    });

    renderFiles();

    // ---------------------------------------------------------------
    // Key Result Progress - staged in the session like reference
    // links/attachments, since there's no card_id yet. Top-level rows,
    // their subtasks, and ATEM links are all staged against tokens and
    // only resolved into real rows/columns once createCard succeeds
    // (okrFinalizeStagedKeyResults in lib.php).
    // ---------------------------------------------------------------
    var keyResults = []; // flat: { token, parent_token, description, creator_name, atem_id, status_id, status_value, pill_class, start_date, end_date }
    var krListEl = document.getElementById('okr-kr-list');
    var krModalEl = document.getElementById('okr-kr-modal');
    var krModal = new bootstrap.Modal(krModalEl);
    var krCreatedByInput = document.getElementById('okr-kr-created-by');
    var krStatusSelect = document.getElementById('okr-kr-status');
    var krStartInput = document.getElementById('okr-kr-start');
    var krEndInput = document.getElementById('okr-kr-end');
    var krTokenInput = document.getElementById('okr-kr-token');
    var krParentTokenInput = document.getElementById('okr-kr-parent-token');
    var krDeleteModalEl = document.getElementById('okr-kr-delete-modal');
    var krDeleteModal = new bootstrap.Modal(krDeleteModalEl);
    var krDeleteTarget = null; // { token, parent_token, atem_id }

    function krChildren(parentToken) {
        return keyResults.filter(function (r) { return r.parent_token === parentToken; });
    }

    function krRowHtml(row, index, isSubtask) {
        var atemCell = '<span class="okr-kr-col-value okr-kr-col-value--muted">&mdash;</span>';
        if (row.atem_id) {
            var atemLabel = row.atem_title || ('ATEM #' + row.atem_id);
            atemCell = '<div class="okr-kr-atem-badge">'
                + '<i class="bi bi-link-45deg"></i> '
                + '<a href="' + CFG.atemViewUrl + '?id=' + row.atem_id + '" target="_blank" rel="noopener">' + escapeHtml(atemLabel) + '</a>'
                + '</div>';
        }

        var fromValue = row.start_date
            ? '<span class="okr-kr-col-value">' + formatKrDate(row.start_date) + '</span>'
            : '<span class="okr-kr-col-value okr-kr-col-value--muted">&mdash;</span>';
        var toValue = row.end_date
            ? '<span class="okr-kr-col-value">' + formatKrDate(row.end_date) + '</span>'
            : '<span class="okr-kr-col-value okr-kr-col-value--muted">&mdash;</span>';

        return '<div class="okr-kr-row' + (isSubtask ? ' okr-kr-row--subtask' : '') + '" data-token="' + row.token + '">'
            + '<div class="okr-kr-num">' + index + '</div>'
            + '<div class="okr-kr-body">'
            + '<div class="okr-kr-action-cell">'
            + '<span class="okr-kr-col-label">Action</span>'
            + '<div class="okr-kr-action-title">' + escapeHtml(row.description) + '</div>'
            + '<div class="okr-kr-action-creator">' + escapeHtml(row.creator_name || '') + '</div>'
            + '</div>'
            + '<div class="okr-kr-col"><span class="okr-kr-col-label">From</span>' + fromValue + '</div>'
            + '<div class="okr-kr-col"><span class="okr-kr-col-label">To</span>' + toValue + '</div>'
            + '<div class="okr-kr-col"><span class="okr-kr-col-label">ATEM</span>' + atemCell + '</div>'
            + '<div class="okr-kr-col"><span class="okr-kr-col-label">Status</span><span class="okr-pill ' + row.pill_class + '">' + escapeHtml(row.status_value) + '</span></div>'
            + '</div>'
            + '<div class="okr-kr-actions">'
            + '<span class="okr-kr-col-label">Actions</span>'
            + '<div class="okr-kr-actions-buttons">'
            + '<button type="button" class="okr-kr-icon-btn okr-kr-icon-btn--edit okr-kr-edit" title="Edit"><i class="bi bi-pencil"></i></button>'
            + '<button type="button" class="okr-kr-icon-btn okr-kr-icon-btn--delete okr-kr-delete" title="Delete"><i class="bi bi-x-lg"></i></button>'
            + (isSubtask ? '' : '<button type="button" class="okr-kr-icon-btn okr-kr-icon-btn--add okr-kr-add-sub" title="Add Subtask"><i class="bi bi-plus-lg"></i></button>')
            + '<button type="button" class="okr-kr-icon-btn okr-kr-icon-btn--atem okr-kr-add-atem" title="ATEM"><i class="bi bi-file-earmark-plus"></i></button>'
            + '</div>'
            + '</div>'
            + '</div>';
    }

    function renderKeyResults() {
        var topLevel = keyResults.filter(function (r) { return !r.parent_token; });
        if (topLevel.length === 0) {
            krListEl.innerHTML = '<div class="okr-kr-empty">No Key Results added yet.</div>';
            refreshKrDateBounds();
            return;
        }
        var html = '';
        topLevel.forEach(function (row, i) {
            html += krRowHtml(row, (i + 1), false);
            krChildren(row.token).forEach(function (sub, j) {
                html += krRowHtml(sub, (i + 1) + '.' + (j + 1), true);
            });
        });
        krListEl.innerHTML = html;
        refreshKrDateBounds();
    }

    function openKrModal(opts) {
        setError('okr-kr-modal', '');
        krTokenInput.value = opts.token || '';
        krParentTokenInput.value = opts.parentToken || '';
        document.getElementById('okr-kr-desc').value = opts.description || '';
        krStartInput.value = opts.start_date || '';
        krEndInput.value = opts.end_date || '';
        if (opts.status_id) { krStatusSelect.value = opts.status_id; }
        else { krStatusSelect.selectedIndex = 0; }
        krCreatedByInput.value = opts.token ? (opts.creatorName || '') : (CFG.currentUserName || '');
        document.getElementById('okr-kr-modal-title').textContent = opts.parentToken
            ? (opts.token ? 'Edit Subtask' : 'Add Subtask')
            : (opts.token ? 'Edit Key Result' : 'Add Key Result');

        // Key Result / Subtask dates must fall within the OKR's own Start/End Date.
        krStartInput.min = startDateInput.value || '';
        krStartInput.max = endDateInput.value || '';
        krEndInput.min = startDateInput.value || '';
        krEndInput.max = endDateInput.value || '';

        krModal.show();
    }

    document.getElementById('okr-kr-add-btn').addEventListener('click', function () {
        openKrModal({});
    });

    var krSaveBtn = document.getElementById('okr-kr-save-btn');
    krSaveBtn.addEventListener('click', function () {
        setError('okr-kr-modal', '');
        var description = document.getElementById('okr-kr-desc').value.trim();
        if (!description) {
            setError('okr-kr-modal', 'Action Details is required.');
            return;
        }

        var editingToken = krTokenInput.value;
        var parentToken = krParentTokenInput.value;

        function stageIt() {
            var body = new URLSearchParams();
            body.set('action', parentToken ? 'stageKeyResultSubtask' : 'stageKeyResult');
            if (parentToken) { body.set('parent_token', parentToken); }
            body.set('description', description);
            body.set('start_date', krStartInput.value);
            body.set('end_date', krEndInput.value);
            body.set('status_id', krStatusSelect.value);

            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    restoreButton(krSaveBtn);
                    if (res.success) {
                        var newRow = {
                            token: res.token,
                            parent_token: parentToken || null,
                            description: res.description,
                            creator_name: res.creator_name,
                            atem_id: null,
                            start_date: res.start_date,
                            end_date: res.end_date,
                            status_id: res.status_id,
                            status_value: res.status_value,
                            pill_class: res.pill_class
                        };
                        if (editingToken) {
                            var idx = keyResults.findIndex(function (r) { return r.token === editingToken; });
                            if (idx !== -1) {
                                newRow.atem_id = keyResults[idx].atem_id;
                                keyResults[idx] = newRow;
                            } else {
                                keyResults.push(newRow);
                            }
                        } else {
                            keyResults.push(newRow);
                        }
                        markChanged();
                        renderKeyResults();
                        krModal.hide();
                    } else {
                        setError('okr-kr-modal', res.message || 'Failed to save Key Result.');
                    }
                })
                .catch(function () {
                    restoreButton(krSaveBtn);
                    setError('okr-kr-modal', 'Network error. Please try again.');
                });
        }

        setButtonLoading(krSaveBtn, 'Saving...');
        if (editingToken) {
            // Editing re-stages under a new token (staged rows have no id to
            // update in place yet) - remove the old one first, then replace it.
            var removeBody = new URLSearchParams();
            if (parentToken) {
                removeBody.set('action', 'removeStagedKeyResultSubtask');
                removeBody.set('parent_token', parentToken);
                removeBody.set('token', editingToken);
            } else {
                removeBody.set('action', 'removeStagedKeyResult');
                removeBody.set('token', editingToken);
            }
            fetch(CFG.apiUrl, { method: 'POST', body: removeBody }).then(stageIt).catch(stageIt);
        } else {
            stageIt();
        }
    });

    krListEl.addEventListener('click', function (e) {
        var row = e.target.closest('.okr-kr-row');
        if (!row) { return; }
        var token = row.getAttribute('data-token');
        var data = keyResults.filter(function (r) { return r.token === token; })[0];
        if (!data) { return; }

        if (e.target.closest('.okr-kr-add-atem')) {
            openAtemModal(token, data.description);
            return;
        }

        if (e.target.closest('.okr-kr-add-sub')) {
            openKrModal({ parentToken: token });
            return;
        }

        if (e.target.closest('.okr-kr-edit')) {
            openKrModal({
                token: data.token,
                parentToken: data.parent_token,
                description: data.description,
                start_date: data.start_date,
                end_date: data.end_date,
                creatorName: data.creator_name,
                status_id: data.status_id
            });
            return;
        }

        if (e.target.closest('.okr-kr-delete')) {
            if (data.atem_id) {
                openKrDeleteModal(data);
                return;
            }
            confirmAction(
                'Delete this ' + (data.parent_token ? 'Subtask' : 'Key Result') + '? This cannot be undone.',
                function () { return performDeleteStagedKeyResult(token, data.parent_token); },
                'Delete', 'btn-danger'
            );
        }
    });

    function performDeleteStagedKeyResult(token, parentToken) {
        var body = new URLSearchParams();
        if (parentToken) {
            body.set('action', 'removeStagedKeyResultSubtask');
            body.set('parent_token', parentToken);
            body.set('token', token);
        } else {
            body.set('action', 'removeStagedKeyResult');
            body.set('token', token);
        }
        var p = fetch(CFG.apiUrl, { method: 'POST', body: body }).catch(function () {});
        // Deleting a top-level Key Result also drops its staged subtasks client-side
        // (the session entry disappears server-side along with them).
        keyResults = keyResults.filter(function (r) { return r.token !== token && r.parent_token !== token; });
        markChanged();
        renderKeyResults();
        return p;
    }

    function deleteAtemBridge(atemId, remark) {
        return fetch(CFG.atemApiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete-atem', id: atemId, remarks: remark })
        }).then(function (r) { return r.json(); });
    }

    // ---------------------------------------------------------------
    // Delete a staged Key Result / Subtask when an ATEM is linked. Unlike
    // edit.js there's no reverse-link to unlink here - create.php's Link
    // ATEM never sets the ATEM's okr_id (no real card id exists yet), so
    // "Delete Key Result Only" just drops the staged row same as any other
    // delete. "Delete Key Result & ATEM" is still gated the same way -
    // requester must be the ATEM's Issuer and it must still be Draft/Active.
    // ---------------------------------------------------------------
    var krDeleteMsgEl = document.getElementById('okr-kr-delete-modal-message');
    var krDeleteAtemWrapEl = document.getElementById('okr-kr-delete-atem-wrap');
    var krDeleteRemarkInput = document.getElementById('okr-kr-delete-remark');
    var krDeleteAtemBtn = document.getElementById('okr-kr-delete-atem-btn');

    function openKrDeleteModal(data) {
        setError('okr-kr-delete-modal', '');
        krDeleteRemarkInput.value = '';
        krDeleteTarget = { token: data.token, parent_token: data.parent_token, atem_id: data.atem_id };

        var atemTitle = data.atem_title || ('ATEM #' + data.atem_id);
        var canDeleteAtem = !!(CFG.currentStaffId && data.atem_issuer_staff_id
            && CFG.currentStaffId === data.atem_issuer_staff_id
            && (data.atem_status_value === 'Active' || data.atem_status_value === 'Draft'));

        document.getElementById('okr-kr-delete-modal-title').textContent =
            'Delete this ' + (data.parent_token ? 'Subtask' : 'Key Result') + '?';

        if (canDeleteAtem) {
            krDeleteMsgEl.textContent = 'This is linked to ATEM "' + atemTitle + '". Choose whether to delete '
                + 'the ATEM as well, or keep it and just unlink it from this OKR.';
            krDeleteAtemWrapEl.style.display = '';
            krDeleteAtemBtn.style.display = '';
        } else {
            var why = data.atem_issuer_staff_id !== CFG.currentStaffId
                ? 'only the ATEM Issuer can delete it'
                : 'it is no longer Draft or Active';
            krDeleteMsgEl.textContent = 'This is linked to ATEM "' + atemTitle + '". The ATEM cannot be deleted '
                + 'from this page because ' + why + ' - deleting this Key Result will unlink it instead; the '
                + 'ATEM itself will remain.';
            krDeleteAtemWrapEl.style.display = 'none';
            krDeleteAtemBtn.style.display = 'none';
        }

        krDeleteModal.show();
    }

    var krDeleteOnlyBtn = document.getElementById('okr-kr-delete-only-btn');
    krDeleteOnlyBtn.addEventListener('click', function () {
        if (!krDeleteTarget) { return; }
        var target = krDeleteTarget;
        setButtonLoading(krDeleteOnlyBtn, 'Deleting...');
        performDeleteStagedKeyResult(target.token, target.parent_token)
            .then(function () {
                restoreButton(krDeleteOnlyBtn);
                krDeleteModal.hide();
            });
    });

    krDeleteAtemBtn.addEventListener('click', function () {
        if (!krDeleteTarget) { return; }
        var target = krDeleteTarget;
        var remark = krDeleteRemarkInput.value.trim();
        if (!remark) {
            setError('okr-kr-delete-modal', 'A reason is required to delete the ATEM.');
            return;
        }
        setError('okr-kr-delete-modal', '');
        setButtonLoading(krDeleteAtemBtn, 'Deleting...');
        deleteAtemBridge(target.atem_id, remark)
            .then(function (res) {
                if (!res.success) {
                    restoreButton(krDeleteAtemBtn);
                    setError('okr-kr-delete-modal', res.message || 'Failed to delete ATEM.');
                    return;
                }
                restoreButton(krDeleteAtemBtn);
                krDeleteModal.hide();
                performDeleteStagedKeyResult(target.token, target.parent_token);
            })
            .catch(function () {
                restoreButton(krDeleteAtemBtn);
                setError('okr-kr-delete-modal', 'Network error. Please try again.');
            });
    });

    // ---------------------------------------------------------------
    // Link ATEM modal: Search Existing ATEM (always visible) + Create New
    // ATEM (toggled inline below it, mirrors atem/create.php's own fields
    // and submits via atem/api.php's save-atem action exactly like that
    // page does). Wrapped in its own IIFE since it ports a large amount of
    // atem/js/create.js's logic - keeping it in a private scope avoids any
    // name collisions with this file's own escapeHtml/setError/fillSelect
    // (which have different signatures than ATEM's originals).
    // ---------------------------------------------------------------
    var AtemLink = (function () {
        var ATEM_CFG = window.ATEM_CREATE_CONFIG || {};
        var atemModalEl = document.getElementById('okr-kr-atem-modal');
        var atemModal = new bootstrap.Modal(atemModalEl);
        var atemTargetTokenInput = document.getElementById('okr-kr-atem-target-token');
        var pendingAtemTitle = ''; // Action text of the Key Result the modal was opened against - prefills the Create New ATEM title

        function $(id) { return document.getElementById(id); }

        // ---- link-back to OKR: persists atem_id onto the staged Key Result
        // (or Subtask) that the modal was opened against. Shared by both the
        // Search pick and the Create-New save. ----
        function linkAtemToTarget(atemId) {
            var body = new URLSearchParams();
            body.set('action', 'stageKeyResultAtemLink');
            body.set('token', atemTargetTokenInput.value);
            body.set('atem_id', atemId);
            return fetch(CFG.apiUrl, { method: 'POST', body: body }).then(function (r) { return r.json(); });
        }

        function applyLinkedAtem(atemId, title, issuerStaffId, statusValue) {
            var data = keyResults.filter(function (r) { return r.token === atemTargetTokenInput.value; })[0];
            if (data) {
                data.atem_id = atemId;
                data.atem_title = title || ('ATEM #' + atemId);
                data.atem_issuer_staff_id = (issuerStaffId != null) ? issuerStaffId : null;
                data.atem_status_value = statusValue || null;
            }
            markChanged();
            renderKeyResults();
        }

        // =============================================================
        // Search Existing ATEM
        // =============================================================
        var searchInput = $('okr-kr-atem-search');
        var listEl = $('okr-kr-atem-list');
        var searchErrorEl = $('okr-kr-atem-modal-error');
        var atemListCache = null;

        // atem-api's status field isn't always a plain string across
        // endpoints - fall back gracefully instead of stringifying an object.
        function atemStatusLabel(a) {
            if (typeof a.status === 'string') { return a.status; }
            if (a.status && typeof a.status === 'object' && a.status.value) { return a.status.value; }
            return '';
        }

        // list-atems-scoped (not the raw list-atems) - filtered server-side to
        // what this requester could actually open in ATEM itself, same grade/
        // department/ARCI visibility rule view.php applies.
        function fetchAtemList(callback) {
            if (atemListCache) { callback(atemListCache); return; }
            fetch(CFG.atemApiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'list-atems-scoped' })
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    atemListCache = (res && res.success) ? (res.data || []) : [];
                    callback(atemListCache);
                })
                .catch(function () { callback([]); });
        }

        function renderAtemOptions(items) {
            if (items.length === 0) {
                listEl.innerHTML = '<div class="okr-kr-empty">No ATEM cards found.</div>';
                return;
            }
            var html = '';
            items.forEach(function (a) {
                var title = a.title || ('ATEM #' + a.id);
                html += '<div class="okr-kr-atem-row" data-atem-id="' + a.id + '" data-atem-title="' + escapeHtml(title) + '">'
                    + '<div class="okr-kr-atem-row-title">' + escapeHtml(title) + ' <span class="okr-kr-atem-row-id">#' + a.id + '</span></div>'
                    + '<div class="okr-kr-atem-row-meta">' + escapeHtml(atemStatusLabel(a)) + '</div>'
                    + '</div>';
            });
            listEl.innerHTML = html;
        }

        searchInput.addEventListener('input', function () {
            var term = searchInput.value.toLowerCase().trim();
            var items = (atemListCache || []).filter(function (a) {
                return String(a.title || '').toLowerCase().indexOf(term) !== -1
                    || String(a.id).indexOf(term) !== -1;
            });
            renderAtemOptions(items);
        });

        listEl.addEventListener('click', function (e) {
            var row = e.target.closest ? e.target.closest('.okr-kr-atem-row') : null;
            if (!row) { return; }
            var atemId = parseInt(row.getAttribute('data-atem-id'), 10);
            var title = row.getAttribute('data-atem-title');
            var pickedItem = (atemListCache || []).filter(function (a) { return a.id === atemId; })[0];
            searchErrorEl.textContent = '';
            confirmAction('Link "' + title + '" to this OKR? This ATEM will be tied to this OKR card.', function () {
                return linkAtemToTarget(atemId)
                    .then(function (res) {
                        if (res.success) {
                            applyLinkedAtem(atemId, title, pickedItem ? pickedItem.issuer_staff_id : null, pickedItem ? atemStatusLabel(pickedItem) : null);
                            atemModal.hide();
                        } else {
                            searchErrorEl.textContent = res.message || 'Failed to link ATEM.';
                        }
                    })
                    .catch(function () {
                        searchErrorEl.textContent = 'Network error. Please try again.';
                    });
            }, 'Link ATEM', 'btn-primary');
        });

        // =============================================================
        // Create New ATEM - ported from atem/js/create.js, trimmed of what
        // only makes sense for a standalone page (session-draft persistence
        // across refresh, the leave-page/navigation guard): this is a
        // one-shot in-modal create, not a page with its own reload lifecycle.
        // Every field, validation rule, and the save-atem payload shape match
        // atem/create.php exactly.
        // =============================================================
        var quillEditor = null;
        var arciState = { A: [], R: [], C: [], I: [] };
        var reflinks = [];
        var atemStagedFiles = [];
        var staffType = null;
        var outletTags = [];
        var areaManagerTags = [];
        var arciScope = 'outlet';

        function money(n) {
            return 'RM' + (Math.round((Number(n) || 0) * 100) / 100).toFixed(2);
        }

        function atemApiCall(action, payload) {
            var body = { action: action };
            if (payload) {
                for (var k in payload) {
                    if (payload.hasOwnProperty(k)) { body[k] = payload[k]; }
                }
            }
            return fetch(CFG.atemApiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(function (r) { return r.json(); });
        }

        function readFileAsBase64(file) {
            return new Promise(function (resolve, reject) {
                var reader = new FileReader();
                reader.onload = function (e) {
                    var dataUrl = String(e.target.result);
                    var comma = dataUrl.indexOf(',');
                    resolve(comma >= 0 ? dataUrl.substring(comma + 1) : dataUrl);
                };
                reader.onerror = function () { reject(new Error('read failed')); };
                reader.readAsDataURL(file);
            });
        }

        function atemSetError(id, msg) {
            var el = $(id);
            if (el) { el.textContent = msg || ''; }
        }

        function clearAtemFormErrors() {
            ['atem-title-error', 'atem-level-error', 'atem-rule-error', 'tl-start-error',
                'tl-end-error', 'arci-error', 'atem-save-error', 'atem-file-error',
                'atem-reflink-section-error', 'atem-am-error', 'atem-reward-label-error'].forEach(function (id) {
                atemSetError(id, '');
            });
        }

        function scrollToFirstAtemError() {
            var ids = ['atem-title-error', 'atem-level-error', 'atem-rule-error', 'tl-start-error',
                'tl-end-error', 'arci-error', 'atem-reflink-section-error', 'atem-file-error', 'atem-save-error',
                'atem-am-error', 'atem-reward-label-error'];
            for (var i = 0; i < ids.length; i++) {
                var el = $(ids[i]);
                if (el && el.textContent.trim() !== '') {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
            }
        }

        function setStaffType(type) {
            staffType = type;
            var outletBtn = $('staff-type-outlet');
            var hqBtn = $('staff-type-hq');
            if (outletBtn) { outletBtn.classList.toggle('active', type === 'outlet'); }
            if (hqBtn) { hqBtn.classList.toggle('active', type === 'hq'); }

            var isHq = (type === 'hq');
            var hqOnly = atemModalEl.querySelectorAll('.atem-hq-only');
            var outletOnly = atemModalEl.querySelectorAll('.atem-outlet-only');
            for (var i = 0; i < hqOnly.length; i++) { hqOnly[i].classList.toggle('atem-hidden', !isHq); }
            for (var j = 0; j < outletOnly.length; j++) { outletOnly[j].classList.toggle('atem-hidden', isHq); }

            var incentiveSection = $('atem-incentive-section');
            if (incentiveSection) { incentiveSection.classList.toggle('atem-hidden', !isHq); }

            if (!isHq) {
                arciScope = 'outlet';
                if ($('arci-scope-outlet')) { $('arci-scope-outlet').checked = true; }
            }
            populateDepartments();
            renderStaffList();
        }

        // ---- area manager tagging (Outlet Staff) ----
        function amLabel(am) {
            return am.position ? (am.name + ' (' + am.position + ')') : am.name;
        }

        function renderAreaManagerTags() {
            var wrap = $('atem-am-tags');
            if (!wrap) { return; }
            if (!areaManagerTags.length) {
                wrap.innerHTML = '<span class="atem-empty-state">No outlet staff tagged.</span>';
                return;
            }
            var html = '';
            for (var i = 0; i < areaManagerTags.length; i++) {
                var label = amLabel(areaManagerTags[i]);
                html += '<span class="atem-outlet-tag">' + escapeHtml(label)
                    + '<span class="atem-outlet-tag-remove" data-id="' + areaManagerTags[i].id + '">&times;</span></span>';
            }
            wrap.innerHTML = html;
        }

        function syncAreaManagerPickerSelection() {
            var listEl2 = $('atem-am-picker-list');
            if (!listEl2) { return; }
            var items = listEl2.querySelectorAll('li');
            for (var i = 0; i < items.length; i++) {
                var id = parseInt(items[i].getAttribute('data-id'), 10) || 0;
                items[i].classList.toggle('selected', areaManagerTags.some(function (m) { return m.id === id; }));
            }
        }

        function addAreaManagerTag(id) {
            if (areaManagerTags.some(function (m) { return m.id === id; })) { return; }
            var am = (ATEM_CFG.areaManagers || []).filter(function (a) { return a.id === id; })[0];
            if (!am) { return; }
            areaManagerTags.push({ id: am.id, name: am.name, position: am.position, outlet_ids: am.outlet_ids });
            renderAreaManagerTags();
            syncAreaManagerPickerSelection();
            recomputeDerivedOutlets();
            autoAddAreaManagerToArci(am);
        }

        function autoAddAreaManagerToArci(am) {
            if (assignedStaffIds().indexOf(am.id) >= 0) { return; }
            arciState.A.push({
                staff_id: am.id,
                staff_name: amLabel(am),
                staff_dept_id: null,
                outlet_id: null,
                department_name: 'All Outlets',
                role: 'A',
                is_incentivised: false
            });
            renderArci();
        }

        function removeAreaManagerTag(id) {
            areaManagerTags = areaManagerTags.filter(function (m) { return m.id !== id; });
            renderAreaManagerTags();
            syncAreaManagerPickerSelection();
            recomputeDerivedOutlets();
        }

        function buildAreaManagerPicker() {
            var listEl2 = $('atem-am-picker-list');
            var searchEl = $('atem-am-picker-search');
            var btnEl = $('atem-am-picker-btn');
            var dropEl = $('atem-am-picker-dropdown');
            var wrapEl = $('atem-am-picker-wrap');
            if (!listEl2 || !btnEl || !dropEl) { return; }

            var managers = ATEM_CFG.areaManagers || [];
            var html = '';
            for (var i = 0; i < managers.length; i++) {
                var label = amLabel(managers[i]);
                html += '<li data-id="' + managers[i].id + '">' + escapeHtml(label) + '</li>';
            }
            listEl2.innerHTML = html || '<div class="atem-outlet-picker-empty">No outlet staff available</div>';
            syncAreaManagerPickerSelection();

            function openDropdown() {
                dropEl.classList.add('open');
                if (searchEl) { searchEl.value = ''; filterList(''); searchEl.focus(); }
            }
            function closeDropdown() { dropEl.classList.remove('open'); }

            function filterList(term) {
                var items = listEl2.querySelectorAll('li');
                var lower = term.toLowerCase();
                for (var j = 0; j < items.length; j++) {
                    var text = items[j].textContent || '';
                    items[j].classList.toggle('hidden', !(!lower || text.toLowerCase().indexOf(lower) >= 0));
                }
            }

            btnEl.addEventListener('click', function (e) {
                e.stopPropagation();
                if (dropEl.classList.contains('open')) { closeDropdown(); } else { openDropdown(); }
            });
            btnEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDropdown(); }
            });
            if (searchEl) {
                searchEl.addEventListener('input', function () { filterList(this.value); });
                searchEl.addEventListener('click', function (e) { e.stopPropagation(); });
            }
            listEl2.addEventListener('click', function (e) {
                var li = e.target.closest ? e.target.closest('li') : null;
                if (!li) { return; }
                addAreaManagerTag(parseInt(li.getAttribute('data-id'), 10) || 0);
            });
            document.addEventListener('click', function (e) {
                if (wrapEl && !wrapEl.contains(e.target)) { closeDropdown(); }
            });

            var tagsWrap = $('atem-am-tags');
            if (tagsWrap) {
                tagsWrap.addEventListener('click', function (e) {
                    if (e.target.classList.contains('atem-outlet-tag-remove')) {
                        removeAreaManagerTag(parseInt(e.target.getAttribute('data-id'), 10) || 0);
                    }
                });
            }
        }

        function recomputeDerivedOutlets() {
            var outletsById = {};
            (ATEM_CFG.outlets || []).forEach(function (o) { outletsById[o.id] = o.code; });

            var seen = {};
            var unionIds = [];
            areaManagerTags.forEach(function (m) {
                (m.outlet_ids || []).forEach(function (oid) {
                    if (!seen[oid]) { seen[oid] = true; unionIds.push(oid); }
                });
            });

            outletTags = unionIds
                .filter(function (id) { return outletsById.hasOwnProperty(id); })
                .map(function (id) { return { id: id, code: outletsById[id] }; });

            populateDepartments();
            renderStaffList();
            checkArciOutletOrphans();
        }

        function checkArciOutletOrphans() {
            var warnEl = $('atem-arci-orphan-warning');
            var textEl = $('atem-arci-orphan-warning-text');
            if (!warnEl || !textEl) { return; }
            if (staffType !== 'outlet') { warnEl.classList.add('atem-hidden'); return; }

            var validOutletIds = {};
            outletTags.forEach(function (o) { validOutletIds[o.id] = true; });

            var orphanNames = [];
            ['A', 'R', 'C', 'I'].forEach(function (role) {
                (arciState[role] || []).forEach(function (m) {
                    var oid = parseInt(m.outlet_id, 10) || 0;
                    if (oid && !validOutletIds[oid]) { orphanNames.push(m.staff_name); }
                });
            });

            if (orphanNames.length) {
                textEl.textContent = 'The following Project Team member(s) are tagged to an outlet no longer covered by the selected Outlet Staff(s) - please recheck: ' + orphanNames.join(', ');
                warnEl.classList.remove('atem-hidden');
            } else {
                warnEl.classList.add('atem-hidden');
            }
        }

        // ---- Quill RTE ----
        function initEditor() {
            if (typeof Quill === 'undefined') { return; }
            quillEditor = new Quill('#atem-description-editor', {
                theme: 'snow',
                modules: {
                    toolbar: {
                        container: [
                            [{ 'header': [1, 2, 3, false] }],
                            ['bold', 'italic', 'underline', 'strike'],
                            [{ 'color': [] }, { 'background': [] }],
                            [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                            [{ 'indent': '-1' }, { 'indent': '+1' }],
                            [{ 'align': [] }],
                            ['link', 'image'],
                            ['clean']
                        ],
                        handlers: {
                            'link': function (value) {
                                if (value) {
                                    var href = prompt('Enter the URL:');
                                    if (href) { this.quill.format('link', href); }
                                } else {
                                    this.quill.format('link', false);
                                }
                            },
                            'image': function () {
                                var input = document.createElement('input');
                                input.setAttribute('type', 'file');
                                input.setAttribute('accept', 'image/*');
                                input.click();

                                var q = this.quill;
                                input.onchange = function () {
                                    var file = input.files[0];
                                    if (file) {
                                        var reader = new FileReader();
                                        reader.onload = function (e) {
                                            var range = q.getSelection(true);
                                            q.insertEmbed(range.index, 'image', e.target.result, 'user');
                                            q.setSelection(range.index + 1);
                                        };
                                        reader.readAsDataURL(file);
                                    }
                                };
                            }
                        }
                    }
                },
                placeholder: 'Write the ATEM description in details here....'
            });
        }

        // ---- lookups / dropdowns ----
        function fillAtemSelect(select, items, valueKey, labelFn, placeholder) {
            if (!select) { return; }
            select.innerHTML = '';
            var opt = document.createElement('option');
            opt.value = '';
            opt.textContent = placeholder;
            select.appendChild(opt);
            for (var i = 0; i < items.length; i++) {
                var o = document.createElement('option');
                o.value = items[i][valueKey];
                o.textContent = labelFn(items[i]);
                select.appendChild(o);
            }
        }

        function populateLookups() {
            var levels = ATEM_CFG.levels || [];
            var rules = ATEM_CFG.rules || [];
            var pillars = ATEM_CFG.pillars || [];

            fillAtemSelect($('atem-level'), levels, 'id', function (l) {
                return l.level + ' - ' + l.system_name + ' (RM' + Number(l.incentive_value).toFixed(0) + ')';
            }, 'Select level');

            fillAtemSelect($('atem-rule'), rules, 'id', function (r) {
                return r.code + ' - ' + r.system_label;
            }, 'Select rule');

            fillAtemSelect($('atem-pillars'), pillars, 'id', function (p) {
                return p.name;
            }, 'Select pillar');
        }

        function selectedLevel() {
            var id = $('atem-level').value;
            var levels = ATEM_CFG.levels || [];
            for (var i = 0; i < levels.length; i++) {
                if (String(levels[i].id) === String(id)) { return levels[i]; }
            }
            return null;
        }

        function selectedRule() {
            var id = $('atem-rule').value;
            var rules = ATEM_CFG.rules || [];
            for (var i = 0; i < rules.length; i++) {
                if (String(rules[i].id) === String(id)) { return rules[i]; }
            }
            return null;
        }

        function getRuleLimits(rule) {
            var map = {
                'rule 1': { maxA: 2, maxR: 0 },
                'rule 2': { maxA: 1, maxR: 0 },
                'rule 3': { maxA: 1, maxR: 2 },
                'rule 4': { maxA: 2, maxR: 2 },
                'rule 5': { maxA: 1, maxR: 1 },
                'rule 6': { maxA: 2, maxR: 1 }
            };
            if (!rule) { return { maxA: 2, maxR: 2 }; }
            var code = String(rule.code).toLowerCase().trim();
            return map[code] || { maxA: 2, maxR: 2 };
        }

        function updateArciWarning() {
            var level = selectedLevel();
            var rule = selectedRule();

            if (!level || Number(level.incentive_value) === 0 || !rule) {
                atemSetError('arci-error', '');
                return;
            }

            var limits = getRuleLimits(rule);
            var incA = countIncentivised('A');
            var incR = countIncentivised('R');
            var msg = '';

            if (!arciState.A || arciState.A.length === 0) {
                msg = 'An Accountable (A) member is mandatory.';
            } else if (incA !== limits.maxA) {
                msg = 'This rule requires exactly ' + limits.maxA + ' Accountable (A) member(s) to be incentivised.';
            } else if (limits.maxR > 0 && incR !== limits.maxR) {
                msg = 'This rule requires exactly ' + limits.maxR + ' Responsible (R) member(s) to be incentivised.';
            }

            atemSetError('arci-error', msg);
        }

        function enforceRuleLimitsOnState() {
            var limits = getRuleLimits(selectedRule());
            var aInc = 0;
            (arciState['A'] || []).forEach(function (m) {
                if (m.is_incentivised) {
                    aInc++;
                    if (aInc > limits.maxA) { m.is_incentivised = false; }
                }
            });
            var rInc = 0;
            (arciState['R'] || []).forEach(function (m) {
                if (m.is_incentivised) {
                    if (limits.maxR === 0) { m.is_incentivised = false; return; }
                    rInc++;
                    if (rInc > limits.maxR) { m.is_incentivised = false; }
                }
            });
        }

        // ---- incentive calc ----
        function recalcIncentive() {
            var level = selectedLevel();
            var rule = selectedRule();
            var base = level ? Number(level.incentive_value) : 0;

            var ruleSelect = $('atem-rule');
            var note = $('inc-note');

            if (level && base === 0) {
                ruleSelect.value = '';
                ruleSelect.setAttribute('disabled', 'disabled');
            } else {
                ruleSelect.removeAttribute('disabled');
            }
            var ruleStar = $('rule-req-star');
            if (ruleStar) { ruleStar.style.display = (base > 0) ? '' : 'none'; }
            rule = selectedRule();

            var incentivisedA = countIncentivised('A');
            var incentivisedR = countIncentivised('R');
            var code = rule ? String(rule.code).toLowerCase() : '';
            var a = 0, r = 0, rDisplay = 0;
            if (base > 0 && rule) {
                if (code === 'rule 1') {
                    a = base * 0.5 * incentivisedA;
                    rDisplay = incentivisedA > 0 ? base * 0.5 : 0;
                    r = 0;
                } else if (code === 'rule 2') {
                    a = base * incentivisedA;
                    r = 0;
                } else if (code === 'rule 3') {
                    a = base * incentivisedA;
                    r = incentivisedR > 0 ? base * 0.5 : 0;
                    rDisplay = r;
                } else if (code === 'rule 4') {
                    a = base * 0.5 * incentivisedA;
                    r = incentivisedR > 0 ? base * 0.5 : 0;
                } else if (code === 'rule 5') {
                    a = base * incentivisedA;
                    r = base * 0.5 * incentivisedR;
                } else if (code === 'rule 6') {
                    a = base * 0.5 * incentivisedA;
                    r = base * 0.5 * incentivisedR;
                }
            }
            var total = a + r;

            $('inc-base').textContent = money(base);
            $('inc-a').textContent = money(a);
            $('inc-r').textContent = money(code === 'rule 1' ? rDisplay : r);
            $('inc-total').textContent = money(total);
            var rLabel = $('inc-r-label');
            if (rLabel) {
                if (code === 'rule 1') {
                    rLabel.textContent = 'A · Accountable (50% each)';
                } else if ((code === 'rule 3' || code === 'rule 4') && incentivisedR > 1) {
                    rLabel.textContent = 'R · Responsible ×' + incentivisedR + ' (pooled 50%)';
                } else if (code === 'rule 5' || code === 'rule 6') {
                    rLabel.textContent = 'R · Responsible (50%)';
                } else {
                    rLabel.textContent = 'R · Responsible';
                }
            }

            if (!level) {
                note.textContent = 'Select an ATEM Complexity Leveland rule to calculate incentive. C and I are not incentivised.';
            } else if (base === 0) {
                note.textContent = 'Level 1 carries no incentive payout.';
            } else if (!rule) {
                note.textContent = 'Select an incentive rule (required for Level 2-4).';
            } else {
                note.textContent = 'Projected amounts. Claimable only when closed as Complete or Complete with Excellence.';
            }
        }

        // ---- ARCI ----
        function countIncentivised(role) {
            var n = 0;
            (arciState[role] || []).forEach(function (m) { if (m.is_incentivised) { n++; } });
            return n;
        }

        function assignedStaffIds() {
            var ids = [];
            ['A', 'R', 'C', 'I'].forEach(function (role) {
                (arciState[role] || []).forEach(function (m) { ids.push(parseInt(m.staff_id, 10)); });
            });
            return ids;
        }

        function renderArci() {
            var cols = atemModalEl.querySelectorAll('.atem-arci-members');
            for (var i = 0; i < cols.length; i++) {
                var role = cols[i].getAttribute('data-role');
                var members = arciState[role] || [];
                if (members.length === 0) {
                    cols[i].innerHTML = '<div class="atem-arci-empty">No members assigned</div>';
                    continue;
                }
                var html = '';
                var _arciRule = selectedRule();
                var _arciLimits = getRuleLimits(_arciRule);
                for (var m = 0; m < members.length; m++) {
                    var mem = members[m];
                    var incentivisedHtml = '';
                    var _lvl = selectedLevel();
                    var _isLevel1 = _lvl && Number(_lvl.incentive_value) === 0;
                    var showChk = !_isLevel1 && staffType !== 'outlet' && ((role === 'A') || (role === 'R' && _arciLimits.maxR > 0));
                    if (showChk) {
                        var maxForRole = (role === 'A') ? _arciLimits.maxA : _arciLimits.maxR;
                        var atMax = !mem.is_incentivised && countIncentivised(role) >= maxForRole;
                        incentivisedHtml = '<label class="atem-arci-incentivised">'
                            + '<input type="checkbox" class="atem-arci-incentivised-chk"'
                            + ' data-staff="' + parseInt(mem.staff_id, 10) + '" data-role="' + role + '"'
                            + (mem.is_incentivised ? ' checked' : '')
                            + (atMax ? ' disabled' : '') + '>'
                            + ' Incentivised</label>';
                    }
                    html += '<div class="atem-arci-member">'
                        + '<div class="atem-arci-member-info">'
                        + '<div class="atem-arci-member-dept">(' + escapeHtml(mem.department_name || '') + ')</div>'
                        + '<div class="atem-arci-member-name">' + escapeHtml(mem.staff_name || '') + '</div>'
                        + '</div>'
                        + incentivisedHtml
                        + '<span class="atem-arci-remove" data-staff="' + parseInt(mem.staff_id, 10) + '" data-role="' + role + '" title="Remove">&times;</span>'
                        + '</div>';
                }
                cols[i].innerHTML = html;
            }
            renderStaffList();
            recalcIncentive();
            updateArciWarning();
        }

        function currentArciScope() {
            return (staffType === 'outlet') ? arciScope : 'department';
        }

        function populateDepartments() {
            var sel = $('arci-dept-select');
            var labelEl = $('arci-dept-label');
            var searchEl = $('arci-dept-search');
            var scopeToggle = $('arci-scope-toggle');
            var isOutlet = (staffType === 'outlet');
            if (scopeToggle) { scopeToggle.classList.toggle('atem-hidden', !isOutlet); }
            if (labelEl) { labelEl.textContent = isOutlet ? 'Scope' : 'Department'; }

            if (isOutlet && currentArciScope() === 'outlet') {
                if (searchEl) { searchEl.placeholder = 'Search outlet...'; }
                sel.innerHTML = '<option value="">Select outlet</option>';
                for (var j = 0; j < outletTags.length; j++) {
                    var oo = document.createElement('option');
                    oo.value = outletTags[j].id;
                    oo.textContent = outletTags[j].code;
                    sel.appendChild(oo);
                }
                return;
            }

            if (searchEl) { searchEl.placeholder = 'Search department...'; }
            var depts = ATEM_CFG.departments || [];
            sel.innerHTML = '<option value="">Select department</option>';
            for (var i = 0; i < depts.length; i++) {
                var o = document.createElement('option');
                o.value = depts[i].id;
                o.textContent = depts[i].name;
                sel.appendChild(o);
            }
        }

        function filterDepartments() {
            var term = $('arci-dept-search').value.toLowerCase();
            var opts = $('arci-dept-select').options;
            for (var i = 0; i < opts.length; i++) {
                if (opts[i].value === '') { continue; }
                opts[i].hidden = opts[i].textContent.toLowerCase().indexOf(term) < 0;
            }
        }

        function renderStaffList() {
            var listDiv = $('arci-staff-list');
            var deptId = $('arci-dept-select').value;
            var isOutletScope = (currentArciScope() === 'outlet');
            if (!deptId) {
                listDiv.innerHTML = '<div class="text-muted" style="font-size:13px;">Select ' + (isOutletScope ? 'an outlet' : 'a department') + ' to load staff</div>';
                return;
            }
            var staff = isOutletScope
                ? ((ATEM_CFG.staffByOutlet && ATEM_CFG.staffByOutlet[deptId]) ? ATEM_CFG.staffByOutlet[deptId] : [])
                : ((ATEM_CFG.staffByDept && ATEM_CFG.staffByDept[deptId]) ? ATEM_CFG.staffByDept[deptId] : []);
            var assigned = assignedStaffIds();
            var term = $('arci-staff-search').value.toLowerCase();

            var html = '';
            for (var i = 0; i < staff.length; i++) {
                if (assigned.indexOf(parseInt(staff[i].id, 10)) >= 0) { continue; }
                if (term && staff[i].name.toLowerCase().indexOf(term) < 0) { continue; }
                var displayName = (isOutletScope && staff[i].position) ? (staff[i].name + ' (' + staff[i].position + ')') : staff[i].name;
                html += '<label class="atem-arci-staff-item">'
                    + '<input type="checkbox" value="' + parseInt(staff[i].id, 10) + '" data-name="' + escapeHtml(displayName) + '"> '
                    + '<span>' + escapeHtml(displayName) + '</span>'
                    + '</label>';
            }
            listDiv.innerHTML = html || '<div class="text-muted" style="font-size:13px;">No staff available</div>';
        }

        function addSelectedMembers() {
            atemSetError('arci-error', '');
            var role = $('arci-role').value;
            if (!role) { atemSetError('arci-error', 'Please select a role first.'); return; }

            var scopeId = $('arci-dept-select').value;
            var checks = $('arci-staff-list').querySelectorAll('input[type="checkbox"]:checked');
            if (checks.length === 0) { atemSetError('arci-error', 'Please select at least one staff member.'); return; }

            var isOutletScope = (currentArciScope() === 'outlet');
            var scopeName = '';
            if (isOutletScope) {
                for (var j = 0; j < outletTags.length; j++) {
                    if (String(outletTags[j].id) === String(scopeId)) { scopeName = outletTags[j].code; break; }
                }
            } else {
                var depts = ATEM_CFG.departments || [];
                for (var k = 0; k < depts.length; k++) {
                    if (String(depts[k].id) === String(scopeId)) { scopeName = depts[k].name; break; }
                }
            }

            for (var i = 0; i < checks.length; i++) {
                arciState[role].push({
                    staff_id: parseInt(checks[i].value, 10),
                    staff_name: checks[i].getAttribute('data-name'),
                    staff_dept_id: (!isOutletScope && scopeId) ? parseInt(scopeId, 10) : null,
                    outlet_id: (isOutletScope && scopeId) ? parseInt(scopeId, 10) : null,
                    department_name: scopeName,
                    role: role,
                    is_incentivised: false
                });
            }

            $('arci-role').value = '';
            $('arci-dept-select').value = '';
            $('arci-staff-search').value = '';
            renderArci();
        }

        function removeMember(staffId, role) {
            var list = arciState[role] || [];
            for (var i = 0; i < list.length; i++) {
                if (parseInt(list[i].staff_id, 10) === parseInt(staffId, 10)) {
                    list.splice(i, 1);
                    break;
                }
            }
            renderArci();
        }

        var _pendingClear = {};
        function resetClearBtn(role, btn) {
            if (_pendingClear[role]) { clearTimeout(_pendingClear[role]); }
            delete _pendingClear[role];
            if (btn) {
                btn.textContent = 'Delete All';
                btn.classList.remove('atem-arci-clear-confirm');
            }
        }

        function clearRole(role, btn) {
            if (_pendingClear[role]) {
                resetClearBtn(role, btn);
                arciState[role] = [];
                renderArci();
                return;
            }
            atemSetError('arci-error', '');
            if (btn) {
                btn.textContent = 'Click again to confirm';
                btn.classList.add('atem-arci-clear-confirm');
            }
            _pendingClear[role] = setTimeout(function () { resetClearBtn(role, btn); }, 3000);
        }

        // ---- reference links ----
        var _reflinkModal = null;
        function getReflinkModal() {
            if (!_reflinkModal && typeof bootstrap !== 'undefined') {
                _reflinkModal = new bootstrap.Modal($('atem-reflink-modal'));
            }
            return _reflinkModal;
        }

        function renderReferenceLinks() {
            var wrap = $('atem-reflink-list');
            if (!reflinks.length) {
                wrap.innerHTML = '<div class="atem-empty-state">No Reference Link added.</div>';
                return;
            }
            var html = '<ol class="atem-reflink-ol">';
            for (var i = 0; i < reflinks.length; i++) {
                html += '<li><div class="atem-reflink-row">'
                    + '<a href="' + escapeHtml(reflinks[i].url) + '" target="_blank" rel="noopener">' + escapeHtml(reflinks[i].name) + '</a>'
                    + '<span class="atem-reflink-remove" data-index="' + i + '" title="Remove">&times;</span>'
                    + '</div></li>';
            }
            html += '</ol>';
            wrap.innerHTML = html;
        }

        function openReflinkModal() {
            $('atem-reflink-name').value = '';
            $('atem-reflink-url').value = '';
            atemSetError('atem-reflink-error', '');
            var m = getReflinkModal();
            if (m) { m.show(); }
        }

        function saveReferenceLink() {
            var name = $('atem-reflink-name').value.trim();
            var url = $('atem-reflink-url').value.trim();
            if (!name || !url) { atemSetError('atem-reflink-error', 'Please fill in both Name and URL.'); return; }
            try { new URL(url); } catch (e) { atemSetError('atem-reflink-error', 'Please enter a valid URL (e.g. https://example.com).'); return; }
            reflinks.push({ name: name, url: url });
            renderReferenceLinks();
            var m = getReflinkModal();
            if (m) { m.hide(); }
        }

        function removeReferenceLink(index) {
            reflinks.splice(index, 1);
            renderReferenceLinks();
        }

        // Pre-fills the ATEM's own Reference Link requirement with a link back
        // to the OKR being created, using the real Draft-status row
        // okrEnsureDraftCard() created the moment this page opened (see
        // create.php) - so this ATEM is traceable back to the OKR that spawned
        // it, and the issuer isn't forced to go find/paste that URL manually.
        // Added once per Create-pane open, not re-added if already present.
        function addOkrReferenceLinkIfMissing() {
            if (!CFG.draftCardId) { return; }
            // atem-api's reference_links.*.url validation requires a fully
            // qualified URL, not a bare relative path - resolve against
            // <base href="/odb/"> so this works in every environment
            // (localhost, staging, production) without hardcoding a host.
            var url = new URL(CFG.okrViewUrl + '?id=' + CFG.draftCardId, document.baseURI).href;
            if (reflinks.some(function (r) { return r.url === url; })) { return; }
            var objectiveEl = document.getElementById('okr-objective');
            var objective = objectiveEl ? objectiveEl.value.trim() : '';
            var name = objective ? ('OKR: ' + objective.slice(0, 60)) : ('OKR #' + CFG.draftCardId);
            reflinks.unshift({ name: name, url: url });
            renderReferenceLinks();
        }

        // ---- attachments ----
        var ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
        var MAX_BYTES = 10 * 1024 * 1024;

        function formatFileSize(bytes) {
            if (!bytes) { return '0 Bytes'; }
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }

        function fileExt(name) {
            var idx = name.lastIndexOf('.');
            return idx >= 0 ? name.substr(idx + 1).toLowerCase() : '';
        }

        function renderStagedFiles() {
            var wrap = $('atem-file-list');
            if (!atemStagedFiles.length) {
                wrap.innerHTML = '<div class="atem-empty-state">No files attached.</div>';
                return;
            }
            var html = '';
            for (var i = 0; i < atemStagedFiles.length; i++) {
                html += '<div class="atem-file-row">'
                    + '<span class="atem-file-name">' + escapeHtml(atemStagedFiles[i].name) + ' (' + formatFileSize(atemStagedFiles[i].size) + ')</span>'
                    + '<span class="atem-file-remove" data-index="' + i + '" title="Remove">&times;</span>'
                    + '</div>';
            }
            wrap.innerHTML = html;
        }

        function addStagedFiles(fileList) {
            atemSetError('atem-file-error', '');
            var toRead = [];
            for (var i = 0; i < fileList.length; i++) {
                var f = fileList[i];
                if (ALLOWED_EXT.indexOf(fileExt(f.name)) < 0) {
                    atemSetError('atem-file-error', f.name + ': file type not allowed.');
                    continue;
                }
                if (f.size > MAX_BYTES) {
                    atemSetError('atem-file-error', f.name + ': exceeds 10MB.');
                    continue;
                }
                var dup = false;
                for (var j = 0; j < atemStagedFiles.length; j++) {
                    if (atemStagedFiles[j].name === f.name && atemStagedFiles[j].size === f.size) { dup = true; break; }
                }
                if (!dup) { toRead.push(f); }
            }
            if (!toRead.length) { return; }

            Promise.all(toRead.map(function (file) {
                return readFileAsBase64(file).then(function (b64) {
                    return { name: file.name, type: file.type, size: file.size, content: b64 };
                });
            })).then(function (objs) {
                for (var k = 0; k < objs.length; k++) { atemStagedFiles.push(objs[k]); }
                renderStagedFiles();
            }).catch(function () {
                atemSetError('atem-file-error', 'Could not read the selected file(s).');
            });
        }

        function removeStagedFile(index) {
            atemStagedFiles.splice(index, 1);
            renderStagedFiles();
        }

        function bindAttachmentZone() {
            var dz = $('atem-dropzone');
            var fi = $('atem-file-input');
            if (!dz || !fi) { return; }

            $('atem-file-pick').addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                fi.click();
            });
            dz.addEventListener('click', function () { fi.click(); });
            fi.addEventListener('change', function () { addStagedFiles(fi.files); fi.value = ''; });

            ['dragenter', 'dragover'].forEach(function (ev) {
                dz.addEventListener(ev, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dz.classList.add('atem-dropzone-active');
                });
            });
            ['dragleave', 'drop'].forEach(function (ev) {
                dz.addEventListener(ev, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dz.classList.remove('atem-dropzone-active');
                });
            });
            dz.addEventListener('drop', function (e) {
                if (e.dataTransfer && e.dataTransfer.files) { addStagedFiles(e.dataTransfer.files); }
            });

            $('atem-file-list').addEventListener('click', function (e) {
                if (e.target.classList.contains('atem-file-remove')) {
                    var idx = parseInt(e.target.getAttribute('data-index'), 10);
                    confirmAction('Remove this attachment?', function () { removeStagedFile(idx); });
                }
            });
        }

        // ---- save ----
        function flattenArci() {
            return arciState.A.concat(arciState.R, arciState.C, arciState.I);
        }

        function validateFinal() {
            clearAtemFormErrors();
            var title = $('atem-title').value.trim();
            var startDate = $('tl-start').value;
            var endDate = $('tl-end').value;

            if (!title) { atemSetError('atem-title-error', 'ATEM Title is required.'); $('atem-title').focus(); return false; }

            if (staffType === 'outlet') {
                if (!areaManagerTags.length) {
                    atemSetError('atem-am-error', 'At least one Outlet Staff is required.');
                    return false;
                }
            } else {
                var levelId = $('atem-level').value;
                var ruleId = $('atem-rule').value;
                var level = selectedLevel();
                if (!levelId) { atemSetError('atem-level-error', 'ATEM Complexity Levelis required.'); $('atem-level').focus(); return false; }
                if (level && Number(level.incentive_value) > 0 && !ruleId) {
                    atemSetError('atem-rule-error', 'Incentive Rule is required for Level 2-4.');
                    $('atem-rule').focus();
                    return false;
                }
            }

            if (!startDate) { atemSetError('tl-start-error', 'Start Date is required.'); $('tl-start').focus(); return false; }
            if (!endDate) { atemSetError('tl-end-error', 'End Date is required.'); $('tl-end').focus(); return false; }
            if (!arciState.A || arciState.A.length === 0) {
                atemSetError('arci-error', 'An Accountable (A) member is mandatory.');
                return false;
            }
            if (staffType !== 'outlet') {
                var rule = selectedRule();
                var lvl = selectedLevel();
                if (lvl && Number(lvl.incentive_value) > 0 && rule) {
                    var limits = getRuleLimits(rule);
                    var incA = countIncentivised('A');
                    var incR = countIncentivised('R');
                    if (incA !== limits.maxA) {
                        atemSetError('arci-error', 'This rule requires exactly ' + limits.maxA + ' Accountable (A) member(s) to be incentivised.');
                        return false;
                    }
                    if (limits.maxR > 0 && incR !== limits.maxR) {
                        atemSetError('arci-error', 'This rule requires exactly ' + limits.maxR + ' Responsible (R) member(s) to be incentivised.');
                        return false;
                    }
                }
            }
            if (!reflinks || reflinks.length === 0) {
                atemSetError('atem-reflink-section-error', 'At least one Reference Link is required.');
                return false;
            }
            return true;
        }

        function collectPayload() {
            var levelId = $('atem-level').value;
            var ruleId = $('atem-rule').value;
            var pillarId = $('atem-pillars') ? $('atem-pillars').value : '';
            var rewardLabel = $('atem-reward-label') ? $('atem-reward-label').value : '';
            var description = '';
            if (quillEditor) {
                description = (quillEditor.getText().trim() === '') ? '' : quillEditor.root.innerHTML;
            }
            return {
                title: $('atem-title').value.trim(),
                description: description,
                atem_type: (staffType === 'outlet') ? 2 : 1,
                level_structure_id: levelId ? parseInt(levelId, 10) : null,
                incentive_rule_id: ruleId ? parseInt(ruleId, 10) : null,
                pillar_id: pillarId ? parseInt(pillarId, 10) : null,
                reward_label: rewardLabel || null,
                outlet_ids: outletTags.map(function (o) { return o.id; }),
                area_manager_ids: areaManagerTags.map(function (m) { return m.id; }),
                start_date: $('tl-start').value || null,
                end_date: $('tl-end').value || null,
                arci: flattenArci(),
                reference_links: reflinks,
                mode: 'final'
            };
        }

        function saveAtem() {
            if (!validateFinal()) { scrollToFirstAtemError(); return; }
            atemSetError('atem-save-error', '');

            var btn = $('okr-kr-atem-create-save-btn');
            if (btn) { btn.disabled = true; btn.textContent = 'Creating...'; }

            var payload = collectPayload();
            payload.attachments = atemStagedFiles;
            atemApiCall('save-atem', { data: payload }).then(function (res) {
                if (res && res.success && res.data && res.data.id) {
                    return linkAtemToTarget(res.data.id).then(function (linkRes) {
                        if (btn) { btn.disabled = false; btn.textContent = 'Create & Link'; }
                        if (linkRes.success) {
                            // Freshly created via this flow, always mode: 'final' -> issuer
                            // is the current user and status is Active (see collectPayload).
                            applyLinkedAtem(res.data.id, payload.title, CFG.currentStaffId, 'Active');
                            atemModal.hide();
                        } else {
                            atemSetError('atem-save-error', linkRes.message || 'ATEM created but failed to link.');
                        }
                    });
                }
                atemSetError('atem-save-error', (res && res.message) ? res.message : 'Failed to create ATEM.');
                scrollToFirstAtemError();
                if (btn) { btn.disabled = false; btn.textContent = 'Create & Link'; }
            }).catch(function () {
                atemSetError('atem-save-error', 'Network error while saving.');
                scrollToFirstAtemError();
                if (btn) { btn.disabled = false; btn.textContent = 'Create & Link'; }
            });
        }

        // ---- type switch (with reset confirmation once data exists) ----
        function hasMeaningfulFormData() {
            if ($('atem-title').value.trim() !== '') { return true; }
            if (quillEditor && quillEditor.getText().trim() !== '') { return true; }
            if ($('tl-start').value || $('tl-end').value) { return true; }
            if ($('atem-level') && $('atem-level').value) { return true; }
            if ($('atem-rule') && $('atem-rule').value) { return true; }
            if ($('atem-pillars') && $('atem-pillars').value) { return true; }
            if (outletTags.length || areaManagerTags.length) { return true; }
            if (arciState.A.length || arciState.R.length || arciState.C.length || arciState.I.length) { return true; }
            if (reflinks.length) { return true; }
            if (atemStagedFiles.length) { return true; }
            return false;
        }

        function requestStaffTypeChange(type) {
            if (type === staffType) { return; }
            if (hasMeaningfulFormData()) {
                var modalEl = $('atem-type-switch-modal');
                if (modalEl && typeof bootstrap !== 'undefined') {
                    bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    modalEl.setAttribute('data-pending-type', type);
                }
                return;
            }
            setStaffType(type);
        }

        // ---- full reset (called each time the Create pane is opened fresh) ----
        function resetCreateForm() {
            clearAtemFormErrors();
            arciState = { A: [], R: [], C: [], I: [] };
            reflinks = [];
            atemStagedFiles = [];
            outletTags = [];
            areaManagerTags = [];
            arciScope = 'outlet';
            $('atem-title').value = pendingAtemTitle || '';
            if (quillEditor) { quillEditor.setText(''); }
            $('tl-start').value = '';
            $('tl-end').value = '';
            if ($('atem-reward-label')) { $('atem-reward-label').value = ''; }
            renderAreaManagerTags();
            syncAreaManagerPickerSelection();
            renderReferenceLinks();
            renderStagedFiles();
            setStaffType('hq');
            renderArci();
            var btn = $('okr-kr-atem-create-save-btn');
            if (btn) { btn.disabled = !!ATEM_CFG.apiUnavailable; btn.textContent = 'Create & Link'; }
        }

        function collapseCreateWrap() {
            $('okr-kr-atem-create-wrap').style.display = 'none';
            $('okr-kr-atem-create-toggle').style.display = '';
        }

        // ---- wiring (once) ----
        function bind() {
            $('staff-type-outlet').addEventListener('click', function () { requestStaffTypeChange('outlet'); });
            $('staff-type-hq').addEventListener('click', function () { requestStaffTypeChange('hq'); });

            var typeSwitchResetBtn = $('atem-type-switch-reset-btn');
            if (typeSwitchResetBtn) {
                typeSwitchResetBtn.addEventListener('click', function () {
                    var modalEl = $('atem-type-switch-modal');
                    var pendingType = modalEl ? modalEl.getAttribute('data-pending-type') : null;
                    resetCreateForm();
                    if (pendingType) { setStaffType(pendingType); }
                    if (modalEl && typeof bootstrap !== 'undefined') {
                        var inst = bootstrap.Modal.getInstance(modalEl);
                        if (inst) { inst.hide(); }
                    }
                });
            }

            $('atem-level').addEventListener('change', function () { recalcIncentive(); renderArci(); updateArciWarning(); });
            $('atem-rule').addEventListener('change', function () {
                enforceRuleLimitsOnState();
                renderArci();
                recalcIncentive();
                updateArciWarning();
            });

            $('arci-dept-search').addEventListener('keyup', filterDepartments);
            $('arci-dept-select').addEventListener('change', renderStaffList);
            $('arci-staff-search').addEventListener('keyup', renderStaffList);
            $('arci-add-btn').addEventListener('click', addSelectedMembers);
            if ($('arci-scope-outlet')) {
                $('arci-scope-outlet').addEventListener('change', function () {
                    arciScope = 'outlet';
                    populateDepartments();
                    renderStaffList();
                });
            }
            if ($('arci-scope-department')) {
                $('arci-scope-department').addEventListener('change', function () {
                    arciScope = 'department';
                    populateDepartments();
                    renderStaffList();
                });
            }

            $('arci-grid').addEventListener('click', function (e) {
                var t = e.target;
                if (t.classList.contains('atem-arci-remove')) {
                    var sId = parseInt(t.getAttribute('data-staff'), 10);
                    var sRole = t.getAttribute('data-role');
                    removeMember(sId, sRole);
                } else if (t.classList.contains('atem-arci-clear')) {
                    clearRole(t.getAttribute('data-role'), t);
                } else if (t.classList.contains('atem-arci-incentivised-chk')) {
                    var chkStaff = parseInt(t.getAttribute('data-staff'), 10);
                    var chkRole = t.getAttribute('data-role');
                    var chkVal = t.checked;
                    if (chkVal) {
                        var chkLimits = getRuleLimits(selectedRule());
                        var chkMax = (chkRole === 'A') ? chkLimits.maxA : chkLimits.maxR;
                        if (countIncentivised(chkRole) >= chkMax) {
                            t.checked = false;
                            atemSetError('arci-error', 'Maximum incentivised ' + chkRole + ' members (' + chkMax + ') already reached for this rule.');
                            return;
                        }
                    }
                    atemSetError('arci-error', '');
                    (arciState[chkRole] || []).forEach(function (m) {
                        if (parseInt(m.staff_id, 10) === chkStaff) { m.is_incentivised = chkVal; }
                    });
                    recalcIncentive();
                    renderArci();
                }
            });

            $('atem-add-reflink-btn').addEventListener('click', openReflinkModal);
            $('atem-reflink-save-btn').addEventListener('click', saveReferenceLink);
            $('atem-reflink-list').addEventListener('click', function (e) {
                if (e.target.classList.contains('atem-reflink-remove')) {
                    var idx = parseInt(e.target.getAttribute('data-index'), 10);
                    confirmAction('Remove this reference link?', function () { removeReferenceLink(idx); });
                }
            });

            bindAttachmentZone();

            $('okr-kr-atem-create-save-btn').addEventListener('click', saveAtem);
            $('okr-kr-atem-create-toggle').addEventListener('click', function () {
                $('okr-kr-atem-create-wrap').style.display = 'block';
                this.style.display = 'none';
                addOkrReferenceLinkIfMissing();
            });

            var orphanCloseBtn = $('atem-arci-orphan-warning-close');
            if (orphanCloseBtn) {
                orphanCloseBtn.addEventListener('click', function () {
                    $('atem-arci-orphan-warning').classList.add('atem-hidden');
                });
            }
        }

        var initialized = false;
        function initOnce() {
            if (initialized) { return; }
            initialized = true;
            populateLookups();
            populateDepartments();
            buildAreaManagerPicker();
            initEditor();
            bind();
            setStaffType('hq');
            recalcIncentive();
            renderArci();
            renderReferenceLinks();
            renderStagedFiles();
            if (!(ATEM_CFG.backdate && ATEM_CFG.backdate.enabled)) {
                var _d = new Date();
                var today = _d.getFullYear() + '-' + (_d.getMonth() + 1 < 10 ? '0' + (_d.getMonth() + 1) : '' + (_d.getMonth() + 1)) + '-' + (_d.getDate() < 10 ? '0' + _d.getDate() : '' + _d.getDate());
                if ($('tl-start')) { $('tl-start').setAttribute('min', today); }
                if ($('tl-end')) { $('tl-end').setAttribute('min', today); }
            }
        }

        // ---- public: open the modal against a given Key Result/Subtask token ----
        function open(token, description) {
            initOnce();
            atemTargetTokenInput.value = token;
            pendingAtemTitle = (description || '').slice(0, 255);
            searchInput.value = '';
            listEl.innerHTML = '<div class="okr-kr-empty">Loading...</div>';
            searchErrorEl.textContent = '';
            collapseCreateWrap();
            resetCreateForm();
            atemModal.show();
            fetchAtemList(function (items) { renderAtemOptions(items); });
        }

        return { open: open };
    })();

    function openAtemModal(token, description) {
        AtemLink.open(token, description);
    }

    renderKeyResults();

    // ---------------------------------------------------------------
    // Validation + save
    // ---------------------------------------------------------------
    // mode 'draft' skips only the reference-link requirement (backend.php's
    // one relaxed rule) - every other field stays required since okr_cards
    // has them as NOT NULL columns regardless of status.
    function validate(mode) {
        clearErrors();
        var ok = true;

        var objective = document.getElementById('okr-objective').value.trim();
        if (!objective) { setError('okr-objective', 'Objective is required.'); ok = false; }

        if (mode !== 'draft' && referenceLinks.length === 0) {
            setError('reflink-section', 'At least one reference link (e.g. the Trello board) is required.');
            ok = false;
        }

        var start = document.getElementById('okr-start').value;
        if (!start) { setError('okr-start', 'Start date is required.'); ok = false; }

        var end = document.getElementById('okr-end').value;
        if (!end) { setError('okr-end', 'End date is required.'); ok = false; }
        else if (start && end < start) { setError('okr-end', 'End date cannot be before start date.'); ok = false; }

        if (ownerState.length === 0) { setError('okr-owner', 'An owner is required.'); ok = false; }

        refreshKrDateBounds();
        if (krDateWarningEl && krDateWarningEl.style.display !== 'none') {
            setError('okr-kr', 'Fix the Key Result dates that fall outside the OKR\'s Start/End Date before saving.');
            ok = false;
        }

        return ok;
    }

    // ---------------------------------------------------------------
    // Session draft autosave (survives refresh) + Save as Draft (a real,
    // status=Draft okr_cards row) - mirrors ATEM's draft-save/draft-clear
    // and saveAtem(mode, navUrl)/cancelAtem(navUrl).
    // ---------------------------------------------------------------
    function buildDraftState() {
        return {
            objective: document.getElementById('okr-objective').value,
            start_date: document.getElementById('okr-start').value,
            end_date: document.getElementById('okr-end').value,
            owner_state: ownerState
        };
    }

    function hydrateDraftState(draft) {
        if (!draft) { return; }
        if (typeof draft.objective === 'string' && draft.objective) {
            document.getElementById('okr-objective').value = draft.objective;
        }
        if (draft.start_date) { startDateInput.value = draft.start_date; }
        if (draft.end_date) { endDateInput.value = draft.end_date; }
        if (Array.isArray(draft.owner_state) && draft.owner_state.length) { ownerState = draft.owner_state; }
        refreshOwnerUI();

        if (Array.isArray(draft.reflinks) && draft.reflinks.length) {
            referenceLinks = draft.reflinks;
            renderReferenceLinks();
        }
        if (Array.isArray(draft.attachments) && draft.attachments.length) {
            stagedFiles = draft.attachments;
            renderFiles();
        }
        if (Array.isArray(draft.keyResults) && draft.keyResults.length) {
            // PHP hydrates each staged Key Result with its subtasks nested
            // inside - flatten into the same parent_token-linked shape the
            // rest of this section works with.
            keyResults = [];
            draft.keyResults.forEach(function (kr) {
                keyResults.push({
                    token: kr.token,
                    parent_token: null,
                    description: kr.description,
                    creator_name: kr.creator_name,
                    atem_id: kr.atem_id || null,
                    start_date: kr.start_date,
                    end_date: kr.end_date,
                    status_id: kr.status_id,
                    status_value: kr.status_value,
                    pill_class: kr.pill_class
                });
                (kr.subtasks || []).forEach(function (sub) {
                    keyResults.push({
                        token: sub.token,
                        parent_token: kr.token,
                        description: sub.description,
                        creator_name: sub.creator_name,
                        atem_id: null,
                        start_date: sub.start_date,
                        end_date: sub.end_date,
                        status_id: sub.status_id,
                        status_value: sub.status_value,
                        pill_class: sub.pill_class
                    });
                });
            });
            renderKeyResults();
        }

        // Restored content is unsaved (no DB row yet), so leaving should still warn.
        dirty = true;
    }

    // Any Key Result/Subtask linked to an ATEM while still staging (no real
    // card id existed yet, so the reverse okr_id link on the ATEM side was
    // never set - see AtemLink's applyLinkedAtem) gets that link backfilled
    // here once createCard hands back a real id. Best-effort: a failure here
    // doesn't block navigation, same tolerance as the "ATEM created but
    // failed to link" case elsewhere - the KR<->ATEM link itself (atem_id)
    // is already durable via okrFinalizeStagedKeyResults regardless.
    function linkStagedAtemsToNewCard(newCardId) {
        var targets = keyResults.filter(function (r) { return r.atem_id; });
        if (targets.length === 0) { return Promise.resolve(); }
        return Promise.all(targets.map(function (r) {
            return fetch(CFG.atemApiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'link-atem-okr', id: r.atem_id, okr_id: newCardId })
            }).then(function (resp) { return resp.json(); }).catch(function () { return { success: false }; });
        }));
    }

    function saveOkr(mode, navUrl) {
        if (!validate(mode)) { scrollToFirstError(); return; }
        setError('okr-save', '');

        var btn = document.getElementById('okr-save-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }

        var deptScopeIds = ownerState.map(function (m) { return m.dept_id; }).filter(function (v) { return !!v; });
        var deptScope = deptScopeIds.filter(function (v, i) { return deptScopeIds.indexOf(v) === i; }).join(',');

        var owner1 = ownerState[0] || {};
        var owner2 = ownerState[1] || {};

        var payload = new URLSearchParams();
        payload.set('action', 'createCard');
        payload.set('mode', mode);
        payload.set('objective', document.getElementById('okr-objective').value.trim());
        payload.set('owner_staff_id', owner1.staff_id || '');
        payload.set('owner2_staff_id', owner2.staff_id || '');
        payload.set('owner2_purpose', '');
        payload.set('dept_scope', deptScope);
        payload.set('start_date', document.getElementById('okr-start').value);
        payload.set('end_date', document.getElementById('okr-end').value);

        fetch(CFG.apiUrl, { method: 'POST', body: payload })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    leaving = true;
                    linkStagedAtemsToNewCard(res.id).then(function () {
                        window.location.href = navUrl || ('okr/view.php?id=' + res.id);
                    });
                } else {
                    setError('okr-save', res.message || 'Failed to save OKR.');
                    scrollToFirstError();
                    if (btn) { btn.disabled = false; btn.textContent = 'Save OKR'; }
                }
            })
            .catch(function () {
                setError('okr-save', 'Network error. Please try again.');
                scrollToFirstError();
                if (btn) { btn.disabled = false; btn.textContent = 'Save OKR'; }
            });
    }

    function cancelOkr(navUrl) {
        var body = new URLSearchParams();
        body.set('action', 'clearDraftState');
        fetch(CFG.apiUrl, { method: 'POST', body: body }).then(function () {
            leaving = true;
            window.location.href = navUrl || 'okr/list.php';
        }).catch(function () {
            leaving = true;
            window.location.href = navUrl || 'okr/list.php';
        });
    }

    // ------------------------------------------------------------ leave guard
    var pendingNavUrl = 'okr/list.php';
    var leaveModalEl = document.getElementById('okr-leave-modal');
    var leaveModal = leaveModalEl ? new bootstrap.Modal(leaveModalEl) : null;

    function showLeaveModal(navUrl) {
        pendingNavUrl = navUrl || 'okr/list.php';
        if (leaveModal) { leaveModal.show(); }
    }

    function bindLeaveGuard() {
        var cancelBtn = document.getElementById('okr-cancel-btn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () { showLeaveModal('okr/list.php'); });
        }
        var leaveCancelBtn = document.getElementById('okr-leave-cancel');
        if (leaveCancelBtn) {
            leaveCancelBtn.addEventListener('click', function () {
                if (leaveModal) { leaveModal.hide(); }
                cancelOkr(pendingNavUrl);
            });
        }
        var leaveDraftBtn = document.getElementById('okr-leave-draft');
        if (leaveDraftBtn) {
            leaveDraftBtn.addEventListener('click', function () {
                if (leaveModal) { leaveModal.hide(); }
                saveOkr('draft', pendingNavUrl);
            });
        }

        // Intercept in-app navigation links while there are unsaved changes.
        document.addEventListener('click', function (e) {
            if (!dirty || leaving) { return; }
            var a = e.target.closest ? e.target.closest('a[href]') : null;
            if (!a) { return; }
            var href = a.getAttribute('href');
            if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) { return; }
            if (a.getAttribute('target') === '_blank') { return; }
            e.preventDefault();
            showLeaveModal(a.href);
        });

        // Tab close / refresh: only a generic browser prompt is possible.
        window.addEventListener('beforeunload', function (e) {
            if (dirty && !leaving) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });
    }
    bindLeaveGuard();

    document.getElementById('okr-save-btn').addEventListener('click', function () {
        saveOkr('final');
    });

    hydrateDraftState(CFG.draft);
})();