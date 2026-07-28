(function () {
    var CFG = window.OKR_CONFIG || { staff: [], departments: [], levels: [] };

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

        document.querySelectorAll('#okr-kr-list .okr-kr-start-input, #okr-kr-list .okr-kr-end-input').forEach(function (input) {
            input.min = min;
            input.max = max;
        });

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

    document.getElementById('reflink-save-btn').addEventListener('click', function () {
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

        fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
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
                setError('reflink', 'Network error. Please try again.');
            });
    });

    reflinkListEl.addEventListener('click', function (e) {
        if (!e.target.classList.contains('okr-reflink-remove')) { return; }
        var token = e.target.getAttribute('data-token');
        var body = new URLSearchParams();
        body.set('action', 'removeStagedReferenceLink');
        body.set('token', token);
        fetch(CFG.apiUrl, { method: 'POST', body: body }).catch(function () {});
        referenceLinks = referenceLinks.filter(function (l) { return l.token !== token; });
        markChanged();
        renderReferenceLinks();
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

        fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    stagedFiles.push({ token: res.token, name: res.name, size: res.size });
                    markChanged();
                    renderFiles();
                } else {
                    setError('okr-file', res.message || 'Failed to upload file.');
                }
            })
            .catch(function () {
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
            var token = e.target.getAttribute('data-token');
            var body = new URLSearchParams();
            body.set('action', 'removeStagedAttachment');
            body.set('token', token);
            fetch(CFG.apiUrl, { method: 'POST', body: body }).catch(function () {});
            stagedFiles = stagedFiles.filter(function (f) { return f.token !== token; });
            markChanged();
            renderFiles();
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

    // ATEM cards this user can see, keyed by id - resolved once so linked
    // Key Results can show the real title. ATEM lives in a separate service
    // (atem-api behind atem/api.php), so this is a same-session AJAX call
    // into the sibling module, not a DB join.
    var atemMap = {};
    var atemListCache = null;

    function fetchAtemList(callback) {
        if (atemListCache) { callback(atemListCache); return; }
        fetch(CFG.atemApiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'list-atems' })
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                atemListCache = (res && res.success) ? (res.data || []) : [];
                atemListCache.forEach(function (a) { atemMap[a.id] = a; });
                callback(atemListCache);
            })
            .catch(function () { callback([]); });
    }

    function krChildren(parentToken) {
        return keyResults.filter(function (r) { return r.parent_token === parentToken; });
    }

    var krStatusOptionsHtml = krStatusSelect.innerHTML;

    function krRowHtml(row, index, isSubtask) {
        var statusSelect = '<select class="okr-kr-status-input" data-status-id="' + row.status_id + '">' + krStatusOptionsHtml + '</select>';
        var dateMin = startDateInput.value || '';
        var dateMax = endDateInput.value || '';
        var dates = '<div class="okr-kr-dates">'
            + '<input type="date" class="okr-kr-start-input" value="' + (row.start_date || '') + '" min="' + dateMin + '" max="' + dateMax + '">'
            + '<input type="date" class="okr-kr-end-input" value="' + (row.end_date || '') + '" min="' + dateMin + '" max="' + dateMax + '">'
            + '</div>';

        var atemBadge = '';
        if (row.atem_id) {
            var atem = atemMap[row.atem_id];
            var atemLabel = atem ? escapeHtml(atem.title) : ('ATEM #' + row.atem_id);
            atemBadge = '<div class="okr-kr-atem-badge">'
                + '<i class="bi bi-link-45deg"></i> '
                + '<a href="' + CFG.atemViewUrl + '?id=' + row.atem_id + '" target="_blank" rel="noopener">' + atemLabel + '</a>'
                + '<span class="okr-kr-atem-unlink" data-token="' + row.token + '" title="Unlink">&times;</span>'
                + '</div>';
        }

        return '<div class="okr-kr-row' + (isSubtask ? ' okr-kr-row--subtask' : '') + '" data-token="' + row.token + '">'
            + '<div class="okr-kr-num">' + index + '</div>'
            + '<div class="okr-kr-body">'
            + '<div class="okr-kr-desc"><span class="okr-kr-desc-label">Action Details</span>'
            + '<textarea class="okr-kr-desc-input" rows="1">' + escapeHtml(row.description) + '</textarea>'
            + atemBadge + '</div>'
            + '<div><span class="okr-kr-dates-label">Dates</span>' + dates + '</div>'
            + '<div><span class="okr-kr-assignee-label">Created By</span><span class="okr-kr-assignee-name">' + escapeHtml(row.creator_name || '') + '</span></div>'
            + '<div><span class="okr-kr-progress-label">Status</span>' + statusSelect + '</div>'
            + '</div>'
            + '<div class="okr-kr-actions">'
            + '<button type="button" class="okr-kr-icon-btn okr-kr-icon-btn--edit okr-kr-edit" title="Edit"><i class="bi bi-pencil"></i></button>'
            + '<button type="button" class="okr-kr-icon-btn okr-kr-icon-btn--delete okr-kr-delete" title="Delete"><i class="bi bi-x-lg"></i></button>'
            + (isSubtask ? '' : '<button type="button" class="okr-kr-icon-btn okr-kr-icon-btn--add okr-kr-add-sub" title="Add Subtask"><i class="bi bi-plus-lg"></i></button>')
            + '<button type="button" class="okr-kr-icon-btn okr-kr-icon-btn--atem okr-kr-add-atem" title="Link ATEM"><i class="bi bi-file-earmark-plus"></i></button>'
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
        krListEl.querySelectorAll('.okr-kr-status-input').forEach(function (sel) {
            sel.value = sel.getAttribute('data-status-id');
        });
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

    // Inline Action Details / Dates editing - autosaves on blur/change
    // instead of requiring the pencil-icon modal. Status still only
    // editable via the modal. Staged rows have no id yet, so "saving" means
    // re-staging under a new token, same as the modal's edit-and-save flow
    // below.
    krListEl.addEventListener('focusin', function (e) {
        var textarea = e.target.closest ? e.target.closest('.okr-kr-desc-input') : null;
        if (textarea) { textarea.dataset.prevValue = textarea.value; }
    });

    krListEl.addEventListener('focusout', function (e) {
        var textarea = e.target.closest ? e.target.closest('.okr-kr-desc-input') : null;
        if (!textarea) { return; }

        var row = textarea.closest('.okr-kr-row');
        var token = row.getAttribute('data-token');
        var data = keyResults.filter(function (r) { return r.token === token; })[0];
        if (!data) { return; }

        var description = textarea.value.trim();
        if (!description) {
            textarea.value = textarea.dataset.prevValue || data.description;
            return;
        }
        if (description === (textarea.dataset.prevValue || '').trim()) { return; }

        var parentToken = data.parent_token;

        function stageIt() {
            var body = new URLSearchParams();
            body.set('action', parentToken ? 'stageKeyResultSubtask' : 'stageKeyResult');
            if (parentToken) { body.set('parent_token', parentToken); }
            body.set('description', description);
            body.set('start_date', data.start_date || '');
            body.set('end_date', data.end_date || '');
            body.set('status_id', data.status_id);

            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        var idx = keyResults.findIndex(function (r) { return r.token === token; });
                        if (idx !== -1) {
                            keyResults[idx] = {
                                token: res.token,
                                parent_token: parentToken || null,
                                description: res.description,
                                creator_name: res.creator_name,
                                atem_id: data.atem_id,
                                start_date: res.start_date,
                                end_date: res.end_date,
                                status_id: res.status_id,
                                status_value: res.status_value,
                                pill_class: res.pill_class
                            };
                        }
                        markChanged();
                        renderKeyResults();
                    } else {
                        textarea.value = textarea.dataset.prevValue || data.description;
                        setError('okr-kr', res.message || 'Failed to save Action Details.');
                    }
                })
                .catch(function () {
                    textarea.value = textarea.dataset.prevValue || data.description;
                    setError('okr-kr', 'Network error. Please try again.');
                });
        }

        var removeBody = new URLSearchParams();
        if (parentToken) {
            removeBody.set('action', 'removeStagedKeyResultSubtask');
            removeBody.set('parent_token', parentToken);
            removeBody.set('token', token);
        } else {
            removeBody.set('action', 'removeStagedKeyResult');
            removeBody.set('token', token);
        }
        fetch(CFG.apiUrl, { method: 'POST', body: removeBody }).then(stageIt).catch(stageIt);
    });

    krListEl.addEventListener('change', function (e) {
        var dateInput = e.target.closest ? e.target.closest('.okr-kr-start-input, .okr-kr-end-input') : null;
        if (!dateInput) { return; }

        var row = dateInput.closest('.okr-kr-row');
        var token = row.getAttribute('data-token');
        var data = keyResults.filter(function (r) { return r.token === token; })[0];
        if (!data) { return; }

        var isStart = dateInput.classList.contains('okr-kr-start-input');
        var startInputEl = row.querySelector('.okr-kr-start-input');
        var endInputEl = row.querySelector('.okr-kr-end-input');

        // Keep start <= end, same guard the modal's own Start/End inputs use.
        if (startInputEl.value && endInputEl.value && startInputEl.value > endInputEl.value) {
            if (isStart) { endInputEl.value = startInputEl.value; } else { startInputEl.value = endInputEl.value; }
        }

        var parentToken = data.parent_token;
        var newStart = startInputEl.value;
        var newEnd = endInputEl.value;

        function stageIt() {
            var body = new URLSearchParams();
            body.set('action', parentToken ? 'stageKeyResultSubtask' : 'stageKeyResult');
            if (parentToken) { body.set('parent_token', parentToken); }
            body.set('description', data.description);
            body.set('start_date', newStart);
            body.set('end_date', newEnd);
            body.set('status_id', data.status_id);

            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        var idx = keyResults.findIndex(function (r) { return r.token === token; });
                        if (idx !== -1) {
                            keyResults[idx] = {
                                token: res.token,
                                parent_token: parentToken || null,
                                description: res.description,
                                creator_name: res.creator_name,
                                atem_id: data.atem_id,
                                start_date: res.start_date,
                                end_date: res.end_date,
                                status_id: res.status_id,
                                status_value: res.status_value,
                                pill_class: res.pill_class
                            };
                        }
                        markChanged();
                        renderKeyResults();
                    } else {
                        startInputEl.value = data.start_date || '';
                        endInputEl.value = data.end_date || '';
                        setError('okr-kr', res.message || 'Failed to save dates.');
                    }
                })
                .catch(function () {
                    startInputEl.value = data.start_date || '';
                    endInputEl.value = data.end_date || '';
                    setError('okr-kr', 'Network error. Please try again.');
                });
        }

        var removeBody = new URLSearchParams();
        if (parentToken) {
            removeBody.set('action', 'removeStagedKeyResultSubtask');
            removeBody.set('parent_token', parentToken);
            removeBody.set('token', token);
        } else {
            removeBody.set('action', 'removeStagedKeyResult');
            removeBody.set('token', token);
        }
        fetch(CFG.apiUrl, { method: 'POST', body: removeBody }).then(stageIt).catch(stageIt);
    });

    krListEl.addEventListener('change', function (e) {
        var statusSelectEl = e.target.closest ? e.target.closest('.okr-kr-status-input') : null;
        if (!statusSelectEl) { return; }

        var row = statusSelectEl.closest('.okr-kr-row');
        var token = row.getAttribute('data-token');
        var data = keyResults.filter(function (r) { return r.token === token; })[0];
        if (!data) { return; }

        var parentToken = data.parent_token;
        var newStatusId = statusSelectEl.value;

        function stageIt() {
            var body = new URLSearchParams();
            body.set('action', parentToken ? 'stageKeyResultSubtask' : 'stageKeyResult');
            if (parentToken) { body.set('parent_token', parentToken); }
            body.set('description', data.description);
            body.set('start_date', data.start_date || '');
            body.set('end_date', data.end_date || '');
            body.set('status_id', newStatusId);

            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        var idx = keyResults.findIndex(function (r) { return r.token === token; });
                        if (idx !== -1) {
                            keyResults[idx] = {
                                token: res.token,
                                parent_token: parentToken || null,
                                description: res.description,
                                creator_name: res.creator_name,
                                atem_id: data.atem_id,
                                start_date: res.start_date,
                                end_date: res.end_date,
                                status_id: res.status_id,
                                status_value: res.status_value,
                                pill_class: res.pill_class
                            };
                        }
                        markChanged();
                        renderKeyResults();
                    } else {
                        statusSelectEl.value = statusSelectEl.getAttribute('data-status-id');
                        setError('okr-kr', res.message || 'Failed to save status.');
                    }
                })
                .catch(function () {
                    statusSelectEl.value = statusSelectEl.getAttribute('data-status-id');
                    setError('okr-kr', 'Network error. Please try again.');
                });
        }

        var removeBody = new URLSearchParams();
        if (parentToken) {
            removeBody.set('action', 'removeStagedKeyResultSubtask');
            removeBody.set('parent_token', parentToken);
            removeBody.set('token', token);
        } else {
            removeBody.set('action', 'removeStagedKeyResult');
            removeBody.set('token', token);
        }
        fetch(CFG.apiUrl, { method: 'POST', body: removeBody }).then(stageIt).catch(stageIt);
    });

    document.getElementById('okr-kr-save-btn').addEventListener('click', function () {
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
                    setError('okr-kr-modal', 'Network error. Please try again.');
                });
        }

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

        if (e.target.closest('.okr-kr-atem-unlink')) {
            var unlinkBody = new URLSearchParams();
            unlinkBody.set('action', 'removeStagedKeyResultAtemLink');
            unlinkBody.set('token', token);
            fetch(CFG.apiUrl, { method: 'POST', body: unlinkBody })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        data.atem_id = null;
                        markChanged();
                        renderKeyResults();
                    } else {
                        setError('okr-kr', res.message || 'Failed to unlink ATEM.');
                    }
                })
                .catch(function () { setError('okr-kr', 'Network error. Please try again.'); });
            return;
        }

        if (e.target.closest('.okr-kr-add-atem')) {
            openAtemModal(token);
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
            var body = new URLSearchParams();
            if (data.parent_token) {
                body.set('action', 'removeStagedKeyResultSubtask');
                body.set('parent_token', data.parent_token);
                body.set('token', token);
            } else {
                body.set('action', 'removeStagedKeyResult');
                body.set('token', token);
            }
            fetch(CFG.apiUrl, { method: 'POST', body: body }).catch(function () {});
            // Deleting a top-level Key Result also drops its staged subtasks client-side
            // (the session entry disappears server-side along with them).
            keyResults = keyResults.filter(function (r) { return r.token !== token && r.parent_token !== token; });
            markChanged();
            renderKeyResults();
        }
    });

    // ---------------------------------------------------------------
    // Link ATEM modal - picks an existing card from the real ATEM module
    // ---------------------------------------------------------------
    var atemModalEl = document.getElementById('okr-kr-atem-modal');
    var atemModal = new bootstrap.Modal(atemModalEl);
    var atemSearchInput = document.getElementById('okr-kr-atem-search');
    var atemListEl = document.getElementById('okr-kr-atem-list');
    var atemTargetToken = null;

    // atem-api's status field isn't always a plain string across endpoints -
    // fall back gracefully instead of stringifying an object into the UI.
    function atemStatusLabel(a) {
        if (typeof a.status === 'string') { return a.status; }
        if (a.status && typeof a.status === 'object' && a.status.value) { return a.status.value; }
        return '';
    }

    function renderAtemOptions(items) {
        if (items.length === 0) {
            atemListEl.innerHTML = '<div class="okr-kr-empty">No ATEM cards found.</div>';
            return;
        }
        var html = '';
        items.forEach(function (a) {
            html += '<div class="okr-kr-atem-row" data-atem-id="' + a.id + '">'
                + '<div class="okr-kr-atem-row-title">' + escapeHtml(a.title || ('ATEM #' + a.id)) + '</div>'
                + '<div class="okr-kr-atem-row-meta">' + escapeHtml(atemStatusLabel(a)) + '</div>'
                + '</div>';
        });
        atemListEl.innerHTML = html;
    }

    function openAtemModal(token) {
        setError('okr-kr-atem-modal', '');
        atemTargetToken = token;
        atemSearchInput.value = '';
        atemListEl.innerHTML = '<div class="okr-kr-empty">Loading...</div>';
        resetAtemCreateForm();
        atemModal.show();
        fetchAtemList(function (items) { renderAtemOptions(items); });
    }

    atemSearchInput.addEventListener('input', function () {
        var term = atemSearchInput.value.toLowerCase();
        var items = (atemListCache || []).filter(function (a) {
            return String(a.title || '').toLowerCase().indexOf(term) !== -1;
        });
        renderAtemOptions(items);
    });

    function linkAtemToTarget(atemId) {
        var body = new URLSearchParams();
        body.set('action', 'stageKeyResultAtemLink');
        body.set('token', atemTargetToken);
        body.set('atem_id', atemId);
        return fetch(CFG.apiUrl, { method: 'POST', body: body }).then(function (r) { return r.json(); });
    }

    atemListEl.addEventListener('click', function (e) {
        var row = e.target.closest('.okr-kr-atem-row');
        if (!row || !atemTargetToken) { return; }
        var atemId = parseInt(row.getAttribute('data-atem-id'), 10);

        linkAtemToTarget(atemId)
            .then(function (res) {
                if (res.success) {
                    var data = keyResults.filter(function (r) { return r.token === atemTargetToken; })[0];
                    if (data) { data.atem_id = atemId; }
                    atemModal.hide();
                    renderKeyResults();
                } else {
                    setError('okr-kr-atem-modal', res.message || 'Failed to link ATEM.');
                }
            })
            .catch(function () {
                setError('okr-kr-atem-modal', 'Network error. Please try again.');
            });
    });

    // ---------------------------------------------------------------
    // Create New ATEM - a reduced quick-create form (Action Details, dates,
    // Department, PIC) that maps onto a real ATEM card's title/dates and a
    // single Accountable ARCI member, saved via atem/api.php's save-atem
    // action directly (same-origin, same session - see fetchAtemList above),
    // then linked/staged against this Key Result the same way picking an
    // existing card would be. Saved as an ATEM Draft (mode: 'draft') so
    // ATEM's own reference-link requirement for a "final" card doesn't block
    // this quick-create path.
    // ---------------------------------------------------------------
    var atemCreateToggle = document.getElementById('okr-kr-atem-create-toggle');
    var atemCreateWrap = document.getElementById('okr-kr-atem-create-wrap');
    var atemCreateDesc = document.getElementById('okr-kr-atem-create-desc');
    var atemCreateStart = document.getElementById('okr-kr-atem-create-start');
    var atemCreateEnd = document.getElementById('okr-kr-atem-create-end');
    var atemCreateDept = document.getElementById('okr-kr-atem-create-dept');
    var atemCreateStaff = document.getElementById('okr-kr-atem-create-staff');
    var atemCreateSaveBtn = document.getElementById('okr-kr-atem-create-save-btn');

    function resetAtemCreateForm() {
        if (!atemCreateWrap) { return; }
        atemCreateWrap.style.display = 'none';
        atemCreateToggle.style.display = '';
        atemCreateDesc.value = '';
        atemCreateStart.value = '';
        atemCreateEnd.value = '';
        atemCreateDept.value = '';
        atemCreateStaff.innerHTML = '<option value="">Select department first</option>';
        setError('okr-kr-atem-create', '');
    }

    if (atemCreateToggle) {
        atemCreateToggle.addEventListener('click', function () {
            atemCreateWrap.style.display = 'block';
            atemCreateToggle.style.display = 'none';
        });

        atemCreateDept.addEventListener('change', function () {
            var deptId = parseInt(atemCreateDept.value, 10);
            var staff = (CFG.staff || []).filter(function (s) { return (s.deptIds || []).indexOf(deptId) !== -1; });
            var html = '<option value="">Select staff</option>';
            staff.forEach(function (s) {
                html += '<option value="' + s.id + '">' + escapeHtml(s.name) + '</option>';
            });
            atemCreateStaff.innerHTML = html || '<option value="">No staff in this department</option>';
        });

        atemCreateSaveBtn.addEventListener('click', function () {
            setError('okr-kr-atem-create', '');
            var desc = atemCreateDesc.value.trim();
            var start = atemCreateStart.value;
            var end = atemCreateEnd.value;
            var deptId = atemCreateDept.value;
            var staffId = atemCreateStaff.value;

            if (!desc || !start || !end || !deptId || !staffId) {
                setError('okr-kr-atem-create', 'All fields are required.');
                return;
            }
            if (end < start) {
                setError('okr-kr-atem-create', 'End date cannot be before start date.');
                return;
            }

            atemCreateSaveBtn.disabled = true;
            fetch(CFG.atemApiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save-atem',
                    data: {
                        title: desc,
                        description: desc,
                        start_date: start,
                        end_date: end,
                        arci: [{ staff_id: parseInt(staffId, 10), staff_dept_id: parseInt(deptId, 10), role: 'A' }],
                        mode: 'draft'
                    }
                })
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success || !res.data || !res.data.id) {
                        setError('okr-kr-atem-create', (res && res.message) || 'Failed to create ATEM.');
                        atemCreateSaveBtn.disabled = false;
                        return;
                    }
                    atemListCache = null;
                    return linkAtemToTarget(res.data.id).then(function (linkRes) {
                        atemCreateSaveBtn.disabled = false;
                        if (linkRes.success) {
                            var data = keyResults.filter(function (r) { return r.token === atemTargetToken; })[0];
                            if (data) { data.atem_id = res.data.id; }
                            atemModal.hide();
                            renderKeyResults();
                        } else {
                            setError('okr-kr-atem-create', linkRes.message || 'ATEM created but failed to link.');
                        }
                    });
                })
                .catch(function () {
                    atemCreateSaveBtn.disabled = false;
                    setError('okr-kr-atem-create', 'Network error. Please try again.');
                });
        });
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

        var type = document.getElementById('okr-type').value;
        if (!type) { setError('okr-type', 'Select an OKR type.'); ok = false; }

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
            okr_type: document.getElementById('okr-type').value,
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
        if (draft.okr_type) { document.getElementById('okr-type').value = draft.okr_type; }
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
            if (keyResults.some(function (r) { return r.atem_id; })) {
                fetchAtemList(function () { renderKeyResults(); });
            } else {
                renderKeyResults();
            }
        }

        // Restored content is unsaved (no DB row yet), so leaving should still warn.
        dirty = true;
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
        payload.set('okr_type', document.getElementById('okr-type').value);
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
                    window.location.href = navUrl || ('okr/view.php?id=' + res.id);
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