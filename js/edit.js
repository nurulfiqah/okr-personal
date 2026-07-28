(function () {
    var CFG = window.OKR_EDIT_CONFIG || { staff: [], departments: [], levels: [] };
    var card = CFG.card || {};

    var referenceLinks = (CFG.referenceLinks || []).slice(); // { id, name, url }
    var attachments = (CFG.attachments || []).slice(); // { id, original_name, size }

    // Warn on refresh/close/back navigation once the user has started editing,
    // same as create.php.
    var dirty = false;
    var leaving = false;
    // Chat is its own independent, immediately-saved action (not part of the
    // OKR form's fields), so typing/sending a message must not trip the
    // "unsaved changes" leave-guard below.
    function markChanged(e) {
        if (e && e.target && e.target.closest && e.target.closest('#okr-chat-card')) { return; }
        dirty = true;
    }
    document.addEventListener('input', markChanged, true);
    document.addEventListener('change', markChanged, true);
    window.addEventListener('beforeunload', function (e) {
        if (dirty && !leaving) {
            e.preventDefault();
            e.returnValue = '';
            return '';
        }
    });
    document.addEventListener('click', function (e) {
        if (!dirty || leaving) { return; }
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) { return; }
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) { return; }
        if (a.getAttribute('target') === '_blank') { return; }
        if (!confirm('Leave this page? Changes you made may not be saved.')) {
            e.preventDefault();
        } else {
            leaving = true;
        }
    });

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

    // Prefill from the card's current saved owners before wiring change handlers.
    [card.owner_staff_id, card.owner2_staff_id].forEach(function (ownerId) {
        if (!ownerId) { return; }
        var staff = CFG.staff.filter(function (s) { return s.id === ownerId; })[0];
        if (!staff) { return; }
        var deptId = (staff.deptIds || [])[0] || null;
        ownerState.push({
            staff_id: staff.id,
            staff_name: staff.name,
            dept_id: deptId,
            department_name: departmentName(deptId)
        });
    });
    refreshOwnerUI();

    // ---------------------------------------------------------------
    // Timeline: Start/End/Status + once-only Extended + auto Final Due Date.
    // ---------------------------------------------------------------
    var statusSelect = document.getElementById('okr-status');

    var startInput = document.getElementById('okr-start');
    var endInput = document.getElementById('okr-end');
    var extendedCheckbox = document.getElementById('okr-extended');
    var extendedDateWrap = document.getElementById('okr-extended-date-wrap');
    var extendedDateReq = document.getElementById('okr-extended-date-req');
    var extendedDateInput = document.getElementById('okr-extended-date');
    var finalDueInput = document.getElementById('okr-final-due');

    function refreshFinalDue() {
        // Final Due Date never mirrors the Extended Date target — it stays
        // End Date until the OKR is actually resolved (Complete/Fail),
        // at which point the server stamps closed_at and it follows that
        // on reload instead.
        finalDueInput.value = endInput.value;
    }

    // End Date can't be before Start Date (or before today, unless the
    // admin's Backdate toggle is on - atem/admin/index.php); Extended Date
    // can't be before the (original) End Date it's extending past.
    var todayStr = new Date().toISOString().slice(0, 10);
    endInput.min = (!CFG.backdateEnabled && startInput.value < todayStr) ? todayStr : startInput.value;
    extendedDateInput.min = card.end_date || endInput.value;
    endInput.addEventListener('change', function () {
        if (startInput.value && endInput.value < startInput.value) {
            endInput.value = startInput.value;
        }
        extendedDateInput.min = endInput.value;
        if (extendedDateInput.value && extendedDateInput.value < endInput.value) {
            extendedDateInput.value = endInput.value;
        }
        refreshKrDateBounds();
    });
    startInput.addEventListener('change', function () {
        refreshKrDateBounds();
    });

    // Key Results can have dates set before the OKR's own Start/End Date
    // change - re-check every Key Result/Subtask against the new range
    // whenever it changes and surface a reminder banner if any of them now
    // fall outside it.
    var krDateWarningEl = document.getElementById('okr-kr-date-warning');
    function refreshKrDateBounds() {
        var min = startInput.value || '';
        var max = endInput.value || '';

        document.querySelectorAll('#okr-kr-list .okr-kr-start-input, #okr-kr-list .okr-kr-end-input').forEach(function (input) {
            input.min = min;
            input.max = max;
        });

        var outOfRange = krList.some(function (row) {
            return (min && row.start_date && row.start_date < min)
                || (max && row.start_date && row.start_date > max)
                || (min && row.end_date && row.end_date < min)
                || (max && row.end_date && row.end_date > max);
        });
        if (krDateWarningEl) { krDateWarningEl.style.display = outOfRange ? '' : 'none'; }
    }

    function applyExtendedToggle(on) {
        extendedCheckbox.checked = on;
        extendedDateWrap.style.display = on ? '' : 'none';
        extendedDateReq.style.display = on ? '' : 'none';
        extendedDateInput.disabled = !on;
        if (!on) { extendedDateInput.value = ''; }
        refreshFinalDue();
    }

    if (!card.extended) {
        extendedDateInput.disabled = !extendedCheckbox.checked;
        extendedCheckbox.addEventListener('change', function () {
            applyExtendedToggle(extendedCheckbox.checked);
        });
        extendedDateInput.addEventListener('change', refreshFinalDue);
    }
    endInput.addEventListener('change', refreshFinalDue);
    statusSelect.addEventListener('change', refreshFinalDue);
    refreshFinalDue();

    // ---------------------------------------------------------------
    // Reference links (real actions against the existing card, not staging)
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
                '<span class="okr-reflink-remove" data-id="' + link.id + '">&times;</span>';
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
        body.set('action', 'addReferenceLink');
        body.set('id', card.id);
        body.set('name', name);
        body.set('url', url);

        fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
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
                setError('reflink', 'Network error. Please try again.');
            });
    });

    reflinkListEl.addEventListener('click', function (e) {
        if (!e.target.classList.contains('okr-reflink-remove')) { return; }
        var id = parseInt(e.target.getAttribute('data-id'), 10);
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
                    setError('reflink-section', res.message || 'Failed to remove link.');
                }
            })
            .catch(function () {
                setError('reflink-section', 'Network error. Please try again.');
            });
    });

    renderReferenceLinks();

    // ---------------------------------------------------------------
    // Attachments (real actions against the existing card, not staging)
    // ---------------------------------------------------------------
    var fileListEl = document.getElementById('okr-file-list');
    var dropzoneEl = document.getElementById('okr-dropzone');
    var fileInputEl = document.getElementById('okr-file-input');

    function renderFiles() {
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
                '<span class="okr-file-remove" data-id="' + file.id + '">&times;</span>';
            fileListEl.appendChild(row);
        });
    }

    function uploadFile(file) {
        setError('okr-file', '');
        var body = new FormData();
        body.set('action', 'addAttachment');
        body.set('id', card.id);
        body.set('file', file);

        fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    attachments.push({ id: res.id, original_name: file.name, size: file.size, mime_type: file.type });
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
            var id = parseInt(e.target.getAttribute('data-id'), 10);
            var body = new URLSearchParams();
            body.set('action', 'deleteAttachment');
            body.set('id', id);
            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        attachments = attachments.filter(function (f) { return f.id !== id; });
                        renderFiles();
                    } else {
                        setError('okr-file', res.message || 'Failed to remove file.');
                    }
                })
                .catch(function () {
                    setError('okr-file', 'Network error. Please try again.');
                });
        }
    });

    renderFiles();

    // ---------------------------------------------------------------
    // Key Result Progress (real actions against the existing card - a
    // 2-level Key Result / Subtask list, self-referential via parent_id,
    // modeled after iidas's project_detail.php Progression Task widget)
    // ---------------------------------------------------------------
    var krList = []; // flat rows from listKeyResults: { id, parent_id, description, created_by, creator_name, atem_id, status_id, status_value, pill_class, start_date, end_date, has_children, display_status_value, display_pill_class }
    var krListEl = document.getElementById('okr-kr-list');
    var krModalEl = document.getElementById('okr-kr-modal');
    var krModal = new bootstrap.Modal(krModalEl);
    var krCreatedByInput = document.getElementById('okr-kr-created-by');
    var krStartInput = document.getElementById('okr-kr-start');
    var krEndInput = document.getElementById('okr-kr-end');
    var krStatusSelect = document.getElementById('okr-kr-status');

    // ATEM cards this user can see, keyed by id - resolved once per load so
    // linked Key Results can show the real title instead of just "ATEM #123".
    // ATEM lives in a separate service (atem-api behind atem/api.php), so this
    // is a same-session AJAX call into the sibling module, not a DB join.
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

    function krChildren(parentId) {
        return krList.filter(function (r) { return r.parent_id === parentId; });
    }

    var krStatusOptionsHtml = krStatusSelect.innerHTML;

    function krRowHtml(row, index, isSubtask) {
        var statusSelect = '<select class="okr-kr-status-input" data-status-id="' + row.status_id + '">' + krStatusOptionsHtml + '</select>';
        var dateMin = startInput.value || '';
        var dateMax = endInput.value || '';
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
                + '<span class="okr-kr-atem-unlink" data-kr-id="' + row.id + '" title="Unlink">&times;</span>'
                + '</div>';
        }

        return '<div class="okr-kr-row' + (isSubtask ? ' okr-kr-row--subtask' : '') + '" data-id="' + row.id + '">'
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
        var topLevel = krList.filter(function (r) { return !r.parent_id; });
        if (topLevel.length === 0) {
            krListEl.innerHTML = '<div class="okr-kr-empty">No Key Results added yet.</div>';
            refreshKrDateBounds();
            return;
        }
        var html = '';
        topLevel.forEach(function (row, i) {
            html += krRowHtml(row, (i + 1), false);
            krChildren(row.id).forEach(function (sub, j) {
                html += krRowHtml(sub, (i + 1) + '.' + (j + 1), true);
            });
        });
        krListEl.innerHTML = html;
        krListEl.querySelectorAll('.okr-kr-status-input').forEach(function (sel) {
            sel.value = sel.getAttribute('data-status-id');
        });
        refreshKrDateBounds();
    }

    function loadKeyResults() {
        var body = new URLSearchParams();
        body.set('action', 'listKeyResults');
        body.set('id', card.id);
        fetch(CFG.apiUrl + '?' + body.toString())
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    krList = res.data || [];
                    if (krList.some(function (r) { return r.atem_id; })) {
                        fetchAtemList(function () { renderKeyResults(); });
                    } else {
                        renderKeyResults();
                    }
                } else {
                    setError('okr-kr', res.message || 'Failed to load Key Results.');
                }
            })
            .catch(function () {
                setError('okr-kr', 'Network error while loading Key Results.');
            });
    }

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

        // Key Result dates must fall within the OKR's own Start/End Date.
        krStartInput.min = startInput.value || '';
        krStartInput.max = endInput.value || '';
        krEndInput.min = startInput.value || '';
        krEndInput.max = endInput.value || '';

        krModal.show();
    }

    document.getElementById('okr-kr-add-btn').addEventListener('click', function () {
        openKrModal({});
    });

    krListEl.addEventListener('click', function (e) {
        var row = e.target.closest ? e.target.closest('.okr-kr-row') : null;
        if (!row) { return; }
        var id = parseInt(row.getAttribute('data-id'), 10);
        var data = krList.filter(function (r) { return r.id === id; })[0];
        if (!data) { return; }

        if (e.target.closest('.okr-kr-atem-unlink')) {
            var unlinkBody = new URLSearchParams();
            unlinkBody.set('action', 'unlinkKeyResultAtem');
            unlinkBody.set('id', id);
            fetch(CFG.apiUrl, { method: 'POST', body: unlinkBody })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) { loadKeyResults(); }
                    else { setError('okr-kr', res.message || 'Failed to unlink ATEM.'); }
                })
                .catch(function () { setError('okr-kr', 'Network error. Please try again.'); });
        } else if (e.target.closest('.okr-kr-add-atem')) {
            openAtemModal(id);
        } else if (e.target.closest('.okr-kr-add-sub')) {
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
            if (!confirm('Delete this ' + (data.parent_id ? 'subtask' : 'Key Result') + '? This cannot be undone.')) { return; }
            var body = new URLSearchParams();
            body.set('action', 'deleteKeyResult');
            body.set('id', id);
            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) { loadKeyResults(); }
                    else { setError('okr-kr', res.message || 'Failed to delete.'); }
                })
                .catch(function () { setError('okr-kr', 'Network error. Please try again.'); });
        }
    });

    // Inline Action Details / Dates editing - autosaves on blur/change
    // instead of requiring the pencil-icon modal. Status still only
    // editable via the modal.
    krListEl.addEventListener('focusin', function (e) {
        var textarea = e.target.closest ? e.target.closest('.okr-kr-desc-input') : null;
        if (textarea) { textarea.dataset.prevValue = textarea.value; }
    });

    krListEl.addEventListener('focusout', function (e) {
        var textarea = e.target.closest ? e.target.closest('.okr-kr-desc-input') : null;
        if (!textarea) { return; }

        var row = textarea.closest('.okr-kr-row');
        var id = parseInt(row.getAttribute('data-id'), 10);
        var data = krList.filter(function (r) { return r.id === id; })[0];
        if (!data) { return; }

        var description = textarea.value.trim();
        if (!description) {
            textarea.value = textarea.dataset.prevValue || data.description;
            return;
        }
        if (description === (textarea.dataset.prevValue || '').trim()) { return; }

        var body = new URLSearchParams();
        body.set('action', 'updateKeyResult');
        body.set('id', id);
        body.set('description', description);
        body.set('start_date', data.start_date || '');
        body.set('end_date', data.end_date || '');
        body.set('status_id', data.status_id);

        fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    data.description = description;
                } else {
                    textarea.value = textarea.dataset.prevValue || data.description;
                    setError('okr-kr', res.message || 'Failed to save Action Details.');
                }
            })
            .catch(function () {
                textarea.value = textarea.dataset.prevValue || data.description;
                setError('okr-kr', 'Network error. Please try again.');
            });
    });

    krListEl.addEventListener('change', function (e) {
        var dateInput = e.target.closest ? e.target.closest('.okr-kr-start-input, .okr-kr-end-input') : null;
        if (!dateInput) { return; }

        var row = dateInput.closest('.okr-kr-row');
        var id = parseInt(row.getAttribute('data-id'), 10);
        var data = krList.filter(function (r) { return r.id === id; })[0];
        if (!data) { return; }

        var isStart = dateInput.classList.contains('okr-kr-start-input');
        var startInputEl = row.querySelector('.okr-kr-start-input');
        var endInputEl = row.querySelector('.okr-kr-end-input');

        // Keep start <= end, same guard the modal's own Start/End inputs use.
        if (startInputEl.value && endInputEl.value && startInputEl.value > endInputEl.value) {
            if (isStart) { endInputEl.value = startInputEl.value; } else { startInputEl.value = endInputEl.value; }
        }

        var body = new URLSearchParams();
        body.set('action', 'updateKeyResult');
        body.set('id', id);
        body.set('description', data.description);
        body.set('start_date', startInputEl.value);
        body.set('end_date', endInputEl.value);
        body.set('status_id', data.status_id);

        fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    data.start_date = startInputEl.value || null;
                    data.end_date = endInputEl.value || null;
                    refreshKrDateBounds();
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
    });

    krListEl.addEventListener('change', function (e) {
        var statusSelectEl = e.target.closest ? e.target.closest('.okr-kr-status-input') : null;
        if (!statusSelectEl) { return; }

        var row = statusSelectEl.closest('.okr-kr-row');
        var id = parseInt(row.getAttribute('data-id'), 10);
        var data = krList.filter(function (r) { return r.id === id; })[0];
        if (!data) { return; }

        var newStatusId = statusSelectEl.value;

        var body = new URLSearchParams();
        body.set('action', 'updateKeyResult');
        body.set('id', id);
        body.set('description', data.description);
        body.set('start_date', data.start_date || '');
        body.set('end_date', data.end_date || '');
        body.set('status_id', newStatusId);

        fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    data.status_id = res.status_id;
                    data.status_value = res.status_value;
                    data.pill_class = res.pill_class;
                    statusSelectEl.setAttribute('data-status-id', res.status_id);
                } else {
                    statusSelectEl.value = statusSelectEl.getAttribute('data-status-id');
                    setError('okr-kr', res.message || 'Failed to save status.');
                }
            })
            .catch(function () {
                statusSelectEl.value = statusSelectEl.getAttribute('data-status-id');
                setError('okr-kr', 'Network error. Please try again.');
            });
    });

    document.getElementById('okr-kr-save-btn').addEventListener('click', function () {
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
            body.set('card_id', card.id);
            if (parentId) { body.set('parent_id', parentId); }
        }
        body.set('description', description);
        body.set('start_date', krStartInput.value);
        body.set('end_date', krEndInput.value);
        body.set('status_id', krStatusSelect.value);

        fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    krModal.hide();
                    loadKeyResults();
                } else {
                    setError('okr-kr-modal', res.message || 'Failed to save.');
                }
            })
            .catch(function () {
                setError('okr-kr-modal', 'Network error. Please try again.');
            });
    });

    // ---------------------------------------------------------------
    // Link ATEM modal - picks an existing card from the real ATEM module
    // ---------------------------------------------------------------
    var atemModalEl = document.getElementById('okr-kr-atem-modal');
    var atemModal = new bootstrap.Modal(atemModalEl);
    var atemSearchInput = document.getElementById('okr-kr-atem-search');
    var atemListEl = document.getElementById('okr-kr-atem-list');
    var atemTargetKrId = null;

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

    function openAtemModal(krId) {
        setError('okr-kr-atem-modal', '');
        atemTargetKrId = krId;
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
        body.set('action', 'linkKeyResultAtem');
        body.set('id', atemTargetKrId);
        body.set('atem_id', atemId);
        return fetch(CFG.apiUrl, { method: 'POST', body: body }).then(function (r) { return r.json(); });
    }

    atemListEl.addEventListener('click', function (e) {
        var row = e.target.closest('.okr-kr-atem-row');
        if (!row || !atemTargetKrId) { return; }
        var atemId = parseInt(row.getAttribute('data-atem-id'), 10);

        linkAtemToTarget(atemId)
            .then(function (res) {
                if (res.success) {
                    atemModal.hide();
                    loadKeyResults();
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
    // then linked to this Key Result the same way picking an existing card
    // would be. Saved as an ATEM Draft (mode: 'draft') so ATEM's own
    // reference-link requirement for a "final" card doesn't block this.
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
                            atemModal.hide();
                            loadKeyResults();
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

    loadKeyResults();

    // ---------------------------------------------------------------
    // Validation + save
    // ---------------------------------------------------------------
    function validate() {
        clearErrors();
        var ok = true;

        var objective = document.getElementById('okr-objective').value.trim();
        if (!objective) { setError('okr-objective', 'Objective is required.'); ok = false; }

        if (referenceLinks.length === 0) {
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

        if (!statusSelect.value) { setError('okr-status', 'Select a status.'); ok = false; }

        if (statusSelect.value === 'Extended' && !extendedCheckbox.checked) {
            setError('okr-status', 'Tick "Extended?" and set an Extended Date when Status is Extended.');
            ok = false;
        }

        if (extendedCheckbox.checked && !extendedDateInput.value) {
            setError('okr-status', 'Extended Date is required when Extended is checked.');
            ok = false;
        }

        if (ownerState.length === 0) { setError('okr-owner', 'An owner is required.'); ok = false; }

        refreshKrDateBounds();
        if (krDateWarningEl && krDateWarningEl.style.display !== 'none') {
            setError('okr-kr', 'Fix the Key Result dates that fall outside the OKR\'s Start/End Date before saving.');
            ok = false;
        }

        return ok;
    }

    function submitSave() {
        var deptScopeIds = ownerState.map(function (m) { return m.dept_id; }).filter(function (v) { return !!v; });
        var deptScope = deptScopeIds.filter(function (v, i) { return deptScopeIds.indexOf(v) === i; }).join(',');

        var owner1 = ownerState[0] || {};
        var owner2 = ownerState[1] || {};

        var payload = new URLSearchParams();
        payload.set('action', 'updateCard');
        payload.set('id', card.id);
        payload.set('objective', document.getElementById('okr-objective').value.trim());
        payload.set('okr_type', document.getElementById('okr-type').value);
        payload.set('owner_staff_id', owner1.staff_id || '');
        payload.set('owner2_staff_id', owner2.staff_id || '');
        payload.set('owner2_purpose', '');
        payload.set('dept_scope', deptScope);
        payload.set('start_date', document.getElementById('okr-start').value);
        payload.set('end_date', document.getElementById('okr-end').value);
        payload.set('result_status', statusSelect.value);
        payload.set('extended', extendedCheckbox.checked ? '1' : '');
        payload.set('extended_date', extendedDateInput.value);
        payload.set('remarks', document.getElementById('okr-remarks').value.trim());

        fetch(CFG.apiUrl, { method: 'POST', body: payload })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    leaving = true;
                    window.location.href = 'okr/view.php?id=' + card.id + '&saved=1';
                } else {
                    setError('okr-save', res.message || 'Failed to save OKR.');
                }
            })
            .catch(function () {
                setError('okr-save', 'Network error. Please try again.');
            });
    }

    document.getElementById('okr-save-btn').addEventListener('click', function () {
        if (!validate()) {
            scrollToFirstError();
            return;
        }
        submitSave();
    });

    // ---------------------------------------------------------------
    // Chat Box - per-card discussion thread, modeled after ATEM's Chat Box.
    // Own messages can be edited/unsent within a 60s window (also enforced
    // server-side); a 4s poll does a full resync so edits/unsends from other
    // viewers propagate, mirroring ATEM's polling pattern.
    // ---------------------------------------------------------------
    var chatWrapEl = document.getElementById('okr-chat-wrap');
    if (chatWrapEl && card.id) {
        var CHAT_EDIT_WINDOW_MS = 60000;
        var chatMessages = CFG.chatMessages ? CFG.chatMessages.slice() : [];
        var chatInput = document.getElementById('okr-chat-input');
        var chatSendBtn = document.getElementById('okr-chat-send-btn');

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
            body.set('id', card.id);
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
            body.set('id', card.id);
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
        setInterval(loadChatMessages, 4000);
        setInterval(refreshChatActionVisibility, 5000);
    }
})();
