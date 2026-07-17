(function () {
    var CFG = window.OKR_CONFIG || { staff: [], departments: [], levels: [] };

    var referenceLinks = []; // { token, name, url }
    var stagedFiles = []; // { token, name, size }

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

    var ownerSelect1 = document.getElementById('okr-owner1');
    var ownerSelect2 = document.getElementById('okr-owner2');
    var deptSelect = document.getElementById('okr-dept-scope');
    var deptSelect2 = document.getElementById('okr-dept-scope-2');
    var levelSelect = document.getElementById('okr-level');
    var incentiveRuleSelect = document.getElementById('okr-incentive-rule');
    var incentivisedOwnerSelect = document.getElementById('okr-incentivised-owner');

    fillSelect(deptSelect, CFG.departments, 'id', 'name');
    fillSelect(deptSelect2, CFG.departments, 'id', 'name');

    // ---------------------------------------------------------------
    // Each owner has its own Department filter: pick department(s) there
    // and only staff in those departments show up in that owner's dropdown.
    // No department selected = everyone is eligible. Populated immediately
    // (before any other feature wiring below) so a failure elsewhere on the
    // page can never leave these selects empty.
    // ---------------------------------------------------------------
    function refreshOwnerOptionsFor(sel, deptSel, excludeId) {
        var selectedDeptIds = Array.prototype.map.call(deptSel.selectedOptions, function (o) { return parseInt(o.value, 10); });
        var eligible = selectedDeptIds.length === 0
            ? CFG.staff
            : CFG.staff.filter(function (s) {
                return (s.deptIds || []).some(function (d) { return selectedDeptIds.indexOf(d) !== -1; });
            });
        if (excludeId) {
            eligible = eligible.filter(function (s) { return s.id !== excludeId; });
        }

        var previous = sel.value;
        var placeholder = sel.options[0];
        sel.innerHTML = '';
        sel.appendChild(placeholder);
        fillSelect(sel, eligible, 'id', 'name');
        if (eligible.some(function (s) { return String(s.id) === previous; })) {
            sel.value = previous;
        }
    }

    // Owner and 2nd Owner can never be the same person: each dropdown
    // excludes whoever is currently selected in the other one.
    function refreshOwnerOptions() {
        refreshOwnerOptionsFor(ownerSelect1, deptSelect, parseInt(ownerSelect2.value, 10) || 0);
        refreshOwnerOptionsFor(ownerSelect2, deptSelect2, parseInt(ownerSelect1.value, 10) || 0);
    }

    refreshOwnerOptions();

    // Wires a department multi-select + its search box: typing filters the
    // visible options, and the box shows the selected department names when
    // not focused (clearing back to a live search on focus).
    function wireDeptFilter(deptSel, searchInput, onChange) {
        if (!searchInput) { return; }
        var showingSelection = false;

        searchInput.addEventListener('keyup', function () {
            showingSelection = false;
            var term = searchInput.value.toLowerCase();
            var opts = deptSel.options;
            for (var i = 0; i < opts.length; i++) {
                opts[i].hidden = opts[i].textContent.toLowerCase().indexOf(term) < 0;
            }
        });

        searchInput.addEventListener('focus', function () {
            if (showingSelection) {
                searchInput.value = '';
                showingSelection = false;
                var opts = deptSel.options;
                for (var i = 0; i < opts.length; i++) { opts[i].hidden = false; }
            }
        });

        function updateDisplay() {
            var names = Array.prototype.map.call(deptSel.selectedOptions, function (o) { return o.textContent; });
            if (document.activeElement !== searchInput) {
                searchInput.value = names.join(', ');
                showingSelection = names.length > 0;
            }
        }

        deptSel.addEventListener('change', function () {
            updateDisplay();
            if (onChange) { onChange(); }
        });
    }

    wireDeptFilter(deptSelect, document.getElementById('okr-dept-scope-search'), function () {
        refreshOwnerOptionsFor(ownerSelect1, deptSelect, parseInt(ownerSelect2.value, 10) || 0);
        refreshIncentiveRuleVisibility();
    });
    wireDeptFilter(deptSelect2, document.getElementById('okr-dept-scope-2-search'), function () {
        refreshOwnerOptionsFor(ownerSelect2, deptSelect2, parseInt(ownerSelect1.value, 10) || 0);
        owner2PurposeWrap.style.display = ownerSelect2.value ? 'block' : 'none';
        refreshIncentiveRuleVisibility();
    });

    // Re-exclude the other owner whenever either selection changes.
    ownerSelect1.addEventListener('change', function () {
        refreshOwnerOptionsFor(ownerSelect2, deptSelect2, parseInt(ownerSelect1.value, 10) || 0);
    });
    ownerSelect2.addEventListener('change', function () {
        refreshOwnerOptionsFor(ownerSelect1, deptSelect, parseInt(ownerSelect2.value, 10) || 0);
    });

    // ---------------------------------------------------------------
    // 2nd Owner is opt-in: hidden behind an "Add 2nd Owner" toggle. Situation
    // 1 (single owner) never shows the Incentive Rule picker at all — the
    // backend defaults it to Rule 1 (100% to that one owner). Situation 2
    // (2nd owner added) reveals its Department/Owner/Purpose block, and the
    // Incentive Rule picker becomes relevant (wired further below).
    // ---------------------------------------------------------------
    var owner2ToggleWrap = document.getElementById('okr-owner2-toggle-wrap');
    var owner2Section = document.getElementById('okr-owner2-section');
    var addOwner2Btn = document.getElementById('okr-add-owner2-btn');
    var removeOwner2Btn = document.getElementById('okr-remove-owner2-btn');

    function clearOwner2() {
        ownerSelect2.value = '';
        Array.prototype.forEach.call(deptSelect2.options, function (opt) { opt.selected = false; });
        document.getElementById('okr-owner2-purpose').value = '';
        deptSelect2.dispatchEvent(new Event('change'));
        ownerSelect2.dispatchEvent(new Event('change'));
    }

    if (addOwner2Btn) {
        addOwner2Btn.addEventListener('click', function () {
            owner2Section.style.display = 'block';
            owner2ToggleWrap.style.display = 'none';
            // Incentive Rule becomes relevant only once both owners are in
            // place, so move it to the end of the section (after the 2nd
            // owner block) instead of leaving it sitting above it.
            owner2Section.parentNode.insertBefore(incentiveRuleWrap, owner2Section.nextSibling);
        });
    }
    if (removeOwner2Btn) {
        removeOwner2Btn.addEventListener('click', function () {
            clearOwner2();
            owner2Section.style.display = 'none';
            owner2ToggleWrap.style.display = 'block';
            // Move Incentive Rule back above the "Add 2nd Owner" button.
            owner2ToggleWrap.parentNode.insertBefore(incentiveRuleWrap, owner2ToggleWrap);
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
    // Two-owner incentive rule (framework doc §10): only relevant once a
    // 2nd owner is picked. Rule 1 needs a single incentivised owner chosen
    // out of the two; Rule 2 splits evenly, no picker needed.
    // ---------------------------------------------------------------
    var owner2PurposeWrap = document.getElementById('okr-owner2-purpose-wrap');
    var incentiveRuleWrap = document.getElementById('okr-incentive-rule-wrap');
    var incentivisedOwnerWrap = document.getElementById('okr-incentivised-owner-wrap');
    var incentiveRuleHint = document.getElementById('okr-incentive-rule-hint');

    function refreshIncentivisedOwnerOptions() {
        var currentValue = incentivisedOwnerSelect.value;
        incentivisedOwnerSelect.innerHTML = '<option value="">Select owner</option>';
        [ownerSelect1, ownerSelect2].forEach(function (sel) {
            if (!sel.value) { return; }
            var label = sel.options[sel.selectedIndex].textContent;
            var opt = document.createElement('option');
            opt.value = sel.value;
            opt.textContent = label;
            incentivisedOwnerSelect.appendChild(opt);
        });
        incentivisedOwnerSelect.value = currentValue;
    }

    var incentiveRuleHadOwner2 = false;

    function refreshIncentiveRuleVisibility() {
        var hasOwner1 = !!ownerSelect1.value;
        var hasOwner2 = !!ownerSelect2.value;
        var selectedLevel = (CFG.levels || []).filter(function (l) { return String(l.level) === levelSelect.value; })[0];
        var noPayout = !!(selectedLevel && Number(selectedLevel.base_rm) === 0);

        if (!hasOwner1 || noPayout) {
            // No owner picked yet, or Level 1 (RM0, no incentive at all):
            // leave the field on its "Select rule" placeholder and locked,
            // there's nothing to choose or pay out.
            incentiveRuleSelect.value = '';
            incentiveRuleSelect.disabled = true;
        } else if (!hasOwner2) {
            // Single owner: no choice to make, lock the field to Rule 1 (100%
            // to that one owner) instead of hiding it.
            var rule1 = (CFG.incentiveRules || []).filter(function (r) { return r.code === 'RULE1'; })[0];
            if (rule1) { incentiveRuleSelect.value = String(rule1.id); }
            incentiveRuleSelect.disabled = true;
        } else {
            if (!incentiveRuleHadOwner2) {
                // Just gained a 2nd owner: reset to the placeholder so the
                // issuer must explicitly pick Rule 1 or Rule 2, instead of
                // silently keeping whatever Rule 1 auto-filled for one owner.
                incentiveRuleSelect.value = '';
            }
            incentiveRuleSelect.disabled = false;
        }
        incentiveRuleHadOwner2 = hasOwner2;

        var rule = (CFG.incentiveRules || []).filter(function (r) { return String(r.id) === incentiveRuleSelect.value; })[0];
        incentiveRuleHint.textContent = rule ? rule.payout_logic : '';

        var showPicker = !!(hasOwner2 && rule && rule.code === 'RULE1');
        incentivisedOwnerWrap.style.display = showPicker ? 'block' : 'none';
        if (showPicker) { refreshIncentivisedOwnerOptions(); }

        // Full width on its own; shrinks to sit side-by-side with
        // Incentivised Owner once that field appears.
        incentiveRuleWrap.classList.toggle('col-12', !showPicker);
        incentiveRuleWrap.classList.toggle('col-md-6', showPicker);

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
        var hasOwner1 = !!ownerSelect1.value;
        var hasOwner2 = !!ownerSelect2.value;
        var selectedLevel = (CFG.levels || []).filter(function (l) { return String(l.level) === levelSelect.value; })[0];
        var baseRm = selectedLevel ? Number(selectedLevel.base_rm) : 0;

        if (!hasOwner1 || baseRm <= 0) {
            breakdownEl.style.display = 'none';
            return;
        }

        stat1LabelEl.textContent = '1st Owner · ' + ownerSelect1.options[ownerSelect1.selectedIndex].textContent;

        if (!hasOwner2) {
            stat1El.classList.add('okr-incentive-stat--full');
            stat1ValueEl.textContent = 'RM' + baseRm.toFixed(2);
            stat2El.style.display = 'none';
        } else {
            stat1El.classList.remove('okr-incentive-stat--full');
            stat2El.style.display = 'block';
            stat2LabelEl.textContent = '2nd Owner · ' + ownerSelect2.options[ownerSelect2.selectedIndex].textContent;

            var rule = (CFG.incentiveRules || []).filter(function (r) { return String(r.id) === incentiveRuleSelect.value; })[0];
            if (rule && rule.code === 'RULE2') {
                stat1ValueEl.textContent = 'RM' + (baseRm / 2).toFixed(2);
                stat2ValueEl.textContent = 'RM' + (baseRm / 2).toFixed(2);
            } else if (rule && rule.code === 'RULE1') {
                var incentivisedId = incentivisedOwnerSelect.value;
                stat1ValueEl.textContent = incentivisedId === ownerSelect1.value ? 'RM' + baseRm.toFixed(2) : 'RM0.00';
                stat2ValueEl.textContent = incentivisedId === ownerSelect2.value ? 'RM' + baseRm.toFixed(2) : 'RM0.00';
            } else {
                stat1ValueEl.textContent = 'RM0.00';
                stat2ValueEl.textContent = 'RM0.00';
            }
        }
        breakdownEl.style.display = 'grid';
    }

    refreshIncentiveRuleVisibility();

    ownerSelect2.addEventListener('change', function () {
        owner2PurposeWrap.style.display = ownerSelect2.value ? 'block' : 'none';
        refreshIncentiveRuleVisibility();
    });
    ownerSelect1.addEventListener('change', refreshIncentiveRuleVisibility);
    incentiveRuleSelect.addEventListener('change', refreshIncentiveRuleVisibility);
    incentivisedOwnerSelect.addEventListener('change', refreshIncentiveBreakdown);

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

        var owner1 = ownerSelect1.value;
        if (!owner1) { setError('okr-owner1', 'An owner is required.'); ok = false; }

        var owner2 = ownerSelect2.value;
        if (owner2 && owner1 && owner2 === owner1) {
            setError('okr-owner1', 'Owner and 2nd Owner must be different people.');
            ok = false;
        }

        var owner2Purpose = document.getElementById('okr-owner2-purpose').value.trim();
        if (owner2 && !owner2Purpose) {
            setError('okr-owner2-purpose', 'State the purpose for a second (jointly-run) owner.');
            ok = false;
        }

        if (owner2) {
            var rule = (CFG.incentiveRules || []).filter(function (r) { return String(r.id) === incentiveRuleSelect.value; })[0];
            if (!rule) {
                setError('okr-incentive-rule', 'Select an incentive rule.');
                ok = false;
            } else if (rule.code === 'RULE1' && !incentivisedOwnerSelect.value) {
                setError('okr-incentivised-owner', 'Select which owner receives the incentive.');
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

        var deptScopeIds = Array.prototype.map.call(deptSelect.selectedOptions, function (o) { return o.value; })
            .concat(Array.prototype.map.call(deptSelect2.selectedOptions, function (o) { return o.value; }));
        var deptScope = deptScopeIds.filter(function (v, i) { return deptScopeIds.indexOf(v) === i; }).join(',');

        var payload = new URLSearchParams();
        payload.set('action', 'createCard');
        payload.set('objective', document.getElementById('okr-objective').value.trim());
        payload.set('key_results', keyResultsHtml());
        payload.set('okr_type', document.getElementById('okr-type').value);
        payload.set('difficulty_level', levelSelect.value);
        payload.set('owner_staff_id', ownerSelect1.value);
        payload.set('owner2_staff_id', ownerSelect2.value);
        payload.set('owner2_purpose', document.getElementById('okr-owner2-purpose').value.trim());
        payload.set('incentive_rule', incentiveRuleSelect.value);
        payload.set('incentivised_owner_staff_id', incentivisedOwnerSelect.value);
        payload.set('dept_scope', deptScope);
        payload.set('start_date', document.getElementById('okr-start').value);
        payload.set('end_date', document.getElementById('okr-end').value);

        fetch(CFG.apiUrl, { method: 'POST', body: payload })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
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