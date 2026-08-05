(function () {
    var CFG = window.OKR_EDIT_CONFIG || { staff: [], departments: [], levels: [] };
    var card = CFG.card || {};

    // Bootstrap 5 popovers need explicit JS init - data-bs-toggle="popover"
    // alone (the OKR Type field's info icon) does nothing without this.
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
        new bootstrap.Popover(el);
    });

    var referenceLinks = (CFG.referenceLinks || []).slice(); // { id, name, url }
    var attachments = (CFG.attachments || []).slice(); // { id, original_name, size }

    // Warn on refresh/close/back navigation once the user has started editing,
    // same as create.php.
    var dirty = false;
    var leaving = false;
    // Chat and CEO Action/Appeal Suspension are their own independent,
    // immediately-saved actions (not part of the OKR form's fields), so
    // typing in their textareas must not trip the "unsaved changes"
    // leave-guard below.
    function markChanged(e) {
        if (e && e.target && e.target.closest && e.target.closest('#okr-chat-card, #okr-kr-atem-modal, #okr-ceo-action-row')) { return; }
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

    // Extended is normally once-only and locked once set - admin is exempt
    // (full override authority, see backend.php's updateCard) and keeps the
    // checkbox/date editable even after the card is already extended.
    var canEditExtended = !!CFG.isAdmin || !card.extended;
    if (canEditExtended) {
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

    var reflinkSaveBtn = document.getElementById('reflink-save-btn');
    reflinkSaveBtn.addEventListener('click', function () {
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

        setButtonLoading(reflinkSaveBtn, 'Saving...');
        fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                restoreButton(reflinkSaveBtn);
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
                restoreButton(reflinkSaveBtn);
                setError('reflink', 'Network error. Please try again.');
            });
    });

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
                    attachments.push({ id: res.id, original_name: file.name, size: file.size, mime_type: file.type });
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
                        renderFiles();
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
        }
    });

    renderFiles();

    // ---------------------------------------------------------------
    // Key Result Progress (real actions against the existing card - a
    // 2-level Key Result / Subtask list, self-referential via parent_id,
    // modeled after iidas's project_detail.php Progression Task widget)
    // ---------------------------------------------------------------
    var krList = []; // flat rows from listKeyResults: { id, parent_id, description, created_by, creator_name, atem_id, status_id, status_value, pill_class, start_date, end_date, has_children, display_status_value, display_pill_class }
    var atemMap = {}; // resolved ATEM id -> { id, title, ... }, lazily fetched from list-atems
    var krListEl = document.getElementById('okr-kr-list');
    var krModalEl = document.getElementById('okr-kr-modal');
    var krModal = new bootstrap.Modal(krModalEl);
    var krCreatedByInput = document.getElementById('okr-kr-created-by');
    var krStartInput = document.getElementById('okr-kr-start');
    var krEndInput = document.getElementById('okr-kr-end');
    var krStatusSelect = document.getElementById('okr-kr-status');
    var krDeleteModalEl = document.getElementById('okr-kr-delete-modal');
    var krDeleteModal = new bootstrap.Modal(krDeleteModalEl);
    var krDeleteTarget = null; // { id, atem_id }

    function krChildren(parentId) {
        return krList.filter(function (r) { return r.parent_id === parentId; });
    }

    function krRowHtml(row, index, isSubtask) {
        var dragHandle = isSubtask ? '<span class="okr-kr-drag-handle" title="Drag to reorder">&#9776;</span>' : '';

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

        return '<div class="okr-kr-row' + (isSubtask ? ' okr-kr-row--subtask' : '') + '" data-id="' + row.id + '"'
            + (isSubtask ? ' draggable="true" data-parent-id="' + row.parent_id + '"' : '') + '>'
            + '<div class="okr-kr-num">' + dragHandle + index + '</div>'
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
        refreshKrDateBounds();
    }

    // ---------------------------------------------------------------
    // Subtask drag-to-reorder - modeled after iidas's project_detail.js
    // Task/Subtask drag-and-drop (native HTML5 DnD, no library). Only
    // Subtasks can be dragged (not top-level Key Results), and only among
    // siblings under the same parent (data-parent-id must match). Event
    // listeners are delegated on krListEl so they survive re-renders.
    // ---------------------------------------------------------------
    var draggedSubtaskEl = null;

    function persistSubtaskOrder(parentIdStr) {
        var domSubtasks = krListEl.querySelectorAll('.okr-kr-row--subtask[data-parent-id="' + parentIdStr + '"]');
        var orders = {};
        domSubtasks.forEach(function (el, i) {
            var subId = parseInt(el.getAttribute('data-id'), 10);
            orders[subId] = i;
            var data = krList.filter(function (r) { return r.id === subId; })[0];
            if (data) { data.sort_order = i; }
        });
        // Stable sort - only reorders items that share the same parent_id,
        // everything else (top-level order, other parents' children) is
        // left exactly where it was.
        krList.sort(function (a, b) {
            if (a.parent_id === null || b.parent_id === null || a.parent_id !== b.parent_id) { return 0; }
            return (a.sort_order || 0) - (b.sort_order || 0);
        });
        renderKeyResults();

        var body = new URLSearchParams();
        body.set('action', 'reorderKeyResultSubtasks');
        body.set('parent_id', parentIdStr);
        body.set('orders', JSON.stringify(orders));
        fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    setError('okr-kr', res.message || 'Failed to save new order.');
                    loadKeyResults();
                }
            })
            .catch(function () {
                setError('okr-kr', 'Network error. Please try again.');
                loadKeyResults();
            });
    }

    krListEl.addEventListener('dragstart', function (e) {
        var row = e.target.closest ? e.target.closest('.okr-kr-row--subtask[draggable="true"]') : null;
        if (!row) { e.preventDefault(); return; }
        draggedSubtaskEl = row;
        row.style.opacity = '0.5';
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', row.getAttribute('data-id'));
    });

    krListEl.addEventListener('dragend', function () {
        if (draggedSubtaskEl) { draggedSubtaskEl.style.opacity = ''; }
        draggedSubtaskEl = null;
        krListEl.querySelectorAll('.okr-kr-drag-over').forEach(function (el) {
            el.classList.remove('okr-kr-drag-over');
        });
    });

    krListEl.addEventListener('dragover', function (e) {
        if (!draggedSubtaskEl) { return; }
        var row = e.target.closest ? e.target.closest('.okr-kr-row--subtask') : null;
        if (!row || row === draggedSubtaskEl
            || row.getAttribute('data-parent-id') !== draggedSubtaskEl.getAttribute('data-parent-id')) { return; }
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        row.classList.add('okr-kr-drag-over');
    });

    krListEl.addEventListener('dragleave', function (e) {
        var row = e.target.closest ? e.target.closest('.okr-kr-row--subtask') : null;
        if (row) { row.classList.remove('okr-kr-drag-over'); }
    });

    krListEl.addEventListener('drop', function (e) {
        if (!draggedSubtaskEl) { return; }
        var row = e.target.closest ? e.target.closest('.okr-kr-row--subtask') : null;
        if (!row || row === draggedSubtaskEl) { return; }
        var parentId = draggedSubtaskEl.getAttribute('data-parent-id');
        if (row.getAttribute('data-parent-id') !== parentId) { return; }
        e.preventDefault();
        row.classList.remove('okr-kr-drag-over');

        var rect = row.getBoundingClientRect();
        var after = (e.clientY - rect.top) > (rect.height / 2);
        row.parentNode.insertBefore(draggedSubtaskEl, after ? row.nextSibling : row);

        persistSubtaskOrder(parentId);
    });

    function loadKeyResults() {
        var body = new URLSearchParams();
        body.set('action', 'listKeyResults');
        body.set('id', card.id);
        fetch(CFG.apiUrl + '?' + body.toString())
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    setError('okr-kr', res.message || 'Failed to load Key Results.');
                    return;
                }
                krList = res.data || [];
                var hasUnresolved = krList.some(function (r) { return r.atem_id && !atemMap[r.atem_id]; });
                if (!hasUnresolved) {
                    renderKeyResults();
                    return;
                }
                fetch(CFG.atemApiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list-atems' })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (atemRes) {
                        var items = (atemRes && atemRes.success) ? (atemRes.data || []) : [];
                        items.forEach(function (a) { atemMap[a.id] = a; });
                        renderKeyResults();
                    })
                    .catch(function () { renderKeyResults(); });
            })
            .catch(function () {
                setError('okr-kr', 'Network error while loading Key Results.');
            });
    }

    var krModalOriginalStatusId = null;

    function openKrModal(opts) {
        setError('okr-kr-modal', '');
        document.getElementById('okr-kr-id').value = opts.id || '';
        document.getElementById('okr-kr-parent-id').value = opts.parent_id || '';
        document.getElementById('okr-kr-desc').value = opts.description || '';
        krStartInput.value = opts.start_date || '';
        krEndInput.value = opts.end_date || '';
        krModalOriginalStatusId = opts.status_id || null;
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

    krListEl.addEventListener('click', function (e) {
        var row = e.target.closest ? e.target.closest('.okr-kr-row') : null;
        if (!row) { return; }
        var id = parseInt(row.getAttribute('data-id'), 10);
        var data = krList.filter(function (r) { return r.id === id; })[0];
        if (!data) { return; }

        if (e.target.closest('.okr-kr-add-atem')) {
            openAtemModal(id, data.description);
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
            if (data.atem_id) {
                openKrDeleteModal(data);
                return;
            }
            confirmAction(
                'Delete this ' + (data.parent_id ? 'Subtask' : 'Key Result') + '? This cannot be undone.',
                function () { return performDeleteKeyResult(id); },
                'Delete', 'btn-danger'
            );
        }
    });

    function performDeleteKeyResult(id) {
        var body = new URLSearchParams();
        body.set('action', 'deleteKeyResult');
        body.set('id', id);
        return fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) { loadKeyResults(); }
                else { setError('okr-kr', res.message || 'Failed to delete.'); }
            })
            .catch(function () { setError('okr-kr', 'Network error. Please try again.'); });
    }

    // Reverse-unlink (ATEM -> OKR): clears atems.okr_id, mirrors linkAtemOkrReverse
    // in the AtemLink IIFE below but reachable from this scope (delete flow
    // isn't nested inside that IIFE).
    function unlinkAtemFromOkr(atemId) {
        return fetch(CFG.atemApiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'link-atem-okr', id: atemId, okr_id: null })
        }).then(function (r) { return r.json(); });
    }

    function deleteAtemBridge(atemId, remark) {
        return fetch(CFG.atemApiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete-atem', id: atemId, remarks: remark })
        }).then(function (r) { return r.json(); });
    }

    // ---------------------------------------------------------------
    // Delete Key Result / Subtask when an ATEM is linked - offers to either
    // unlink the ATEM (default, ATEM itself is untouched) or delete the ATEM
    // too. Deleting the ATEM is only offered when the requester is its
    // Issuer and it's still Draft/Active - a stricter, OKR-side-only rule on
    // top of atem-api's own destroy() guard (issuer-or-SuperAdmin, blocks
    // only the 4 terminal statuses) since this button intentionally doesn't
    // expose the SuperAdmin override atem-api's endpoint otherwise allows.
    // ---------------------------------------------------------------
    var krDeleteMsgEl = document.getElementById('okr-kr-delete-modal-message');
    var krDeleteAtemWrapEl = document.getElementById('okr-kr-delete-atem-wrap');
    var krDeleteRemarkInput = document.getElementById('okr-kr-delete-remark');
    var krDeleteAtemBtn = document.getElementById('okr-kr-delete-atem-btn');

    function openKrDeleteModal(data) {
        setError('okr-kr-delete-modal', '');
        krDeleteRemarkInput.value = '';
        krDeleteTarget = { id: data.id, atem_id: data.atem_id };

        var atem = atemMap[data.atem_id];
        var atemTitle = atem ? atem.title : ('ATEM #' + data.atem_id);
        var atemIssuerId = atem ? atem.issuer_staff_id : null;
        var atemStatusValue = atem
            ? (typeof atem.status === 'string' ? atem.status : (atem.status && atem.status.value))
            : null;
        var canDeleteAtem = !!(CFG.currentStaffId && atemIssuerId && CFG.currentStaffId === atemIssuerId
            && (atemStatusValue === 'Active' || atemStatusValue === 'Draft'));

        document.getElementById('okr-kr-delete-modal-title').textContent =
            'Delete this ' + (data.parent_id ? 'Subtask' : 'Key Result') + '?';

        if (canDeleteAtem) {
            krDeleteMsgEl.textContent = 'This is linked to ATEM "' + atemTitle + '". Choose whether to delete '
                + 'the ATEM as well, or keep it and just unlink it from this OKR.';
            krDeleteAtemWrapEl.style.display = '';
            krDeleteAtemBtn.style.display = '';
        } else {
            var why = !atem ? '' : (atemIssuerId !== CFG.currentStaffId
                ? 'only the ATEM Issuer can delete it'
                : 'it is no longer Draft or Active');
            krDeleteMsgEl.textContent = 'This is linked to ATEM "' + atemTitle + '". The ATEM cannot be deleted '
                + 'from this page' + (why ? ' because ' + why : '') + ' - deleting this Key Result will unlink it '
                + 'instead; the ATEM itself will remain.';
            krDeleteAtemWrapEl.style.display = 'none';
            krDeleteAtemBtn.style.display = 'none';
        }

        krDeleteModal.show();
    }

    var krDeleteOnlyBtn = document.getElementById('okr-kr-delete-only-btn');
    krDeleteOnlyBtn.addEventListener('click', function () {
        if (!krDeleteTarget) { return; }
        var target = krDeleteTarget;
        setError('okr-kr-delete-modal', '');
        setButtonLoading(krDeleteOnlyBtn, 'Deleting...');
        unlinkAtemFromOkr(target.atem_id)
            .then(function (res) {
                if (!res.success) {
                    restoreButton(krDeleteOnlyBtn);
                    setError('okr-kr-delete-modal', res.message || 'Failed to unlink ATEM.');
                    return;
                }
                krDeleteModal.hide();
                restoreButton(krDeleteOnlyBtn);
                performDeleteKeyResult(target.id);
            })
            .catch(function () {
                restoreButton(krDeleteOnlyBtn);
                setError('okr-kr-delete-modal', 'Network error. Please try again.');
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
                krDeleteModal.hide();
                restoreButton(krDeleteAtemBtn);
                performDeleteKeyResult(target.id);
            })
            .catch(function () {
                restoreButton(krDeleteAtemBtn);
                setError('okr-kr-delete-modal', 'Network error. Please try again.');
            });
    });

    var krSaveBtn = document.getElementById('okr-kr-save-btn');
    krSaveBtn.addEventListener('click', function () {
        setError('okr-kr-modal', '');
        var description = document.getElementById('okr-kr-desc').value.trim();
        if (!description) {
            setError('okr-kr-modal', 'Action Details is required.');
            return;
        }

        var id = document.getElementById('okr-kr-id').value;
        var parentId = document.getElementById('okr-kr-parent-id').value;
        var statusChanged = id && String(krStatusSelect.value) !== String(krModalOriginalStatusId || '');

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

        setButtonLoading(krSaveBtn, 'Saving...');
        fetch(CFG.apiUrl, { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    krModal.hide();
                    restoreButton(krSaveBtn);
                    // Its status just changed and it's linked to an ATEM - rather than
                    // silently mirroring the new status onto the ATEM (which would skip
                    // atem-api's own business rules, e.g. requiring an Outcome Attachment
                    // before Completed), send the user to update the ATEM itself.
                    if (statusChanged && res.atem_id) {
                        window.location.href = CFG.atemViewUrl + '?id=' + res.atem_id + '&mode=edit';
                        return;
                    }
                    loadKeyResults();
                } else {
                    restoreButton(krSaveBtn);
                    setError('okr-kr-modal', res.message || 'Failed to save.');
                }
            })
            .catch(function () {
                restoreButton(krSaveBtn);
                setError('okr-kr-modal', 'Network error. Please try again.');
            });
    });

    loadKeyResults();

    // ---------------------------------------------------------------
    // Link ATEM modal: Search Existing ATEM (always visible) + Create New
    // ATEM (toggled inline below it, mirrors atem/create.php's own fields
    // and submits via atem/api.php's save-atem action exactly like that
    // page does). Ported from create.js, adapted for real (already-saved)
    // Key Result/Subtask rows addressed by id instead of staged tokens -
    // linkAtemToTarget persists via the real linkKeyResultAtem backend
    // action instead of stageKeyResultAtemLink. Wrapped in its own IIFE
    // since it ports a large amount of atem/js/create.js's logic - keeping
    // it in a private scope avoids any name collisions with this file's own
    // escapeHtml/setError/fillSelect (which have different signatures than
    // ATEM's originals).
    // ---------------------------------------------------------------
    var AtemLink = (function () {
        var ATEM_CFG = window.ATEM_CREATE_CONFIG || {};
        var atemModalEl = document.getElementById('okr-kr-atem-modal');
        var atemModal = new bootstrap.Modal(atemModalEl);
        var atemTargetIdInput = document.getElementById('okr-kr-atem-target-id');
        var pendingAtemTitle = ''; // Action text of the Key Result the modal was opened against - prefills the Create New ATEM title

        function $(id) { return document.getElementById(id); }

        // ---- link-back to OKR: persists atem_id onto the real Key Result
        // (or Subtask) row that the modal was opened against. Shared by both
        // the Search pick and the Create-New save. ----
        function linkAtemToTarget(atemId) {
            var body = new URLSearchParams();
            body.set('action', 'linkKeyResultAtem');
            body.set('id', atemTargetIdInput.value);
            body.set('atem_id', atemId);
            return fetch(CFG.apiUrl, { method: 'POST', body: body }).then(function (r) { return r.json(); });
        }

        function applyLinkedAtem(atemId, title) {
            var data = krList.filter(function (r) { return r.id === parseInt(atemTargetIdInput.value, 10); })[0];
            if (data) {
                data.atem_id = atemId;
                data.atem_title = title || ('ATEM #' + atemId);
            }
            atemMap[atemId] = { id: atemId, title: title || ('ATEM #' + atemId) };
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

        // Reverse link (ATEM -> OKR): sets/clears atems.okr_id via atem-api's
        // dedicated okr-link endpoint. Only relevant when linking an existing
        // ATEM - Create New sets okr_id directly at creation time instead.
        function linkAtemOkrReverse(atemId) {
            return fetch(CFG.atemApiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'link-atem-okr', id: atemId, okr_id: card.id })
            }).then(function (r) { return r.json(); });
        }

        listEl.addEventListener('click', function (e) {
            var row = e.target.closest ? e.target.closest('.okr-kr-atem-row') : null;
            if (!row) { return; }
            var atemId = parseInt(row.getAttribute('data-atem-id'), 10);
            var title = row.getAttribute('data-atem-title');
            searchErrorEl.textContent = '';
            confirmAction('Link "' + title + '" to this OKR? This ATEM will be tied to this OKR card.', function () {
                return linkAtemOkrReverse(atemId)
                    .then(function (okrLinkRes) {
                        if (!okrLinkRes.success) {
                            searchErrorEl.textContent = okrLinkRes.message || 'Failed to link ATEM.';
                            return;
                        }
                        return linkAtemToTarget(atemId).then(function (res) {
                            if (res.success) {
                                applyLinkedAtem(atemId, title);
                                atemModal.hide();
                            } else {
                                searchErrorEl.textContent = res.message || 'ATEM linked but failed to attach to Key Result.';
                            }
                        });
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
        // to this OKR (already a real, saved card on edit.php - unlike
        // create.php's not-yet-saved draft), so this ATEM is traceable back to
        // the OKR that spawned it, and the issuer isn't forced to go find/paste
        // that URL manually. Added once per Create-pane open, not re-added if
        // already present.
        function addOkrReferenceLinkIfMissing() {
            // atem-api's reference_links.*.url validation requires a fully
            // qualified URL, not a bare relative path - resolve against
            // <base href="/odb/"> so this works in every environment
            // (localhost, staging, production) without hardcoding a host.
            var url = new URL(CFG.okrViewUrl + '?id=' + card.id, document.baseURI).href;
            if (reflinks.some(function (r) { return r.url === url; })) { return; }
            var objectiveEl = document.getElementById('okr-objective');
            var objective = objectiveEl ? objectiveEl.value.trim() : '';
            var name = objective ? ('OKR: ' + objective.slice(0, 60)) : ('OKR #' + card.id);
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
                okr_id: card.id,
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
                            applyLinkedAtem(res.data.id, payload.title);
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

        // ---- public: open the modal against a given Key Result/Subtask id ----
        function open(id, description) {
            initOnce();
            atemTargetIdInput.value = id;
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

    function openAtemModal(id, description) {
        AtemLink.open(id, description);
    }

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

        // Ticking Extended + setting an Extended Date without also updating
        // Status leaves an inconsistent record (e.g. still "Active"). Admin
        // is exempt (full override authority, matches the server-side bypass
        // in backend.php's updateCard) - everyone else must follow one of
        // the two allowed sets below, matching
        // okrPostExtensionResolvableStatuses in lib.php:
        // - card.extended was already true when this page loaded (a
        //   subsequent edit of an already-extended card): Status must
        //   actually resolve it now - Completed (stored as Completed with
        //   Extension) or Failed only. Staying on "Extended" is no longer
        //   allowed past the first save.
        // - card.extended was false (the user is extending for the first
        //   time, this session): Status may still be left as "Extended" (an
        //   ongoing, not-yet-resolved state), or resolved immediately as
        //   Completed/Failed.
        if (!CFG.isAdmin && extendedCheckbox.checked && extendedDateInput.value) {
            var allowedOnceExtended = card.extended ? ['Completed', 'Failed'] : ['Extended', 'Completed', 'Failed'];
            if (allowedOnceExtended.indexOf(statusSelect.value) === -1) {
                var msg = card.extended
                    ? 'This OKR has already been extended - please change Status to Completed (with Extension) or Failed.'
                    : 'You\'ve set an Extended Date - please change Status to Extended (or Completed/Failed if this OKR is already resolved).';
                setError('okr-status', msg);
                ok = false;
            }
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
        var closureDateEl = document.getElementById('okr-closure');
        if (closureDateEl && !closureDateEl.disabled) { payload.set('closure_date', closureDateEl.value); }

        var saveBtn = document.getElementById('okr-save-btn');
        setButtonLoading(saveBtn, 'Saving...');
        fetch(CFG.apiUrl, { method: 'POST', body: payload })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    leaving = true;
                    window.location.href = 'okr/list.php';
                } else {
                    restoreButton(saveBtn);
                    setError('okr-save', res.message || 'Failed to save OKR.');
                }
            })
            .catch(function () {
                restoreButton(saveBtn);
                setError('okr-save', 'Network error. Please try again.');
            });
    }

    document.getElementById('okr-save-btn').addEventListener('click', function () {
        if (!validate()) {
            scrollToFirstError();
            return;
        }
        // Admin editing an OKR they didn't issue is a full override of the
        // normal issuer-only edit gate - make sure that's an intentional
        // action, not an accidental save while browsing someone else's card.
        if (CFG.isAdmin && CFG.currentStaffId && card.issuer_staff_id !== CFG.currentStaffId) {
            var warn = 'You are ADMIN and about to overwrite an OKR issued by ' + (card.issuer_name || 'another staff member') +
                ', not yourself. This bypasses normal edit restrictions (including the once-only Extended lock) and cannot be undone.\n\nContinue?';
            if (!window.confirm(warn)) { return; }
        }
        submitSave();
    });

    // ---------------------------------------------------------------
    // CEO Action (Suspend/Unsuspend/Force Terminate) + Appeal Suspension -
    // same markup/actions as view.php's, so the CEO/admin doesn't have to
    // leave the edit form just to suspend an OKR. Unsuspend/Force-Terminate-
    // while-Suspended and appeal-submission never actually render here
    // (edit.php redirects away once a card is Suspended/Failed), but this
    // mirrors view.js's handlers exactly so both stay in sync.
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
            payload.set('id', card.id);
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
            payload.set('id', card.id);

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
            payload.set('id', card.id);
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
            payload.set('id', card.id);
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

            setButtonLoading(chatSendBtn, 'Sending...');
            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    restoreButton(chatSendBtn);
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
                    restoreButton(chatSendBtn);
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
            var saveBtn = bodyEl.querySelector('.okr-chat-save-btn');

            var body = new URLSearchParams();
            body.set('action', 'editChatMessage');
            body.set('id', id);
            body.set('message', message);

            setButtonLoading(saveBtn, 'Saving...');
            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        var m = chatMessages.filter(function (x) { return x.id === id; })[0];
                        if (m) { m.message = res.message; }
                        renderChat();
                    } else {
                        restoreButton(saveBtn);
                        alert(res.message || 'Failed to save message.');
                    }
                })
                .catch(function () {
                    restoreButton(saveBtn);
                    alert('Network error. Please try again.');
                });
        }

        function unsendChatMessage(id) {
            confirmAction('Unsend this message?', function () {
                var body = new URLSearchParams();
                body.set('action', 'unsendChatMessage');
                body.set('id', id);
                return fetch(CFG.apiUrl, { method: 'POST', body: body })
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
            }, 'Unsend', 'btn-danger');
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
