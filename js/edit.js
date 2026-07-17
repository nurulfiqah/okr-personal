(function () {
    var CFG = window.OKR_EDIT_CONFIG || { staff: [], departments: [], levels: [] };
    var card = CFG.card || {};

    var referenceLinks = (CFG.referenceLinks || []).slice(); // { id, name, url }
    var attachments = (CFG.attachments || []).slice(); // { id, original_name, size }

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
    // Key Results rich text editor (same Quill setup as create.php).
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
    // dept_scope is saved as a single combined list (both owners' filters
    // merged, see create.js), so there's no way to know which ids belonged
    // to which owner - restore the same saved set to both boxes rather than
    // leaving the 2nd owner's filter silently blank.
    (CFG.deptScopeIds || []).forEach(function (id) {
        var opt = deptSelect.querySelector('option[value="' + id + '"]');
        if (opt) { opt.selected = true; }
        var opt2 = deptSelect2.querySelector('option[value="' + id + '"]');
        if (opt2) { opt2.selected = true; }
    });

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
        // The dept filter narrows future choices - it must never hide
        // whoever is already assigned, even if their own department isn't
        // in the filter (e.g. restoring a saved dept_scope on page load).
        if (previous && !eligible.some(function (s) { return String(s.id) === previous; })) {
            var current = CFG.staff.filter(function (s) { return String(s.id) === previous; });
            eligible = eligible.concat(current);
        }
        var placeholder = sel.options[0];
        sel.innerHTML = '';
        sel.appendChild(placeholder);
        fillSelect(sel, eligible, 'id', 'name');
        if (eligible.some(function (s) { return String(s.id) === previous; })) {
            sel.value = previous;
        }
    }

    function refreshOwnerOptions() {
        refreshOwnerOptionsFor(ownerSelect1, deptSelect, parseInt(ownerSelect2.value, 10) || 0);
        refreshOwnerOptionsFor(ownerSelect2, deptSelect2, parseInt(ownerSelect1.value, 10) || 0);
    }

    // Populate both owner selects with the full unfiltered staff list first
    // and select the card's saved owners there, so refreshOwnerOptions()
    // (which narrows by dept filter) has a real "previous" value to
    // preserve instead of the empty placeholder.
    fillSelect(ownerSelect1, CFG.staff, 'id', 'name');
    fillSelect(ownerSelect2, CFG.staff, 'id', 'name');
    ownerSelect1.value = card.owner_staff_id || '';
    ownerSelect2.value = card.owner2_staff_id || '';

    refreshOwnerOptions();
    ownerSelect1.value = card.owner_staff_id || '';
    ownerSelect2.value = card.owner2_staff_id || '';

    // The merged dept_scope is only a rough approximation of "what was
    // filtered when this card was created" - once an owner is actually
    // assigned, show their own real department(s) instead, which is
    // accurate and matches what view.php displays.
    function selectOwnDept(deptSel, staffId) {
        var staff = CFG.staff.filter(function (s) { return s.id === staffId; })[0];
        if (!staff || !staff.deptIds || !staff.deptIds.length) { return; }
        Array.prototype.forEach.call(deptSel.options, function (opt) { opt.selected = false; });
        staff.deptIds.forEach(function (id) {
            var opt = deptSel.querySelector('option[value="' + id + '"]');
            if (opt) { opt.selected = true; }
        });
    }
    if (card.owner_staff_id) { selectOwnDept(deptSelect, card.owner_staff_id); }
    if (card.owner2_staff_id) { selectOwnDept(deptSelect2, card.owner2_staff_id); }

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
        updateDisplay();
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

    ownerSelect1.addEventListener('change', function () {
        refreshOwnerOptionsFor(ownerSelect2, deptSelect2, parseInt(ownerSelect1.value, 10) || 0);
    });
    ownerSelect2.addEventListener('change', function () {
        refreshOwnerOptionsFor(ownerSelect1, deptSelect, parseInt(ownerSelect2.value, 10) || 0);
    });

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
            owner2Section.parentNode.insertBefore(incentiveRuleWrap, owner2Section.nextSibling);
        });
    }
    if (removeOwner2Btn) {
        removeOwner2Btn.addEventListener('click', function () {
            clearOwner2();
            owner2Section.style.display = 'none';
            owner2ToggleWrap.style.display = 'block';
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

    var incentiveRuleHadOwner2 = !!card.owner2_staff_id;

    function refreshIncentiveRuleVisibility() {
        var hasOwner1 = !!ownerSelect1.value;
        var hasOwner2 = !!ownerSelect2.value;
        var selectedLevel = (CFG.levels || []).filter(function (l) { return String(l.level) === levelSelect.value; })[0];
        var noPayout = !!(selectedLevel && Number(selectedLevel.base_rm) === 0);

        if (!hasOwner1 || noPayout) {
            incentiveRuleSelect.value = '';
            incentiveRuleSelect.disabled = true;
        } else if (!hasOwner2) {
            var rule1 = (CFG.incentiveRules || []).filter(function (r) { return r.code === 'RULE1'; })[0];
            if (rule1) { incentiveRuleSelect.value = String(rule1.id); }
            incentiveRuleSelect.disabled = true;
        } else {
            if (!incentiveRuleHadOwner2) {
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

    // Prefill from the card's current data before wiring change handlers.
    levelSelect.value = card.difficulty_level || '';
    if (card.owner2_staff_id) {
        owner2Section.style.display = 'block';
        owner2ToggleWrap.style.display = 'none';
        owner2Section.parentNode.insertBefore(incentiveRuleWrap, owner2Section.nextSibling);
    }
    owner2PurposeWrap.style.display = card.owner2_staff_id ? 'block' : 'none';
    incentiveRuleSelect.value = card.incentive_rule || '';
    incentivisedOwnerSelect.value = card.incentivised_owner_staff_id || '';
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

    function refreshLevelPreview() {
        var lv = CFG.levels.filter(function (l) { return String(l.level) === levelSelect.value; })[0];
        if (lv) {
            levelRubricEl.textContent = lv.rubric_text || '';
            levelRmEl.textContent = 'RM' + Number(lv.base_rm).toFixed(2);
        } else {
            levelRubricEl.textContent = 'Select a difficulty level to see its rubric and RM.';
            levelRmEl.textContent = 'RM0.00';
        }
        refreshIncentiveRuleVisibility();
    }
    refreshLevelPreview();
    levelSelect.addEventListener('change', refreshLevelPreview);

    // ---------------------------------------------------------------
    // Timeline: Start/End/Status + once-only Extended + auto Final Due Date.
    // ---------------------------------------------------------------
    var statusSelect = document.getElementById('okr-status');
    var incentiveTileEl = document.getElementById('okr-incentive-tile');
    var incentiveTileLabelEl = document.getElementById('okr-incentive-tile-label');
    var incentiveTileClassMap = {
        'Draft': 'okr-incentive-tile--blue',
        'Active': 'okr-incentive-tile--blue',
        'Extend': 'okr-incentive-tile--yellow',
        'Fail': 'okr-incentive-tile--red',
        'Suspended': 'okr-incentive-tile--red'
    };

    function refreshIncentiveTileStatus() {
        var status = statusSelect.value;
        var paid = (status === 'Complete' || status === 'Complete with Excellence');
        incentiveTileEl.classList.remove('okr-incentive-tile--blue', 'okr-incentive-tile--yellow', 'okr-incentive-tile--red');
        var cls = incentiveTileClassMap[status];
        if (cls) { incentiveTileEl.classList.add(cls); }
        incentiveTileLabelEl.textContent = paid ? 'Total Incentive' : 'Estimated Incentive';
    }
    refreshIncentiveTileStatus();
    statusSelect.addEventListener('change', refreshIncentiveTileStatus);

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
    // admin's Backdate toggle is on - okr/admin/index.php); Extended Date
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
    });

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

        if (!statusSelect.value) { setError('okr-status', 'Select a status.'); ok = false; }

        if (statusSelect.value === 'Extend' && !extendedCheckbox.checked) {
            setError('okr-status', 'Tick "Extended?" and set an Extended Date when Status is Extend.');
            ok = false;
        }

        if (extendedCheckbox.checked && !extendedDateInput.value) {
            setError('okr-status', 'Extended Date is required when Extended is checked.');
            ok = false;
        }

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

    function submitSave() {
        var deptScopeIds = Array.prototype.map.call(deptSelect.selectedOptions, function (o) { return o.value; })
            .concat(Array.prototype.map.call(deptSelect2.selectedOptions, function (o) { return o.value; }));
        var deptScope = deptScopeIds.filter(function (v, i) { return deptScopeIds.indexOf(v) === i; }).join(',');

        var payload = new URLSearchParams();
        payload.set('action', 'updateCard');
        payload.set('id', card.id);
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
        payload.set('result_status', statusSelect.value);
        payload.set('extended', extendedCheckbox.checked ? '1' : '');
        payload.set('extended_date', extendedDateInput.value);
        payload.set('remarks', document.getElementById('okr-remarks').value.trim());

        fetch(CFG.apiUrl, { method: 'POST', body: payload })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
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
})();
