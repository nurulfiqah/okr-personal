(function () {
    var CFG = window.OKR_PERFORMANCE || { departments: [], apiUrl: 'okr/backend.php', exportUrl: 'okr/export_performance.php' };

    var PILL_CLASS = {
        'Active': 'okr-pill-active',
        'Complete': 'okr-pill-complete',
        'Complete with Excellence': 'okr-pill-complete-excellence',
        'Extend': 'okr-pill-extend',
        'Suspended': 'okr-pill-suspended',
        'Fail': 'okr-pill-fail',
    };

    function pillClass(status) {
        return PILL_CLASS[status] || 'okr-pill-active';
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function money(n) {
        return 'RM' + Number(n || 0).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    var yearSelect    = document.getElementById('perf-filter-year');
    var monthSelect   = document.getElementById('perf-filter-month');
    var quarterSelect = document.getElementById('perf-filter-quarter');
    var deptSelect    = document.getElementById('perf-filter-dept');
    var gradeSelect   = document.getElementById('perf-filter-grade');
    var structSelect  = document.getElementById('perf-filter-struct');
    var closureFromInput = document.getElementById('perf-filter-closure-from');
    var closureToInput   = document.getElementById('perf-filter-closure-to');
    var applyBtn      = document.getElementById('perf-apply-filter');
    var resetBtn      = document.getElementById('perf-reset-filter');
    var exportBtn     = document.getElementById('perf-export-btn');
    var tableBody     = document.getElementById('perf-table-body');
    var exportProgressWrap = document.getElementById('perf-export-progress-wrap');
    var exportProgressMsg  = document.getElementById('perf-export-progress-msg');

    // Same progress-bar/cookie-polling pattern as ATEM's staff_performance
    // export (see atem/staff_performance/index.php's triggerExport()): the
    // CSV download itself sets a dl_token cookie once streaming starts, and
    // this page polls for it so the user sees when the download is ready.
    function triggerExport(url) {
        var token = Date.now().toString(36) + Math.random().toString(36).substr(2, 4);
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        if (exportProgressWrap) { exportProgressWrap.style.display = 'block'; }
        if (exportProgressMsg) { exportProgressMsg.textContent = 'Preparing export...'; }

        window.location.href = url + sep + 'dl_token=' + token;

        var cookieName = 'export_done_' + token;
        var pollTimer = setInterval(function () {
            if (document.cookie.indexOf(cookieName) >= 0) {
                clearInterval(pollTimer);
                document.cookie = cookieName + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
                if (exportProgressMsg) { exportProgressMsg.textContent = 'Done.'; }
                setTimeout(function () { if (exportProgressWrap) { exportProgressWrap.style.display = 'none'; } }, 1500);
            }
        }, 500);
        setTimeout(function () {
            clearInterval(pollTimer);
            if (exportProgressWrap) { exportProgressWrap.style.display = 'none'; }
        }, 60000);
    }

    monthSelect.addEventListener('change', function () {
        if (monthSelect.value) { quarterSelect.value = ''; }
    });
    quarterSelect.addEventListener('change', function () {
        if (quarterSelect.value) { monthSelect.value = ''; }
    });

    function currentFilters() {
        return {
            filter_year: yearSelect.value,
            filter_month: monthSelect.value,
            filter_quarter: quarterSelect.value,
            filter_dept_id: deptSelect.value,
            filter_grade: gradeSelect.value,
            filter_struct: structSelect.value,
            filter_closure_from: closureFromInput.value,
            filter_closure_to: closureToInput.value,
        };
    }

    function buildQuery(params) {
        var qs = new URLSearchParams();
        Object.keys(params).forEach(function (k) {
            if (params[k] !== '' && params[k] != null) { qs.set(k, params[k]); }
        });
        return qs.toString();
    }

    var staffRows = [];
    var perfPage = 1;
    var perfPerPage = 25;
    var pagerEl = document.getElementById('perf-pager');

    // Selection state persists across pages/filter reloads (keyed by
    // staff_id) so checking rows on page 1 then moving to page 2 doesn't lose
    // the selection - only the checkboxes actually in the DOM get rebuilt.
    var selectedStaffIds = {};
    var selectAllEl = document.getElementById('perf-select-all');
    var exportSelectedBtn = document.getElementById('perf-export-selected-btn');
    var exportLockSelectedBtn = document.getElementById('perf-export-lock-selected-btn');
    var lockSelectedBtn = document.getElementById('perf-lock-selected-btn');
    var unlockSelectedBtn = document.getElementById('perf-unlock-selected-btn');

    function selectedIdsArray() {
        return Object.keys(selectedStaffIds).filter(function (k) { return selectedStaffIds[k]; });
    }

    function updateSelectAllCheckbox() {
        if (!selectAllEl) { return; }
        var boxes = tableBody.querySelectorAll('.perf-row-cb');
        var checked = tableBody.querySelectorAll('.perf-row-cb:checked');
        selectAllEl.checked = boxes.length > 0 && checked.length === boxes.length;
    }

    function updateSelectedButtons() {
        var hasSelection = selectedIdsArray().length > 0;
        if (exportSelectedBtn) {
            exportSelectedBtn.style.pointerEvents = hasSelection ? '' : 'none';
            exportSelectedBtn.style.opacity = hasSelection ? '' : '0.5';
            if (hasSelection) {
                var qs = buildQuery(Object.assign({ type: 'performance', staff_ids: selectedIdsArray().join(',') }, currentFilters()));
                exportSelectedBtn.href = CFG.exportUrl + '?' + qs;
            }
        }
        if (exportLockSelectedBtn) {
            exportLockSelectedBtn.style.pointerEvents = hasSelection ? '' : 'none';
            exportLockSelectedBtn.style.opacity = hasSelection ? '' : '0.5';
            if (hasSelection) {
                var lockQs = buildQuery(Object.assign({ type: 'performance', lock: '1', staff_ids: selectedIdsArray().join(',') }, currentFilters()));
                exportLockSelectedBtn.href = CFG.exportUrl + '?' + lockQs;
            }
        }
        if (lockSelectedBtn) { lockSelectedBtn.disabled = !hasSelection; }
        if (unlockSelectedBtn) { unlockSelectedBtn.disabled = !hasSelection; }
    }

    if (selectAllEl) {
        selectAllEl.addEventListener('change', function () {
            var boxes = tableBody.querySelectorAll('.perf-row-cb');
            boxes.forEach(function (cb) {
                cb.checked = selectAllEl.checked;
                if (selectAllEl.checked) { selectedStaffIds[cb.value] = true; }
                else { delete selectedStaffIds[cb.value]; }
            });
            updateSelectedButtons();
        });
    }

    if (exportSelectedBtn) {
        exportSelectedBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (selectedIdsArray().length === 0) { return; }
            triggerExport(this.href);
        });
    }

    if (exportLockSelectedBtn) {
        exportLockSelectedBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (selectedIdsArray().length === 0) { return; }
            if (!window.confirm('This will download the CSV and lock every Complete/Complete with Excellence OKR for the selected staff. Continue?')) { return; }
            triggerExport(this.href);
        });
    }

    function pageBtn(p, label, disabled, active) {
        return '<li class="page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '') + '">' +
            '<a class="page-link" href="#" data-page="' + p + '" style="font-size:12px;">' + label + '</a></li>';
    }

    function renderPager(total) {
        if (!pagerEl) { return; }
        if (total === 0) {
            pagerEl.innerHTML = '';
            return;
        }
        var pages = Math.max(1, Math.ceil(total / perfPerPage));
        var startIdx = (perfPage - 1) * perfPerPage;
        var shown = Math.min(perfPerPage, total - startIdx);

        var info = '<span class="text-muted">Showing ' + (startIdx + 1) + ' to ' + (startIdx + shown) + ' of ' + total + ' entries</span>';

        var btns = '<ul class="pagination pagination-sm mb-0">';
        btns += pageBtn(perfPage - 1, 'Previous', perfPage <= 1, false);
        for (var p = 1; p <= pages; p++) {
            btns += pageBtn(p, String(p), false, p === perfPage);
        }
        btns += pageBtn(perfPage + 1, 'Next', perfPage >= pages, false);
        btns += '</ul>';

        pagerEl.innerHTML = info + btns;
    }

    function actionButtonsHtml(r) {
        var exportQs = buildQuery(Object.assign({ type: 'staff-okr', staff_id: r.staff_id }, currentFilters()));
        var lockBtnHtml = CFG.canLockPayout
            ? '<button type="button" class="btn btn-outline-danger okr-tbl-btn ms-1 perf-lock-row-btn" data-staff-id="' + r.staff_id + '"' +
              (r.is_locked ? ' disabled' : '') + '>' + (r.is_locked ? 'Locked' : 'Lock') + '</button>'
            : '';
        var unlockBtnHtml = CFG.canLockPayout
            ? '<button type="button" class="btn btn-outline-warning okr-tbl-btn ms-1 perf-unlock-row-btn" data-staff-id="' + r.staff_id + '"' +
              (r.is_locked ? '' : ' disabled') + '>Unlock</button>'
            : '';
        return '<div style="white-space:nowrap;">' +
            '<button type="button" class="btn btn-outline-secondary okr-tbl-btn me-1 perf-view-btn" data-staff-id="' + r.staff_id + '">View</button>' +
            '<a class="btn btn-outline-success okr-tbl-btn perf-export-row-btn" href="' + CFG.exportUrl + '?' + exportQs + '">Export</a>' +
            lockBtnHtml +
            unlockBtnHtml +
            '</div>';
    }

    function renderTable() {
        if (staffRows.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="12" class="text-muted" style="font-size:12px;text-align:left;">No data for this filter.</td></tr>';
            renderPager(0);
            return;
        }

        var pages = Math.max(1, Math.ceil(staffRows.length / perfPerPage));
        if (perfPage > pages) { perfPage = pages; }
        var startIdx = (perfPage - 1) * perfPerPage;
        var pageRows = staffRows.slice(startIdx, startIdx + perfPerPage);

        tableBody.innerHTML = '';
        pageRows.forEach(function (r) {
            var tr = document.createElement('tr');
            if (r.is_locked) { tr.classList.add('okr-row-locked'); }
            tr.innerHTML =
                '<td><input type="checkbox" class="perf-row-cb" value="' + r.staff_id + '"' +
                (selectedStaffIds[r.staff_id] ? ' checked' : '') + '></td>' +
                '<td style="font-size:12px;text-align:left;">' + escapeHtml(r.name) + '</td>' +
                '<td style="font-size:12px;text-align:left;">' + escapeHtml(r.department) + '</td>' +
                '<td style="font-size:12px;text-align:left;">' + escapeHtml(r.grade) + '</td>' +
                '<td style="font-size:12px;text-align:left;">' + escapeHtml(r.struct) + '</td>' +
                '<td style="font-size:12px;text-align:center;">' + r.total + '</td>' +
                '<td style="font-size:12px;text-align:center;">' + r.complete_total + '</td>' +
                '<td style="font-size:12px;text-align:center;">' + r.active + '</td>' +
                '<td style="font-size:12px;text-align:center;">' + r.extend + '</td>' +
                '<td style="font-size:12px;text-align:center;">' + r.fail + '</td>' +
                '<td style="font-size:12px;text-align:center;">' + money(r.forecast_rm) + '</td>' +
                '<td style="font-size:12px;text-align:left;">' + actionButtonsHtml(r) + '</td>';
            tr.querySelector('.perf-row-cb').addEventListener('change', function () {
                if (this.checked) { selectedStaffIds[r.staff_id] = true; }
                else { delete selectedStaffIds[r.staff_id]; }
                updateSelectAllCheckbox();
                updateSelectedButtons();
            });
            tr.querySelector('.perf-view-btn').addEventListener('click', function () { openStaffModal(r); });
            tr.querySelector('.perf-export-row-btn').addEventListener('click', function (e) {
                e.preventDefault();
                triggerExport(this.href);
            });
            var lockRowBtn = tr.querySelector('.perf-lock-row-btn');
            if (lockRowBtn) {
                lockRowBtn.addEventListener('click', function () { openLockForStaff(r); });
            }
            var unlockRowBtn = tr.querySelector('.perf-unlock-row-btn');
            if (unlockRowBtn) {
                unlockRowBtn.addEventListener('click', function () { openUnlockForStaff(r); });
            }
            tableBody.appendChild(tr);
        });

        renderPager(staffRows.length);
        updateSelectAllCheckbox();
        updateSelectedButtons();
    }

    if (pagerEl) {
        pagerEl.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('.page-link') : null;
            if (!a || a.parentNode.classList.contains('disabled')) { return; }
            e.preventDefault();
            var p = parseInt(a.getAttribute('data-page'), 10);
            if (p >= 1) {
                perfPage = p;
                renderTable();
            }
        });
    }

    function loadTable() {
        tableBody.innerHTML = '<tr><td colspan="12" class="text-muted" style="font-size:12px;text-align:left;">Loading...</td></tr>';
        var qs = buildQuery(Object.assign({ action: 'staffPerformanceList' }, currentFilters()));
        fetch(CFG.apiUrl + '?' + qs)
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    staffRows = res.data;
                    perfPage = 1;
                    renderTable();
                } else {
                    tableBody.innerHTML = '<tr><td colspan="12" class="text-muted" style="font-size:12px;text-align:left;">' + escapeHtml(res.error || res.message || 'Failed to load.') + '</td></tr>';
                }
            })
            .catch(function () {
                tableBody.innerHTML = '<tr><td colspan="12" class="text-muted" style="font-size:12px;text-align:left;">Network error.</td></tr>';
            });
    }

    var staffModalEl = document.getElementById('okr-staff-modal');
    var staffModal = staffModalEl ? new bootstrap.Modal(staffModalEl) : null;
    var staffModalTitle = document.getElementById('okr-staff-modal-title');
    var staffModalBody = document.getElementById('okr-staff-modal-body');
    var staffModalExportBtn = document.getElementById('okr-staff-modal-export');

    function openStaffModal(staffRow) {
        staffModalTitle.textContent = staffRow.name + "'s OKR Cards";
        staffModalBody.innerHTML = '<tr><td colspan="8" class="text-muted" style="font-size:12px;text-align:left;">Loading...</td></tr>';
        if (staffModalExportBtn) {
            var exportQs = buildQuery(Object.assign({ type: 'staff-okr', staff_id: staffRow.staff_id }, currentFilters()));
            staffModalExportBtn.href = CFG.exportUrl + '?' + exportQs;
        }
        if (staffModal) { staffModal.show(); }

        var qs = buildQuery(Object.assign({ action: 'staffOkrList', staff_id: staffRow.staff_id }, currentFilters()));
        fetch(CFG.apiUrl + '?' + qs)
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) {
                    staffModalBody.innerHTML = '<tr><td colspan="8" class="text-muted" style="font-size:12px;text-align:left;">' + escapeHtml(res.message || 'Failed to load.') + '</td></tr>';
                    return;
                }
                if (res.data.length === 0) {
                    staffModalBody.innerHTML = '<tr><td colspan="8" class="text-muted" style="font-size:12px;text-align:left;">No cards for this filter.</td></tr>';
                    return;
                }
                staffModalBody.innerHTML = '';
                res.data.forEach(function (c) {
                    var tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td style="font-size:12px;text-align:left;">OKR' + c.id + '</td>' +
                        '<td style="font-size:12px;text-align:left;"><a href="okr/view.php?id=' + c.id + '" target="_blank">' + escapeHtml(c.objective) + '</a></td>' +
                        '<td style="font-size:12px;text-align:left;">' + escapeHtml(c.okr_type) + '</td>' +
                        '<td style="font-size:12px;text-align:left;">' + escapeHtml(c.level_label) + '</td>' +
                        '<td style="font-size:12px;text-align:left;">' + escapeHtml(c.start_date) + ' &rarr; ' + escapeHtml(c.end_date) + '</td>' +
                        '<td style="font-size:12px;text-align:left;"><span class="okr-pill ' + pillClass(c.result_status) + '">' + escapeHtml(c.result_status) + '</span></td>' +
                        '<td style="font-size:12px;text-align:left;">' + escapeHtml(c.role) + '</td>' +
                        '<td style="font-size:12px;text-align:left;">' + money(c.rm_share) + '</td>';
                    staffModalBody.appendChild(tr);
                });
            })
            .catch(function () {
                staffModalBody.innerHTML = '<tr><td colspan="8" class="text-muted" style="font-size:12px;text-align:left;">Network error.</td></tr>';
            });
    }

    var exportLockBtn = document.getElementById('perf-export-lock-btn');

    function updateExportLink() {
        var qs = buildQuery(Object.assign({ type: 'performance' }, currentFilters()));
        exportBtn.href = CFG.exportUrl + '?' + qs;
        if (exportLockBtn) {
            var lockQs = buildQuery(Object.assign({ type: 'performance', lock: '1' }, currentFilters()));
            exportLockBtn.href = CFG.exportUrl + '?' + lockQs;
        }
    }

    applyBtn.addEventListener('click', function () {
        loadTable();
        updateExportLink();
    });

    resetBtn.addEventListener('click', function () {
        yearSelect.value = '2026';
        monthSelect.value = '';
        quarterSelect.value = '';
        deptSelect.value = '';
        gradeSelect.value = '';
        structSelect.value = '';
        closureFromInput.value = '';
        closureToInput.value = '';
        loadTable();
        updateExportLink();
    });

    exportBtn.addEventListener('click', function (e) {
        e.preventDefault();
        triggerExport(this.href);
    });

    if (exportLockBtn) {
        exportLockBtn.addEventListener('click', function (e) {
            e.preventDefault();
            if (!window.confirm('This will download the CSV and lock every Complete/Complete with Excellence OKR matching the current filter. Continue?')) { return; }
            triggerExport(this.href);
        });
    }

    if (staffModalExportBtn) {
        staffModalExportBtn.addEventListener('click', function (e) {
            e.preventDefault();
            triggerExport(this.href);
        });
    }

    // Lock/Undo Lock Payout: toggles incentive_locked on every matching card
    // in the current filter (backend.php's lockPayoutCards /
    // unlockPayoutCards), restricted server-side to People Management/admin -
    // the buttons only render for them at all. Both follow the same
    // trigger-button(s) -> confirm-modal -> POST -> refresh flow, so share one
    // helper. Lock has two triggers (the bulk button, and each row's "Lock"
    // button scoped to one staff_id), so the helper exposes open(extraParams,
    // bodyHtml) for callers that need to set the scope before showing.
    function wireLockAction(opts) {
        var modalEl = document.getElementById(opts.modalId);
        var modal = modalEl ? new bootstrap.Modal(modalEl) : null;
        var confirmBtn = document.getElementById(opts.confirmBtnId);
        var errorEl = document.getElementById(opts.errorId);
        var bodyEl = modalEl ? modalEl.querySelector('.modal-body p:first-child') : null;
        var defaultBodyHtml = bodyEl ? bodyEl.innerHTML : '';
        var remarkEl = opts.remarkId ? document.getElementById(opts.remarkId) : null;
        var pendingExtra = {};

        function open(extraParams, bodyHtml) {
            if (!modal) { return; }
            pendingExtra = extraParams || {};
            if (bodyEl) { bodyEl.innerHTML = bodyHtml || defaultBodyHtml; }
            if (errorEl) { errorEl.textContent = ''; }
            if (remarkEl) { remarkEl.value = ''; }
            modal.show();
        }

        var btn = document.getElementById(opts.btnId);
        if (btn) {
            btn.addEventListener('click', function () { open({}); });
        }

        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                if (errorEl) { errorEl.textContent = ''; }
                confirmBtn.disabled = true;

                var payload = new URLSearchParams();
                payload.set('action', opts.action);
                if (remarkEl && remarkEl.value.trim() !== '') { payload.set('remark', remarkEl.value.trim()); }
                var filters = Object.assign({}, currentFilters(), pendingExtra);
                Object.keys(filters).forEach(function (k) {
                    if (filters[k] !== '' && filters[k] != null) { payload.set(k, filters[k]); }
                });

                fetch(CFG.apiUrl, { method: 'POST', body: payload })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        confirmBtn.disabled = false;
                        if (!res.success) {
                            if (errorEl) { errorEl.textContent = res.message || opts.failMsg; }
                            return;
                        }
                        modal.hide();
                        loadTable();
                        window.alert(opts.doneLabel + ' ' + res[opts.countKey] + ' OKR card(s).');
                    })
                    .catch(function () {
                        confirmBtn.disabled = false;
                        if (errorEl) { errorEl.textContent = 'Network error. Please try again.'; }
                    });
            });
        }

        return { open: open };
    }

    var lockController = wireLockAction({
        btnId: 'perf-lock-btn', modalId: 'okr-lock-modal', confirmBtnId: 'okr-lock-confirm-btn',
        errorId: 'okr-lock-error', action: 'lockPayoutCards', countKey: 'locked_count',
        doneLabel: 'Locked', failMsg: 'Failed to lock payout.', remarkId: 'okr-lock-remark',
    });
    var unlockController = wireLockAction({
        btnId: 'perf-unlock-btn', modalId: 'okr-unlock-modal', confirmBtnId: 'okr-unlock-confirm-btn',
        errorId: 'okr-unlock-error', action: 'unlockPayoutCards', countKey: 'unlocked_count',
        doneLabel: 'Unlocked', failMsg: 'Failed to unlock payout.',
    });

    function openLockForStaff(staffRow) {
        var msg = 'This locks every <strong>Complete</strong> / <strong>Complete with Excellence</strong> OKR for <strong>' +
            escapeHtml(staffRow.name) + '</strong> matching the current filter. Locked OKRs can no longer be edited, deleted, or suspended by anyone.';
        lockController.open({ staff_id: staffRow.staff_id }, msg);
    }

    function openUnlockForStaff(staffRow) {
        var msg = 'This unlocks every currently locked OKR for <strong>' + escapeHtml(staffRow.name) +
            '</strong> matching the current filter, allowing them to be edited, deleted, or suspended again.';
        unlockController.open({ staff_id: staffRow.staff_id }, msg);
    }

    if (lockSelectedBtn) {
        lockSelectedBtn.addEventListener('click', function () {
            var ids = selectedIdsArray();
            if (ids.length === 0) { return; }
            var msg = 'This locks every <strong>Complete</strong> / <strong>Complete with Excellence</strong> OKR for the <strong>' +
                ids.length + ' selected staff</strong> matching the current filter. Locked OKRs can no longer be edited, deleted, or suspended by anyone.';
            lockController.open({ staff_ids: ids.join(',') }, msg);
        });
    }

    if (unlockSelectedBtn) {
        unlockSelectedBtn.addEventListener('click', function () {
            var ids = selectedIdsArray();
            if (ids.length === 0) { return; }
            var msg = 'This unlocks every currently locked OKR for the <strong>' + ids.length +
                ' selected staff</strong> matching the current filter, allowing them to be edited, deleted, or suspended again.';
            unlockController.open({ staff_ids: ids.join(',') }, msg);
        });
    }

    loadTable();
    updateExportLink();
})();
