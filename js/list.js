(function () {
    var CFG = window.OKR_VIEW || { cards: [], departments: {} };
    var tbody = document.getElementById('okr-view-tbody');
    var emptyState = document.getElementById('okr-empty-state');
    var searchFilter = document.getElementById('okr-filter-search');
    var statusFilter = document.getElementById('okr-filter-status');
    var typeFilter = document.getElementById('okr-filter-type');
    var levelFilter = document.getElementById('okr-filter-level');
    var deptFilter = document.getElementById('okr-filter-dept');
    var ownerFilter = document.getElementById('okr-filter-owner');
    var issuerFilter = document.getElementById('okr-filter-issuer');
    var yearFilter = document.getElementById('okr-filter-year');
    var monthFilter = document.getElementById('okr-filter-month');
    var startDateFilter = document.getElementById('okr-filter-start-date');
    var endDateFilter = document.getElementById('okr-filter-end-date');
    var resetFilterBtn = document.getElementById('okr-filter-reset');
    var searchFilterBtn = document.getElementById('okr-filter-search-btn');

    function pillClass(status) {
        var map = {
            'Active': 'okr-pill-active',
            'Complete': 'okr-pill-complete',
            'Complete with Excellence': 'okr-pill-complete-excellence',
            'Extend': 'okr-pill-extend',
            'Suspended': 'okr-pill-suspended',
            'Fail': 'okr-pill-fail'
        };
        return map[status] || 'okr-pill-active';
    }

    function ownerText(card) {
        var text = card.owner_name || '-';
        if (card.owner2_name) {
            text += ' & ' + card.owner2_name;
        }
        return text;
    }

    function incentiveText(card) {
        if (card.result_status === 'Complete' || card.result_status === 'Complete with Excellence') {
            return 'RM' + Number(card.level_rm).toFixed(2);
        }
        return '-';
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : str;
        return div.innerHTML;
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
        var status = statusFilter.value;
        var type = typeFilter.value;
        var level = levelFilter.value;
        var deptId = deptFilter.value;
        var ownerId = ownerFilter.value;
        var issuerId = issuerFilter.value;
        var year = yearFilter.value;
        var month = monthFilter.value;
        var startDate = startDateFilter.value;
        var endDate = endDateFilter.value;

        var rows = CFG.cards.filter(function (card) {
            if (status && card.result_status !== status) return false;
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
            if (search) {
                var searchHay = ('okr' + card.id + ' ' + (card.objective || '')).toLowerCase();
                if (searchHay.indexOf(search) === -1) return false;
            }
            return true;
        });

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
                '<td>OKR' + card.id + '</td>' +
                '<td>' + escapeHtml(card.objective).slice(0, 80) + '</td>' +
                '<td>' + escapeHtml(card.okr_type) + '</td>' +
                '<td>L' + card.difficulty_level + '</td>' +
                '<td>' + escapeHtml(ownerText(card)) + '</td>' +
                '<td>' + escapeHtml(card.issuer_name) + '</td>' +
                '<td>' + escapeHtml(card.start_date) + '</td>' +
                '<td>' + escapeHtml(card.end_date) + '</td>' +
                '<td>' + statusCell + '</td>' +
                '<td>' + incentiveText(card) + '</td>' +
                '<td>' + actions + '</td>';
            tbody.appendChild(tr);
        });
    }

    searchFilter.addEventListener('input', render);
    [statusFilter, typeFilter, levelFilter, deptFilter, ownerFilter, issuerFilter, yearFilter, monthFilter, startDateFilter, endDateFilter].forEach(function (el) {
        el.addEventListener('change', render);
    });
    searchFilterBtn.addEventListener('click', render);
    resetFilterBtn.addEventListener('click', function () {
        searchFilter.value = '';
        statusFilter.value = '';
        typeFilter.value = '';
        levelFilter.value = '';
        deptFilter.value = '';
        ownerFilter.value = '';
        issuerFilter.value = '';
        yearFilter.value = '';
        monthFilter.value = '';
        startDateFilter.value = '';
        endDateFilter.value = '';
        render();
    });
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
