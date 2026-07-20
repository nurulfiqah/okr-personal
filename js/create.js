(function () {
    var CFG = window.OKR_CONFIG || { staff: [], departments: [], levels: [] };

    var referenceLinks = []; // { token, name, url }
    var stagedFiles = []; // { token, name, size }

    // Warn on refresh/close/back navigation once the user has started filling
    // in the form, same as ATEM's create page.
    var dirty = false;
    var leaving = false;
    function markChanged() { dirty = true; }
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

    // ---------------------------------------------------------------
    // Key Results rich text editor (same Quill setup as ATEM's description
    // editor: full toolbar, no custom link/image handlers needed here).
    // ---------------------------------------------------------------
    var keyResultsEditor = null;
    if (typeof Quill !== 'undefined') {
        keyResultsEditor = new Quill('#okr-key-results-editor', {
            theme: 'snow',
            modules: {
                toolbar: [
                    [{ 'header': [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ 'color': [] }, { 'background': [] }],
                    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                    [{ 'indent': '-1' }, { 'indent': '+1' }],
                    [{ 'align': [] }],
                    ['link', 'image'],
                    ['clean']
                ]
            },
            placeholder: 'Write the measurable results in detail here....'
        });
        keyResultsEditor.on('text-change', function (delta, old, source) {
            if (source === 'user') { markChanged(); }
        });
    }

    // ---------------------------------------------------------------
    // Start/End dates: End Date can't be before Start Date. Start Date
    // also can't be before today unless the admin's Backdate toggle is on
    // (okr/admin/index.php).
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
    });

    function keyResultsHtml() {
        return keyResultsEditor && keyResultsEditor.getText().trim() !== '' ? keyResultsEditor.root.innerHTML : '';
    }

    var levelSelect = document.getElementById('okr-level');
    var incentiveRuleSelect = document.getElementById('okr-incentive-rule');
    var owner2PurposeWrap = document.getElementById('okr-owner2-purpose-wrap');
    var incentiveRuleHint = document.getElementById('okr-incentive-rule-hint');

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    CFG.levels.forEach(function (lv) {
        var opt = document.createElement('option');
        opt.value = lv.level;
        opt.textContent = lv.label + ' (RM' + Number(lv.base_rm).toFixed(2) + ')';
        levelSelect.appendChild(opt);
    });
    (CFG.incentiveRules || []).forEach(function (rule) {
        var opt = document.createElement('option');
        opt.value = rule.id;
        opt.textContent = rule.label;
        incentiveRuleSelect.appendChild(opt);
    });

    // ---------------------------------------------------------------
    // Owner(s): ATEM ARCI-style tagging widget, restricted to a single
    // "A - Accountable" role capped at 2 members (OKR has no R/C/I). Whoever
    // is ticked "Incentivised" receives the payout; the tick only appears
    // once 2 owners are tagged and Rule 1 (single incentivised owner) is
    // selected - Rule 2 splits 50/50 automatically and needs no ticking.
    // ---------------------------------------------------------------
    var ownerState = []; // [{ staff_id, staff_name, dept_id, department_name, is_incentivised }]

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

    function selectedIncentiveRule() {
        return (CFG.incentiveRules || []).filter(function (r) { return String(r.id) === incentiveRuleSelect.value; })[0];
    }

    function countIncentivisedOwners() {
        var n = 0;
        ownerState.forEach(function (m) { if (m.is_incentivised) { n++; } });
        return n;
    }

    function renderOwnerMembers() {
        if (ownerState.length === 0) {
            ownerMembersEl.innerHTML = '<div class="okr-arci-empty">No owners assigned</div>';
        } else {
            var rule = selectedIncentiveRule();
            var showTick = ownerState.length === 2 && rule && rule.code === 'RULE1';
            var incCount = countIncentivisedOwners();
            var html = '';
            ownerState.forEach(function (m) {
                var tickHtml = '';
                if (showTick) {
                    var atMax = !m.is_incentivised && incCount >= 1;
                    tickHtml = '<label class="okr-arci-incentivised">'
                        + '<input type="checkbox" class="okr-owner-incentivised-chk" data-staff="' + m.staff_id + '"'
                        + (m.is_incentivised ? ' checked' : '')
                        + (atMax ? ' disabled' : '') + '> Incentivised</label>';
                }
                html += '<div class="okr-arci-member">'
                    + '<div class="okr-arci-member-info">'
                    + '<div class="okr-arci-member-dept">(' + escapeHtml(m.department_name || '') + ')</div>'
                    + '<div class="okr-arci-member-name">' + escapeHtml(m.staff_name) + '</div>'
                    + '</div>'
                    + tickHtml
                    + '<span class="okr-arci-remove" data-staff="' + m.staff_id + '" title="Remove">&times;</span>'
                    + '</div>';
            });
            ownerMembersEl.innerHTML = html;
        }
        owner2PurposeWrap.style.display = ownerState.length === 2 ? 'block' : 'none';
    }

    ownerMembersEl.addEventListener('click', function (e) {
        if (e.target.classList.contains('okr-arci-remove')) {
            var staffId = parseInt(e.target.getAttribute('data-staff'), 10);
            ownerState = ownerState.filter(function (m) { return m.staff_id !== staffId; });
            markChanged();
            refreshIncentiveRuleVisibility();
        }
    });
    ownerMembersEl.addEventListener('change', function (e) {
        if (e.target.classList.contains('okr-owner-incentivised-chk')) {
            var staffId = parseInt(e.target.getAttribute('data-staff'), 10);
            var checked = e.target.checked;
            ownerState.forEach(function (m) {
                if (m.staff_id === staffId) { m.is_incentivised = checked; }
            });
            refreshIncentiveRuleVisibility();
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
                department_name: deptName,
                is_incentivised: false
            });
        }
        ownerDeptSelect.value = '';
        ownerStaffSearch.value = '';
        markChanged();
        refreshIncentiveRuleVisibility();
    });

    function refreshIncentiveRuleVisibility() {
        var selectedLevel = (CFG.levels || []).filter(function (l) { return String(l.level) === levelSelect.value; })[0];
        var noPayout = !!(selectedLevel && Number(selectedLevel.base_rm) === 0);

        incentiveRuleSelect.disabled = noPayout;

        var rule = selectedIncentiveRule();
        incentiveRuleHint.textContent = rule ? rule.payout_logic : '';

        renderOwnerMembers();
        renderOwnerStaffList();
        refreshIncentiveBreakdown();
    }

    var breakdownEl = document.getElementById('okr-incentive-breakdown');
    var stat1El = document.getElementById('okr-incentive-stat1');
    var stat1LabelEl = document.getElementById('okr-incentive-stat1-label');
    var stat1ValueEl = document.getElementById('okr-incentive-stat1-value');
    var stat2El = document.getElementById('okr-incentive-stat2');
    var stat2LabelEl = document.getElementById('okr-incentive-stat2-label');
    var stat2ValueEl = document.getElementById('okr-incentive-stat2-value');

    function refreshIncentiveBreakdown() {
        var selectedLevel = (CFG.levels || []).filter(function (l) { return String(l.level) === levelSelect.value; })[0];
        var baseRm = selectedLevel ? Number(selectedLevel.base_rm) : 0;

        if (ownerState.length === 0 || baseRm <= 0) {
            breakdownEl.style.display = 'none';
            return;
        }

        stat1LabelEl.textContent = '1st Owner · ' + ownerState[0].staff_name;

        if (ownerState.length < 2) {
            stat1El.classList.add('okr-incentive-stat--full');
            stat1ValueEl.textContent = 'RM' + baseRm.toFixed(2);
            stat2El.style.display = 'none';
        } else {
            stat1El.classList.remove('okr-incentive-stat--full');
            stat2El.style.display = 'block';
            stat2LabelEl.textContent = '2nd Owner · ' + ownerState[1].staff_name;

            var rule = selectedIncentiveRule();
            if (rule && rule.code === 'RULE2') {
                stat1ValueEl.textContent = 'RM' + (baseRm / 2).toFixed(2);
                stat2ValueEl.textContent = 'RM' + (baseRm / 2).toFixed(2);
            } else if (rule && rule.code === 'RULE1') {
                stat1ValueEl.textContent = ownerState[0].is_incentivised ? 'RM' + baseRm.toFixed(2) : 'RM0.00';
                stat2ValueEl.textContent = ownerState[1].is_incentivised ? 'RM' + baseRm.toFixed(2) : 'RM0.00';
            } else {
                stat1ValueEl.textContent = 'RM0.00';
                stat2ValueEl.textContent = 'RM0.00';
            }
        }
        breakdownEl.style.display = 'grid';
    }

    refreshIncentiveRuleVisibility();

    incentiveRuleSelect.addEventListener('change', refreshIncentiveRuleVisibility);

    var levelRubricEl = document.getElementById('okr-level-rubric');
    var levelRmEl = document.getElementById('okr-level-rm');
    levelSelect.addEventListener('change', function () {
        var lv = CFG.levels.filter(function (l) { return String(l.level) === levelSelect.value; })[0];
        if (lv) {
            levelRubricEl.textContent = lv.rubric_text || '';
            levelRmEl.textContent = 'RM' + Number(lv.base_rm).toFixed(2);
        } else {
            levelRubricEl.textContent = 'Select a difficulty level to see its rubric and RM.';
            levelRmEl.textContent = 'RM0.00';
        }
        refreshIncentiveRuleVisibility();
    });

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
    // Validation + save
    // ---------------------------------------------------------------
    function validate() {
        clearErrors();
        var ok = true;

        var objective = document.getElementById('okr-objective').value.trim();
        if (!objective) { setError('okr-objective', 'Objective is required.'); ok = false; }

        var keyResults = keyResultsHtml();
        if (!keyResults) { setError('okr-key-results', 'Key Results are required.'); ok = false; }

        if (referenceLinks.length === 0) {
            setError('reflink-section', 'At least one reference link (e.g. the Trello board) is required.');
            ok = false;
        }

        var type = document.getElementById('okr-type').value;
        if (!type) { setError('okr-type', 'Select an OKR type.'); ok = false; }

        var level = levelSelect.value;
        if (!level) { setError('okr-level', 'Select a difficulty level.'); ok = false; }

        var start = document.getElementById('okr-start').value;
        if (!start) { setError('okr-start', 'Start date is required.'); ok = false; }

        var end = document.getElementById('okr-end').value;
        if (!end) { setError('okr-end', 'End date is required.'); ok = false; }
        else if (start && end < start) { setError('okr-end', 'End date cannot be before start date.'); ok = false; }

        if (ownerState.length === 0) { setError('okr-owner', 'An owner is required.'); ok = false; }

        if (ownerState.length === 2) {
            var rule = selectedIncentiveRule();
            if (!rule) {
                setError('okr-incentive-rule', 'Select an incentive rule.');
                ok = false;
            } else if (rule.code === 'RULE1' && countIncentivisedOwners() !== 1) {
                setError('okr-owner', 'Tick which owner receives the incentive.');
                ok = false;
            }
        }

        return ok;
    }

    document.getElementById('okr-save-btn').addEventListener('click', function () {
        if (!validate()) {
            scrollToFirstError();
            return;
        }

        var deptScopeIds = ownerState.map(function (m) { return m.dept_id; }).filter(function (v) { return !!v; });
        var deptScope = deptScopeIds.filter(function (v, i) { return deptScopeIds.indexOf(v) === i; }).join(',');

        var owner1 = ownerState[0] || {};
        var owner2 = ownerState[1] || {};
        var incentivisedOwnerId = '';
        if (ownerState.length === 1) {
            incentivisedOwnerId = owner1.staff_id;
        } else if (ownerState.length === 2) {
            var rule2 = selectedIncentiveRule();
            if (rule2 && rule2.code === 'RULE1') {
                var incMember = ownerState.filter(function (m) { return m.is_incentivised; })[0];
                incentivisedOwnerId = incMember ? incMember.staff_id : '';
            }
        }

        var payload = new URLSearchParams();
        payload.set('action', 'createCard');
        payload.set('objective', document.getElementById('okr-objective').value.trim());
        payload.set('key_results', keyResultsHtml());
        payload.set('okr_type', document.getElementById('okr-type').value);
        payload.set('difficulty_level', levelSelect.value);
        payload.set('owner_staff_id', owner1.staff_id || '');
        payload.set('owner2_staff_id', owner2.staff_id || '');
        payload.set('owner2_purpose', document.getElementById('okr-owner2-purpose').value.trim());
        // With a single owner, the incentive rule doesn't apply (that owner
        // always gets 100%) and the field may be blank/disabled (e.g. Level 1
        // has no payout) - default to Rule 1, matching backend.php's own
        // single-owner fallback, instead of sending the blank placeholder.
        payload.set('incentive_rule', ownerState.length === 2 ? incentiveRuleSelect.value : '1');
        payload.set('incentivised_owner_staff_id', incentivisedOwnerId);
        payload.set('dept_scope', deptScope);
        payload.set('start_date', document.getElementById('okr-start').value);
        payload.set('end_date', document.getElementById('okr-end').value);

        fetch(CFG.apiUrl, { method: 'POST', body: payload })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    leaving = true;
                    window.location.href = 'okr/view.php?id=' + res.id;
                } else {
                    setError('okr-save', res.message || 'Failed to save OKR.');
                }
            })
            .catch(function () {
                setError('okr-save', 'Network error. Please try again.');
            });
    });
})();