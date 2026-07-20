(function () {
    var CFG = window.OKR_VIEW || { cards: [], departments: {} };
    var tbody = document.getElementById('okr-view-tbody');
    var emptyState = document.getElementById('okr-empty-state');
    var searchFilter = document.getElementById('okr-filter-search');
    var typeFilter = document.getElementById('okr-filter-type');
    var levelFilter = document.getElementById('okr-filter-level');
    var deptFilter = document.getElementById('okr-filter-dept');
    var yearFilter = document.getElementById('okr-filter-year');
    var monthFilter = document.getElementById('okr-filter-month');
    var startDateFilter = document.getElementById('okr-filter-start-date');
    var endDateFilter = document.getElementById('okr-filter-end-date');
    var resetFilterBtn = document.getElementById('okr-filter-reset');

    // Set when index.php's Overdue Cards stat tile deep-links here (?overdue=1) -
    // applied as an extra predicate in render() alongside the Active/Extend
    // statuses that link already pre-selects.
    var deepLinkOverdueOnly = false;

    // Set from a dashboard deep link's quarter-derived from/to range. Kept
    // separate from the Start Date/End Date filter inputs above, which are
    // exact-match (card.start_date === value) rather than a range - reusing
    // them here would silently match nothing.
    var deepLinkDateFrom = '';
    var deepLinkDateTo = '';

    // Pre-fills filters from a dashboard stat-card deep link (index.php's
    // buildListUrl) - status/statuses, overdue, year, month, from/to and dept.
    // Runs once on load, before the initial render().
    function applyUrlParams() {
        var params = new URLSearchParams(window.location.search);
        if (!params.toString()) { return; }

        var statusesParam = params.get('statuses');
        var statusParam = params.get('status');
        if (statusesParam || statusParam) {
            var wanted = statusesParam ? statusesParam.split(',') : [statusParam];
            var boxes = allStatusCheckboxes('okr-filter-status');
            for (var i = 0; i < boxes.length; i++) {
                boxes[i].checked = wanted.indexOf(boxes[i].value) !== -1;
            }
            updateStatusButtonLabel('okr-filter-status');
        }

        if (params.get('year'))  { yearFilter.value = params.get('year'); }
        if (params.get('month')) { monthFilter.value = params.get('month'); }
        if (params.get('from'))  { deepLinkDateFrom = params.get('from'); }
        if (params.get('to'))    { deepLinkDateTo = params.get('to'); }
        if (params.get('dept'))  { deptFilter.value = params.get('dept'); }
        if (params.get('level')) { levelFilter.value = params.get('level'); }
        if (params.get('type'))  { typeFilter.value = params.get('type'); }
        if (params.get('overdue') === '1') { deepLinkOverdueOnly = true; }
    }

    // Generic searchable single-select dropdown, mirrors ATEM's
    // vf-issuer-wrap/vf-s2-* widget (buildS2Dropdown in atem/js/view.js).
    // Wires open/close, type-to-filter search, and item selection for a
    // {baseId}-wrap/-btn/-dropdown/-search/-list/-value element set.
    function wireS2Dropdown(baseId, onSelect) {
        var wrapEl   = document.getElementById(baseId + '-wrap');
        var btnEl    = document.getElementById(baseId + '-btn');
        var dropEl   = document.getElementById(baseId + '-dropdown');
        var searchEl = document.getElementById(baseId + '-search');
        var listEl   = document.getElementById(baseId + '-list');
        var valueEl  = document.getElementById(baseId + '-value');
        if (!wrapEl || !btnEl || !dropEl || !listEl) { return; }

        function filterList(term) {
            var lower = term.toLowerCase();
            var items = listEl.querySelectorAll('li');
            for (var i = 0; i < items.length; i++) {
                var match = items[i].textContent.toLowerCase().indexOf(lower) >= 0;
                items[i].classList.toggle('hidden', !match);
            }
        }

        function open() {
            dropEl.classList.add('open');
            if (searchEl) {
                searchEl.value = '';
                filterList('');
                searchEl.focus();
            }
        }

        function close() {
            dropEl.classList.remove('open');
        }

        btnEl.addEventListener('click', function () {
            if (dropEl.classList.contains('open')) { close(); } else { open(); }
        });
        btnEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
        });

        if (searchEl) {
            searchEl.addEventListener('input', function () { filterList(this.value); });
            searchEl.addEventListener('click', function (e) { e.stopPropagation(); });
        }

        listEl.addEventListener('click', function (e) {
            var li = e.target.closest ? e.target.closest('li[data-id]') : null;
            if (!li) { return; }
            var id = li.getAttribute('data-id');
            var label = li.textContent;
            if (valueEl) { valueEl.value = id; }
            btnEl.textContent = label;
            close();
            if (onSelect) { onSelect(id, label); }
        });

        document.addEventListener('click', function (e) {
            if (!wrapEl.contains(e.target)) { close(); }
        });
    }

    function resetS2Dropdown(baseId, defaultLabel) {
        var valueEl = document.getElementById(baseId + '-value');
        var btnEl   = document.getElementById(baseId + '-btn');
        if (valueEl) { valueEl.value = ''; }
        if (btnEl)   { btnEl.textContent = defaultLabel; }
    }

    // Checkbox multi-select dropdown, mirrors ATEM's Status filter
    // (buildStatusDropdown/getSelectedStatuses/updateStatusButtonLabel in
    // atem/js/view.js). No search box; open/close only, label reflects
    // how many statuses are checked.
    function allStatusCheckboxes(baseId) {
        var listEl = document.getElementById(baseId + '-list');
        return listEl ? listEl.querySelectorAll('input[type=checkbox]') : [];
    }

    function getSelectedStatuses(baseId) {
        var listEl = document.getElementById(baseId + '-list');
        if (!listEl) { return []; }
        var boxes = listEl.querySelectorAll('input[type=checkbox]:checked');
        var values = [];
        for (var i = 0; i < boxes.length; i++) { values.push(boxes[i].value); }
        return values;
    }

    function updateStatusButtonLabel(baseId) {
        var btnEl = document.getElementById(baseId + '-btn');
        if (!btnEl) { return; }
        var selected = getSelectedStatuses(baseId);
        var all = allStatusCheckboxes(baseId);
        if (selected.length === 0) {
            btnEl.textContent = 'No status selected';
        } else if (selected.length === all.length) {
            btnEl.textContent = 'All statuses';
        } else if (selected.length <= 2) {
            btnEl.textContent = selected.join(', ');
        } else {
            btnEl.textContent = selected.length + ' statuses selected';
        }
    }

    function wireStatusCheckboxDropdown(baseId, onChange) {
        var wrapEl = document.getElementById(baseId + '-wrap');
        var btnEl  = document.getElementById(baseId + '-btn');
        var dropEl = document.getElementById(baseId + '-dropdown');
        if (!wrapEl || !btnEl || !dropEl) { return; }

        btnEl.addEventListener('click', function () {
            dropEl.classList.toggle('open');
        });
        btnEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dropEl.classList.add('open'); }
        });
        dropEl.addEventListener('change', function (e) {
            if (e.target.type !== 'checkbox') { return; }
            updateStatusButtonLabel(baseId);
            if (onChange) { onChange(); }
        });
        document.addEventListener('click', function (e) {
            if (!wrapEl.contains(e.target)) { dropEl.classList.remove('open'); }
        });
        updateStatusButtonLabel(baseId);
    }

    function resetStatusCheckboxDropdown(baseId) {
        var boxes = allStatusCheckboxes(baseId);
        for (var i = 0; i < boxes.length; i++) { boxes[i].checked = true; }
        updateStatusButtonLabel(baseId);
    }

    function pillClass(status) {
        var map = {
            'Draft': 'okr-pill-draft',
            'Active': 'okr-pill-active',
            'Complete': 'okr-pill-complete',
            'Complete with Excellence': 'okr-pill-complete-excellence',
            'Extend': 'okr-pill-extend',
            'Suspended': 'okr-pill-suspended',
            'Fail': 'okr-pill-fail'
        };
        return map[status] || 'okr-pill-active';
    }

    // Level badge color map, mirrors atem/js/view.js's LEVEL_COLOR.
    var LEVEL_COLOR = { 1: '#6c757d', 2: '#0d6efd', 3: '#6610f2', 4: '#003B73' };

    function levelPill(level) {
        var color = LEVEL_COLOR[level] || '#6c757d';
        return '<span class="okr-pill" style="background-color:' + color + '">L' + level + '</span>';
    }

    // Type badge colors - kept distinct from the Level (gray/blue/indigo/navy)
    // and Status (gray/blue/green/cyan/orange/rose/red) palettes above.
    var TYPE_COLOR = { 'Committed': '#6f42c1', 'Aspiration': '#d63384', 'Learning': '#20c997' };

    function typePill(type) {
        var color = TYPE_COLOR[type] || '#6c757d';
        return '<span class="okr-pill" style="background-color:' + color + '">' + escapeHtml(type) + '</span>';
    }

    // staff.department is a comma-separated list of department ids (a staff
    // member can belong to more than one) - resolve every id present via the
    // deptNames map built server-side and join for display.
    function deptNamesForCsv(csv) {
        if (!csv) { return '-'; }
        var names = String(csv).split(',').map(function (s) { return s.trim(); }).filter(Boolean).map(function (id) {
            return (CFG.deptNames && CFG.deptNames[id]) || null;
        }).filter(Boolean);
        return names.length ? names.join(', ') : '-';
    }

    // Owner(s) cell: name + department per owner, on its own row, with a
    // divider between owners when there are two - mirrors atem/js/view.js's
    // buildAccountableCell.
    function ownerCell(card) {
        var owners = [];
        if (card.owner_staff_id > 0) { owners.push({ name: card.owner_name, dept: card.owner_department }); }
        if (card.owner2_staff_id > 0) { owners.push({ name: card.owner2_name, dept: card.owner2_department }); }
        if (owners.length === 0) {
            return '<span style="color:#adb5bd;font-size:12px;">-</span>';
        }
        var html = '';
        for (var i = 0; i < owners.length; i++) {
            if (i > 0) {
                html += '<div style="border-top:1px solid #dee2e6;margin:4px 0;"></div>';
            }
            html += '<div style="font-size:13px;">' + escapeHtml(owners[i].name) + '</div>'
                + '<div style="font-size:11px;color:#6c757d;">' + escapeHtml(deptNamesForCsv(owners[i].dept)) + '</div>';
        }
        return html;
    }

    // Issuer cell: name + department, mirrors atem/js/view.js's buildIssuerCell.
    function issuerCell(card) {
        return '<div style="font-size:13px;">' + escapeHtml(card.issuer_name || '-') + '</div>'
            + '<div style="font-size:11px;color:#6c757d;">' + escapeHtml(deptNamesForCsv(card.issuer_department)) + '</div>';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : str;
        return div.innerHTML;
    }

    // Click-to-sort table headers, mirrors atem/js/view.js's sortRows/
    // updateSortIndicators. sortCol === null keeps the server's default
    // order (created_at desc).
    var sortState = { col: null, dir: 1 };

    function sortRows(list) {
        if (sortState.col === null) { return list; }
        var col = sortState.col, dir = sortState.dir;
        return list.slice().sort(function (a, b) {
            var av = a[col], bv = b[col];
            if (col === 'id' || col === 'difficulty_level') {
                av = Number(av) || 0;
                bv = Number(bv) || 0;
            } else {
                av = String(av == null ? '' : av).toLowerCase();
                bv = String(bv == null ? '' : bv).toLowerCase();
            }
            if (av < bv) { return -1 * dir; }
            if (av > bv) { return 1 * dir; }
            return 0;
        });
    }

    function updateSortIndicators() {
        var ths = document.querySelectorAll('#okr-view-tbl th.okr-sortable');
        for (var i = 0; i < ths.length; i++) {
            ths[i].classList.remove('okr-sort-asc', 'okr-sort-desc');
            if (sortState.col !== null && ths[i].getAttribute('data-col') === sortState.col) {
                ths[i].classList.add(sortState.dir === 1 ? 'okr-sort-asc' : 'okr-sort-desc');
            }
        }
    }

    function canEdit(card) {
        return !card.deleted_at && (CFG.requesterIsAdmin || card.issuer_staff_id === CFG.requesterId) && !card.incentive_locked;
    }

    // Admin can delete any (non-locked) card; the issuer can only delete
    // their own card while it's still a Draft - once it's Active/closed/etc.
    // only an admin can remove it.
    function canDelete(card) {
        if (card.deleted_at || card.incentive_locked) { return false; }
        if (CFG.requesterIsAdmin) { return true; }
        return card.issuer_staff_id === CFG.requesterId && card.result_status === 'Draft';
    }

    function render() {
        var search = searchFilter.value.trim().toLowerCase();
        var statuses = getSelectedStatuses('okr-filter-status');
        var allStatusCount = allStatusCheckboxes('okr-filter-status').length;
        var type = typeFilter.value;
        var level = levelFilter.value;
        var deptId = deptFilter.value;
        var ownerValueEl = document.getElementById('okr-filter-owner-value');
        var issuerValueEl = document.getElementById('okr-filter-issuer-value');
        var ownerId = ownerValueEl ? ownerValueEl.value : '';
        var issuerId = issuerValueEl ? issuerValueEl.value : '';
        var year = yearFilter.value;
        var month = monthFilter.value;
        var startDate = startDateFilter.value;
        var endDate = endDateFilter.value;

        var rows = CFG.cards.filter(function (card) {
            if (statuses.length === 0) return false;
            if (statuses.length < allStatusCount && statuses.indexOf(card.result_status) === -1) return false;
            if (type && card.okr_type !== type) return false;
            if (level && String(card.difficulty_level) !== level) return false;
            if (deptId) {
                var deptIds = (card.dept_scope || '').split(',').map(function (s) { return s.trim(); });
                if (deptIds.indexOf(deptId) === -1) return false;
            }
            if (ownerId && String(card.owner_staff_id) !== ownerId && String(card.owner2_staff_id) !== ownerId) return false;
            if (issuerId && String(card.issuer_staff_id) !== issuerId) return false;
            if (year && card.start_date && card.start_date.slice(0, 4) !== year) return false;
            if (month && card.start_date && String(parseInt(card.start_date.slice(5, 7), 10)) !== month) return false;
            if (startDate && card.start_date !== startDate) return false;
            if (endDate && card.end_date !== endDate) return false;
            if (deepLinkDateFrom && (!card.start_date || card.start_date < deepLinkDateFrom)) return false;
            if (deepLinkDateTo && (!card.start_date || card.start_date > deepLinkDateTo)) return false;
            if (deepLinkOverdueOnly) {
                var today = new Date().toISOString().slice(0, 10);
                var isOverdue = card.end_date && card.end_date < today
                    && (card.result_status === 'Active' || card.result_status === 'Extend');
                if (!isOverdue) return false;
            }
            if (search) {
                var searchHay = ('okr' + card.id + ' ' + (card.objective || '')).toLowerCase();
                if (searchHay.indexOf(search) === -1) return false;
            }
            return true;
        });

        rows = sortRows(rows);
        updateSortIndicators();

        tbody.innerHTML = '';
        emptyState.style.display = rows.length === 0 ? 'block' : 'none';

        rows.forEach(function (card) {
            var tr = document.createElement('tr');
            if (card.deleted_at) { tr.classList.add('okr-row-deleted'); }
            var actions = '<a class="btn btn-outline-secondary btn-sm" href="okr/view.php?id=' + card.id + '" title="View"><i class="bi bi-eye"></i></a>';
            if (canEdit(card)) {
                actions += ' <a class="btn btn-outline-secondary btn-sm" href="okr/edit.php?id=' + card.id + '" title="Edit"><i class="bi bi-pencil"></i></a>';
            }
            if (canDelete(card)) {
                actions += ' <button type="button" class="btn btn-outline-danger btn-sm okr-list-delete-btn" data-id="' + card.id + '" title="Delete"><i class="bi bi-trash"></i></button>';
            }
            if (card.deleted_at && CFG.requesterIsAdmin) {
                actions += ' <button type="button" class="btn btn-danger btn-sm okr-list-permadelete-btn" data-id="' + card.id + '" title="Delete Permanently"><i class="bi bi-trash3-fill"></i></button>';
            }
            var statusLabel = card.extended
                ? (card.result_status === 'Complete' ? 'Completed with extension' : (card.result_status === 'Fail' ? 'Failed' : card.result_status))
                : card.result_status;
            var statusCell = card.deleted_at
                ? '<span class="okr-pill okr-pill-fail">Deleted</span>'
                : '<span class="okr-pill ' + pillClass(card.result_status) + '">' + escapeHtml(statusLabel) + '</span>';
            tr.innerHTML =
                '<td><span class="okr-id">#OKR' + card.id + '</span></td>' +
                '<td>' + escapeHtml(card.objective).slice(0, 80) + '</td>' +
                '<td>' + issuerCell(card) + '</td>' +
                '<td>' + ownerCell(card) + '</td>' +
                '<td>' + levelPill(card.difficulty_level) + '</td>' +
                '<td>' + typePill(card.okr_type) + '</td>' +
                '<td>' + escapeHtml(card.start_date) + '</td>' +
                '<td>' + escapeHtml(card.end_date) + '</td>' +
                '<td>' + statusCell + '</td>' +
                '<td class="okr-view-actions">' + actions + '</td>';
            tbody.appendChild(tr);
        });
    }

    searchFilter.addEventListener('input', render);
    [typeFilter, levelFilter, deptFilter, yearFilter, monthFilter, startDateFilter, endDateFilter].forEach(function (el) {
        el.addEventListener('change', render);
    });
    wireS2Dropdown('okr-filter-owner', render);
    wireS2Dropdown('okr-filter-issuer', render);
    wireStatusCheckboxDropdown('okr-filter-status', render);

    document.querySelectorAll('#okr-view-tbl th.okr-sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            var col = this.getAttribute('data-col');
            if (sortState.col === col) { sortState.dir = -sortState.dir; } else { sortState.col = col; sortState.dir = 1; }
            render();
        });
    });

    resetFilterBtn.addEventListener('click', function () {
        searchFilter.value = '';
        resetStatusCheckboxDropdown('okr-filter-status');
        typeFilter.value = '';
        levelFilter.value = '';
        deptFilter.value = '';
        resetS2Dropdown('okr-filter-owner', 'All owners');
        resetS2Dropdown('okr-filter-issuer', 'All issuers');
        yearFilter.value = '';
        monthFilter.value = '';
        startDateFilter.value = '';
        endDateFilter.value = '';
        deepLinkOverdueOnly = false;
        deepLinkDateFrom = '';
        deepLinkDateTo = '';
        render();
    });

    applyUrlParams();
    render();

    // ---------------------------------------------------------------
    // Delete (soft delete via backend.php's deleteCard action). A single
    // shared confirm modal is reused for every row's Delete button.
    // ---------------------------------------------------------------
    var deleteModalEl = document.getElementById('okr-delete-modal');
    var deleteModal = deleteModalEl ? new bootstrap.Modal(deleteModalEl) : null;
    var deleteConfirmBtn = document.getElementById('okr-delete-confirm-btn');
    var deleteErrorEl = document.getElementById('okr-delete-error');
    var deleteToastEl = document.getElementById('okr-delete-toast');
    var deleteToast = deleteToastEl ? new bootstrap.Toast(deleteToastEl) : null;
    var pendingDeleteId = null;

    tbody.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.okr-list-delete-btn') : null;
        if (!btn || !deleteModal) { return; }
        pendingDeleteId = parseInt(btn.getAttribute('data-id'), 10);
        if (deleteErrorEl) { deleteErrorEl.textContent = ''; }
        deleteModal.show();
    });

    if (deleteConfirmBtn) {
        deleteConfirmBtn.addEventListener('click', function () {
            if (!pendingDeleteId) { return; }
            if (deleteErrorEl) { deleteErrorEl.textContent = ''; }

            var payload = new URLSearchParams();
            payload.set('action', 'deleteCard');
            payload.set('id', pendingDeleteId);

            fetch('okr/backend.php', { method: 'POST', body: payload })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        if (CFG.requesterIsAdmin) {
                            CFG.cards.forEach(function (c) {
                                if (c.id === pendingDeleteId) { c.deleted_at = new Date().toISOString(); }
                            });
                        } else {
                            CFG.cards = CFG.cards.filter(function (c) { return c.id !== pendingDeleteId; });
                        }
                        deleteModal.hide();
                        render();
                        if (deleteToast) { deleteToast.show(); }
                    } else if (deleteErrorEl) {
                        deleteErrorEl.textContent = res.message || 'Failed to delete OKR.';
                    }
                })
                .catch(function () {
                    if (deleteErrorEl) { deleteErrorEl.textContent = 'Network error. Please try again.'; }
                });
        });
    }

    // ---------------------------------------------------------------
    // Delete Permanently (admin-only hard delete via backend.php's
    // permanentlyDeleteCard action). Only shown for already-deleted rows.
    // ---------------------------------------------------------------
    var permaModalEl = document.getElementById('okr-permadelete-modal');
    var permaModal = permaModalEl ? new bootstrap.Modal(permaModalEl) : null;
    var permaConfirmBtn = document.getElementById('okr-permadelete-confirm-btn');
    var permaErrorEl = document.getElementById('okr-permadelete-error');
    var permaToastEl = document.getElementById('okr-permadelete-toast');
    var permaToast = permaToastEl ? new bootstrap.Toast(permaToastEl) : null;
    var pendingPermaDeleteId = null;

    tbody.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.okr-list-permadelete-btn') : null;
        if (!btn || !permaModal) { return; }
        pendingPermaDeleteId = parseInt(btn.getAttribute('data-id'), 10);
        if (permaErrorEl) { permaErrorEl.textContent = ''; }
        permaModal.show();
    });

    if (permaConfirmBtn) {
        permaConfirmBtn.addEventListener('click', function () {
            if (!pendingPermaDeleteId) { return; }
            if (permaErrorEl) { permaErrorEl.textContent = ''; }

            var payload = new URLSearchParams();
            payload.set('action', 'permanentlyDeleteCard');
            payload.set('id', pendingPermaDeleteId);

            fetch('okr/backend.php', { method: 'POST', body: payload })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        CFG.cards = CFG.cards.filter(function (c) { return c.id !== pendingPermaDeleteId; });
                        permaModal.hide();
                        render();
                        if (permaToast) { permaToast.show(); }
                    } else if (permaErrorEl) {
                        permaErrorEl.textContent = res.message || 'Failed to permanently delete OKR.';
                    }
                })
                .catch(function () {
                    if (permaErrorEl) { permaErrorEl.textContent = 'Network error. Please try again.'; }
                });
        });
    }
})();
