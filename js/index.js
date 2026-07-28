(function () {
    'use strict';

    var CFG = window.OKR_DASH || {};

    function apiCall(action, params) {
        var query = new URLSearchParams();
        query.set('action', action);
        if (params) {
            for (var k in params) {
                if (params.hasOwnProperty(k)) { query.set(k, params[k]); }
            }
        }
        return fetch(CFG.apiUrl + '?' + query.toString()).then(function (r) { return r.json(); });
    }

    function formatNumber(n) {
        return (n || 0).toLocaleString('en-MY');
    }

    function setText(id, val) {
        var el = document.getElementById(id);
        if (el) { el.textContent = val; }
    }

    function setWidth(id, pct) {
        var el = document.getElementById(id);
        if (el) { el.style.width = pct + '%'; }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Generic searchable single-select dropdown, mirrors ATEM's
    // vf-issuer-wrap/vf-s2-* widget (buildS2Dropdown in atem/js/index.js).
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

    function setLoading(on) {
        var cards = document.querySelectorAll('.okr-stat-value');
        for (var i = 0; i < cards.length; i++) {
            cards[i].style.opacity = on ? '0.4' : '1';
        }
    }

    function renderDashboard(data) {
        var s = data.by_status;
        var total  = data.total || 0;
        var active = s.active || 0;
        var closed = (s.complete || 0) + (s.excellence || 0);
        var failed = s.failed || 0;
        var failRate = total > 0 ? (failed / total * 100).toFixed(1) + '% failure rate' : '0.0% failure rate';

        var extended = s.extended || 0;
        var extLabelEl = document.getElementById('dash-extended-label');
        if (extLabelEl) {
            if (extended > 0) {
                extLabelEl.textContent = 'with ' + formatNumber(extended) + ' extended';
                extLabelEl.style.display = '';
            } else {
                extLabelEl.style.display = 'none';
            }
        }

        setText('dash-total',     formatNumber(total));
        setText('dash-active',    formatNumber(active));
        setText('dash-closed',    formatNumber(closed));
        setText('dash-failed',    formatNumber(failed));
        setText('dash-fail-rate', failRate);
        setText('dash-overdue',   formatNumber(data.overdue_count || 0));

        setWidth('bar-complete',   total > 0 ? Math.round((s.complete   || 0) / total * 100) : 0);
        setWidth('bar-excellence', total > 0 ? Math.round((s.excellence || 0) / total * 100) : 0);
        setWidth('bar-extended',   total > 0 ? Math.round(extended            / total * 100) : 0);
        setWidth('bar-failed',     total > 0 ? Math.round(failed              / total * 100) : 0);
        setText('bar-complete-n',   s.complete   || 0);
        setText('bar-excellence-n', s.excellence || 0);
        setText('bar-extended-n',   extended);
        setText('bar-failed-n',     failed);

        var deptTbody = document.getElementById('dash-dept-body');
        if (deptTbody) {
            if (data.by_department && data.by_department.length > 0) {
                var dHtml = '';
                for (var d = 0; d < data.by_department.length; d++) {
                    var dept      = data.by_department[d];
                    var dFail     = dept.fail || 0;
                    var dCards    = dept.cards || 0;
                    var dFailRate = dCards > 0 ? (dFail / dCards * 100).toFixed(1) + '%' : '0%';
                    var dId = dept.dept_id || '';
                    // Unassigned (dept_id 0, no dept scope on the issuer) has
                    // nothing valid to filter list.php by - render plain text.
                    var dClickable = dId ? 'cursor:pointer;text-decoration:underline;' : '';
                    dHtml += '<tr>' +
                        '<td style="font-size:12px;font-weight:600;text-align:left;">' + escapeHtml(dept.dept_name) + '</td>' +
                        '<td style="font-size:12px;text-align:left;' + dClickable + '"' + (dId ? ' data-nav-dept="' + dId + '" data-nav-status=""' : '') + '>' + dCards + '</td>' +
                        '<td style="font-size:12px;color:#0d6efd;text-align:left;' + dClickable + '"' + (dId ? ' data-nav-dept="' + dId + '" data-nav-status="Completed,Completed with Extension"' : '') + '>' + (dept.complete || 0) + '</td>' +
                        '<td style="font-size:12px;color:#198754;text-align:left;' + dClickable + '"' + (dId ? ' data-nav-dept="' + dId + '" data-nav-status="Completed with Excellence"' : '') + '>' + (dept.excellence || 0) + '</td>' +
                        '<td style="font-size:12px;color:#dc3545;text-align:left;' + dClickable + '"' + (dId ? ' data-nav-dept="' + dId + '" data-nav-status="Failed"' : '') + '>' + dFail + '</td>' +
                        '<td style="font-size:12px;text-align:left;">' + dFailRate + '</td>' +
                        '<td style="font-size:12px;color:#e11d48;text-align:left;' + dClickable + '"' + (dId ? ' data-nav-dept="' + dId + '" data-nav-status="Suspended"' : '') + '>' + (dept.suspended || 0) + '</td>' +
                        // Force Terminate isn't a real status (it sets Failed +
                        // a flag - see backend.php), so this column isn't a
                        // clickable list.php status-filter link like the others.
                        '<td style="font-size:12px;color:#6610f2;text-align:left;">' + (dept.force_terminated || 0) + '</td>' +
                        '</tr>';
                }
                deptTbody.innerHTML = dHtml;
                deptTbody.onclick = function (e) {
                    var td = e.target;
                    while (td && td !== deptTbody) {
                        if (td.tagName === 'TD' && td.hasAttribute('data-nav-dept')) {
                            var navDept = td.getAttribute('data-nav-dept');
                            if (!navDept) { return; }
                            var navStatus = td.getAttribute('data-nav-status') || '';
                            window.location.href = buildListUrl('', navStatus ? navStatus.split(',') : [], false, navDept);
                            return;
                        }
                        td = td.parentNode;
                    }
                };
            } else {
                deptTbody.innerHTML = '<tr><td colspan="8" class="text-muted" style="font-size:12px;">No data for the selected period.</td></tr>';
            }
        }

        var staffSuspendTbody = document.getElementById('dash-staff-suspend-body');
        if (staffSuspendTbody) {
            if (data.by_staff_suspend && data.by_staff_suspend.length > 0) {
                var ssHtml = '';
                for (var s2 = 0; s2 < data.by_staff_suspend.length; s2++) {
                    var staffRow = data.by_staff_suspend[s2];
                    ssHtml += '<tr>' +
                        '<td style="font-size:12px;font-weight:600;text-align:left;">' + escapeHtml(staffRow.staff_name) + '</td>' +
                        '<td style="font-size:12px;color:#e11d48;text-align:left;">' + (staffRow.suspended || 0) + '</td>' +
                        '<td style="font-size:12px;color:#6610f2;text-align:left;">' + (staffRow.force_terminated || 0) + '</td>' +
                        '</tr>';
                }
                staffSuspendTbody.innerHTML = ssHtml;
            } else {
                staffSuspendTbody.innerHTML = '<tr><td colspan="3" class="text-muted" style="font-size:12px;">No suspended/force-terminated OKRs in this period.</td></tr>';
            }
        }

        setLoading(false);
    }

    function showError(msg) {
        setLoading(false);
        setText('dash-total',     'err');
        setText('dash-active',    'err');
        setText('dash-closed',    'err');
        setText('dash-failed',    'err');
        setText('dash-overdue',   'err');
        var deptTbody = document.getElementById('dash-dept-body');
        if (deptTbody) {
            deptTbody.innerHTML = '<tr><td colspan="8" class="text-danger" style="font-size:12px;">' + msg + '</td></tr>';
        }
        var staffSuspendTbodyErr = document.getElementById('dash-staff-suspend-body');
        if (staffSuspendTbodyErr) {
            staffSuspendTbodyErr.innerHTML = '<tr><td colspan="3" class="text-danger" style="font-size:12px;">' + msg + '</td></tr>';
        }
    }

    // Stat-card click navigation to list.php, mirrors atem/js/index.js's
    // buildViewUrl. list.php has no Quarter field of its own, so a selected
    // quarter is converted to a from/to date range same as atem does.
    var QUARTER_RANGES = {
        '1': ['01-01', '03-31'],
        '2': ['04-01', '06-30'],
        '3': ['07-01', '09-30'],
        '4': ['10-01', '12-31']
    };

    // deptOverride lets the Department breakdown-table row clicks target a
    // specific value regardless of the dashboard's own filters; falls back
    // to the currently selected dash-filter-dept when not given, same as
    // the plain stat cards.
    function buildListUrl(statusOverride, statusesOverride, overdueOnly, deptOverride) {
        var yearEl    = document.getElementById('dash-filter-year');
        var monthEl   = document.getElementById('dash-filter-month');
        var quarterEl = document.getElementById('dash-filter-quarter');
        var deptEl    = document.getElementById('dash-filter-dept');

        var year    = yearEl    ? yearEl.value    : '';
        var month   = monthEl   ? monthEl.value   : '';
        var quarter = quarterEl ? quarterEl.value : '';
        var deptId  = deptOverride || (deptEl ? deptEl.value : '');

        var params = [];
        if (statusOverride) { params.push('status=' + encodeURIComponent(statusOverride)); }
        if (statusesOverride && statusesOverride.length) { params.push('statuses=' + encodeURIComponent(statusesOverride.join(','))); }
        if (overdueOnly) { params.push('overdue=1'); }
        if (year)  { params.push('year='  + encodeURIComponent(year)); }
        if (month) { params.push('month=' + encodeURIComponent(month)); }
        if (!month && quarter && year && QUARTER_RANGES[quarter]) {
            var range = QUARTER_RANGES[quarter];
            params.push('from=' + encodeURIComponent(year + '-' + range[0]));
            params.push('to='   + encodeURIComponent(year + '-' + range[1]));
        }
        if (deptId) { params.push('dept=' + encodeURIComponent(deptId)); }

        return 'okr/list.php' + (params.length ? '?' + params.join('&') : '');
    }

    function buildPayload() {
        var payload = {};
        var yearEl    = document.getElementById('dash-filter-year');
        var monthEl   = document.getElementById('dash-filter-month');
        var quarterEl = document.getElementById('dash-filter-quarter');
        var deptEl    = document.getElementById('dash-filter-dept');
        var staffValueEl = document.getElementById('dash-staff-value');

        var year    = yearEl    ? parseInt(yearEl.value,    10) : 0;
        var month   = monthEl   ? parseInt(monthEl.value,   10) : 0;
        var quarter = quarterEl ? parseInt(quarterEl.value, 10) : 0;
        var deptId  = deptEl    ? parseInt(deptEl.value,    10) : 0;
        var staffId = staffValueEl ? parseInt(staffValueEl.value, 10) : 0;

        if (year    > 0) { payload.filter_year    = year;    }
        if (month   > 0) { payload.filter_month   = month;   }
        if (quarter > 0) { payload.filter_quarter = quarter; }
        if (deptId  > 0) { payload.filter_dept_id = deptId;  }
        if (staffId > 0) { payload.filter_staff_id = staffId; }

        return payload;
    }

    function buildLabel() {
        var yearEl    = document.getElementById('dash-filter-year');
        var monthEl   = document.getElementById('dash-filter-month');
        var quarterEl = document.getElementById('dash-filter-quarter');
        var deptEl    = document.getElementById('dash-filter-dept');
        var staffValueEl = document.getElementById('dash-staff-value');
        var staffBtnEl   = document.getElementById('dash-staff-btn');

        var parts = [];
        var yearVal    = yearEl    ? yearEl.value    : '';
        var monthVal   = monthEl   ? monthEl.value   : '';
        var quarterVal = quarterEl ? quarterEl.value  : '';
        var deptVal    = deptEl    ? deptEl.value    : '';
        var staffVal   = staffValueEl ? staffValueEl.value : '0';

        if (!yearVal && !monthVal && !quarterVal && !deptVal && (!staffVal || staffVal === '0')) { return 'Showing all records'; }

        if (yearVal) { parts.push(yearVal); }

        if (monthVal) {
            var months = ['', 'January', 'February', 'March', 'April', 'May', 'June',
                              'July', 'August', 'September', 'October', 'November', 'December'];
            parts.push(months[parseInt(monthVal, 10)] || monthVal);
        }

        if (quarterVal) {
            var qLabels = { 1: 'Q1 (Jan-Mar)', 2: 'Q2 (Apr-Jun)', 3: 'Q3 (Jul-Sep)', 4: 'Q4 (Oct-Dec)' };
            parts.push(qLabels[parseInt(quarterVal, 10)] || ('Q' + quarterVal));
        }

        if (deptEl && deptEl.selectedIndex > 0) {
            parts.push(deptEl.options[deptEl.selectedIndex].text);
        }

        if (staffBtnEl && staffVal && staffVal !== '0') {
            parts.push(staffBtnEl.textContent);
        }

        return 'Showing: ' + parts.join(', ');
    }

    function loadDashboard(payload) {
        setLoading(true);
        setText('dash-total',     '---');
        setText('dash-active',    '---');
        setText('dash-closed',    '---');
        setText('dash-failed',    '---');
        setText('dash-overdue',   '---');

        var deptTbody = document.getElementById('dash-dept-body');
        if (deptTbody) { deptTbody.innerHTML = '<tr><td colspan="8" class="text-muted" style="font-size:12px;">Loading...</td></tr>'; }
        var staffSuspendTbodyLoad = document.getElementById('dash-staff-suspend-body');
        if (staffSuspendTbodyLoad) { staffSuspendTbodyLoad.innerHTML = '<tr><td colspan="3" class="text-muted" style="font-size:12px;">Loading...</td></tr>'; }

        var lbl = document.getElementById('dash-filter-label');
        if (lbl) { lbl.textContent = buildLabel(); }

        apiCall('dashboardStats', payload || {}).then(function (res) {
            if (res && res.success && res.data) {
                renderDashboard(res.data);
            } else {
                showError(res && res.message ? res.message : 'Failed to load dashboard data.');
            }
        }).catch(function () {
            showError('Network error. Please try again.');
        });
    }

    function populateDeptSelect() {
        var deptEl = document.getElementById('dash-filter-dept');
        if (!deptEl || !CFG.departments || !CFG.departments.length) { return; }
        for (var i = 0; i < CFG.departments.length; i++) {
            var opt = document.createElement('option');
            opt.value = CFG.departments[i].id;
            opt.textContent = CFG.departments[i].name;
            deptEl.appendChild(opt);
        }
    }

    // Rebuilds the Staff dropdown, narrowed to the currently selected
    // Department (if any) - same filter-cascade UX as create.php's owner
    // pickers. Keeps the previous selection if it's still in the narrowed
    // list, otherwise falls back to "All Staff".
    function populateStaffSelect() {
        var listEl  = document.getElementById('dash-staff-list');
        var deptEl  = document.getElementById('dash-filter-dept');
        var valueEl = document.getElementById('dash-staff-value');
        var btnEl   = document.getElementById('dash-staff-btn');
        if (!listEl || !CFG.staff) { return; }

        var deptId = deptEl ? parseInt(deptEl.value, 10) : 0;
        var currentValue = valueEl ? parseInt(valueEl.value, 10) : 0;
        var stillValid = (currentValue === 0);
        var currentLabel = 'All Staff';

        var html = '<li data-id="0">All Staff</li>';
        for (var i = 0; i < CFG.staff.length; i++) {
            var s = CFG.staff[i];
            if (deptId > 0 && (!s.deptIds || s.deptIds.indexOf(deptId) === -1)) { continue; }
            html += '<li data-id="' + s.id + '">' + escapeHtml(s.name) + '</li>';
            if (s.id === currentValue) { stillValid = true; currentLabel = s.name; }
        }
        listEl.innerHTML = html;

        if (!stillValid) {
            if (valueEl) { valueEl.value = '0'; }
            if (btnEl)   { btnEl.textContent = 'All Staff'; }
        } else if (btnEl) {
            btnEl.textContent = currentLabel;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        populateDeptSelect();
        populateStaffSelect();
        wireS2Dropdown('dash-staff', function () { loadDashboard(buildPayload()); });

        var resetBtn  = document.getElementById('dash-reset-filter');
        var yearEl    = document.getElementById('dash-filter-year');
        var monthEl   = document.getElementById('dash-filter-month');
        var quarterEl = document.getElementById('dash-filter-quarter');
        var deptEl    = document.getElementById('dash-filter-dept');

        if (monthEl) {
            monthEl.addEventListener('change', function () {
                if (this.value && quarterEl) { quarterEl.value = ''; }
                loadDashboard(buildPayload());
            });
        }
        if (quarterEl) {
            quarterEl.addEventListener('change', function () {
                if (this.value && monthEl) { monthEl.value = ''; }
                loadDashboard(buildPayload());
            });
        }
        if (yearEl) { yearEl.addEventListener('change', function () { loadDashboard(buildPayload()); }); }
        if (deptEl) {
            deptEl.addEventListener('change', function () {
                populateStaffSelect();
                loadDashboard(buildPayload());
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                var yearEl  = document.getElementById('dash-filter-year');
                var deptEl  = document.getElementById('dash-filter-dept');
                var staffValueEl = document.getElementById('dash-staff-value');
                var staffBtnEl   = document.getElementById('dash-staff-btn');
                if (yearEl)    { yearEl.value    = '2026'; }
                if (monthEl)   { monthEl.value   = ''; }
                if (quarterEl) { quarterEl.value = ''; }
                if (deptEl)    { deptEl.value    = ''; }
                if (staffValueEl) { staffValueEl.value = '0'; }
                if (staffBtnEl)   { staffBtnEl.textContent = 'All Staff'; }
                populateStaffSelect();
                loadDashboard({ filter_year: 2026 });
            });
        }

        loadDashboard({ filter_year: 2026 });

        var dashStats = document.querySelectorAll('.okr-dash-stat');
        for (var si = 0; si < dashStats.length; si++) {
            (function (card) {
                card.addEventListener('click', function () {
                    var status       = card.getAttribute('data-status')   || '';
                    var statusesAttr = card.getAttribute('data-statuses') || '';
                    var statuses     = statusesAttr ? statusesAttr.split(',') : [];
                    var isOverdue    = card.getAttribute('data-overdue')  === '1';
                    window.location.href = buildListUrl(status, statuses, isOverdue);
                });
            }(dashStats[si]));
        }
    });
}());
