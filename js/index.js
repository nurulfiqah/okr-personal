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

    function formatRM(n) {
        var val = Math.round(n || 0);
        return 'RM' + val.toLocaleString('en-MY');
    }

    function setText(id, val) {
        var el = document.getElementById(id);
        if (el) { el.textContent = val; }
    }

    function setWidth(id, pct) {
        var el = document.getElementById(id);
        if (el) { el.style.width = pct + '%'; }
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
        setText('dash-incentive', formatRM(data.incentive_total));
        setText('dash-overdue',   formatNumber(data.overdue_count || 0));

        var tbody = document.getElementById('dash-level-body');
        if (tbody && data.by_level) {
            var html = '';
            for (var i = 0; i < data.by_level.length; i++) {
                var l = data.by_level[i];
                var forecast = l.level_id === 1 ? 'RM0' : formatRM(l.forecast);
                html += '<tr>' +
                    '<td style="font-size:12px;font-weight:600;text-align:left;">' + l.label + '</td>' +
                    '<td style="font-size:12px;text-align:left;">' + l.cards + '</td>' +
                    '<td style="font-size:12px;color:#0d6efd;text-align:left;">' + l.complete + '</td>' +
                    '<td style="font-size:12px;color:#198754;text-align:left;">' + l.excellence + '</td>' +
                    '<td style="font-size:12px;color:#dc3545;text-align:left;">' + l.fail + '</td>' +
                    '<td style="font-size:12px;text-align:left;">' + forecast + '</td>' +
                    '</tr>';
            }
            tbody.innerHTML = html || '<tr><td colspan="6" class="text-muted" style="font-size:12px;">No data for the selected period.</td></tr>';
        }

        setWidth('bar-complete',   total > 0 ? Math.round((s.complete   || 0) / total * 100) : 0);
        setWidth('bar-excellence', total > 0 ? Math.round((s.excellence || 0) / total * 100) : 0);
        setWidth('bar-extended',   total > 0 ? Math.round(extended            / total * 100) : 0);
        setWidth('bar-failed',     total > 0 ? Math.round(failed              / total * 100) : 0);
        setText('bar-complete-n',   s.complete   || 0);
        setText('bar-excellence-n', s.excellence || 0);
        setText('bar-extended-n',   extended);
        setText('bar-failed-n',     failed);

        var typeTbody = document.getElementById('dash-type-body');
        if (typeTbody) {
            if (data.by_type && data.by_type.length > 0) {
                var tHtml = '';
                for (var t = 0; t < data.by_type.length; t++) {
                    var type = data.by_type[t];
                    tHtml += '<tr>' +
                        '<td style="font-size:12px;font-weight:600;text-align:left;">' + type.okr_type + '</td>' +
                        '<td style="font-size:12px;color:#0d6efd;text-align:left;">' + (type.complete || 0) + '</td>' +
                        '<td style="font-size:12px;color:#198754;text-align:left;">' + (type.excellence || 0) + '</td>' +
                        '<td style="font-size:12px;color:#fd7e14;text-align:left;">' + (type.extend || 0) + '</td>' +
                        '<td style="font-size:12px;color:#dc3545;text-align:left;">' + (type.suspended || 0) + '</td>' +
                        '<td style="font-size:12px;color:#dc3545;text-align:left;">' + (type.fail || 0) + '</td>' +
                        '</tr>';
                }
                typeTbody.innerHTML = tHtml;
            } else {
                typeTbody.innerHTML = '<tr><td colspan="6" class="text-muted" style="font-size:12px;">No data for the selected period.</td></tr>';
            }
        }

        var deptTbody = document.getElementById('dash-dept-body');
        if (deptTbody) {
            if (data.by_department && data.by_department.length > 0) {
                var dHtml = '';
                for (var d = 0; d < data.by_department.length; d++) {
                    var dept      = data.by_department[d];
                    var dFail     = dept.fail || 0;
                    var dCards    = dept.cards || 0;
                    var dFailRate = dCards > 0 ? (dFail / dCards * 100).toFixed(1) + '%' : '0%';
                    var dForecast = dept.forecast > 0 ? formatRM(dept.forecast) : 'RM0';
                    dHtml += '<tr>' +
                        '<td style="font-size:12px;font-weight:600;text-align:left;">' + dept.dept_name + '</td>' +
                        '<td style="font-size:12px;text-align:left;">' + dCards + '</td>' +
                        '<td style="font-size:12px;color:#0d6efd;text-align:left;">' + (dept.complete || 0) + '</td>' +
                        '<td style="font-size:12px;color:#198754;text-align:left;">' + (dept.excellence || 0) + '</td>' +
                        '<td style="font-size:12px;color:#dc3545;text-align:left;">' + dFail + '</td>' +
                        '<td style="font-size:12px;text-align:left;">' + dFailRate + '</td>' +
                        '<td style="font-size:12px;text-align:left;">' + dForecast + '</td>' +
                        '</tr>';
                }
                deptTbody.innerHTML = dHtml;
            } else {
                deptTbody.innerHTML = '<tr><td colspan="7" class="text-muted" style="font-size:12px;">No data for the selected period.</td></tr>';
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
        setText('dash-incentive', 'err');
        setText('dash-overdue',   'err');
        var tbody = document.getElementById('dash-level-body');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-danger" style="font-size:12px;">' + msg + '</td></tr>';
        }
        var deptTbody = document.getElementById('dash-dept-body');
        if (deptTbody) {
            deptTbody.innerHTML = '<tr><td colspan="7" class="text-danger" style="font-size:12px;">' + msg + '</td></tr>';
        }
    }

    function buildPayload() {
        var payload = {};
        var yearEl    = document.getElementById('dash-filter-year');
        var monthEl   = document.getElementById('dash-filter-month');
        var quarterEl = document.getElementById('dash-filter-quarter');
        var deptEl    = document.getElementById('dash-filter-dept');
        var staffEl   = document.getElementById('dash-filter-staff');

        var year    = yearEl    ? parseInt(yearEl.value,    10) : 0;
        var month   = monthEl   ? parseInt(monthEl.value,   10) : 0;
        var quarter = quarterEl ? parseInt(quarterEl.value, 10) : 0;
        var deptId  = deptEl    ? parseInt(deptEl.value,    10) : 0;
        var staffId = staffEl   ? parseInt(staffEl.value,   10) : 0;

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
        var staffEl   = document.getElementById('dash-filter-staff');

        var parts = [];
        var yearVal    = yearEl    ? yearEl.value    : '';
        var monthVal   = monthEl   ? monthEl.value   : '';
        var quarterVal = quarterEl ? quarterEl.value  : '';
        var deptVal    = deptEl    ? deptEl.value    : '';
        var staffVal   = staffEl   ? staffEl.value   : '';

        if (!yearVal && !monthVal && !quarterVal && !deptVal && !staffVal) { return 'Showing all records'; }

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

        if (staffEl && staffEl.selectedIndex > 0) {
            parts.push(staffEl.options[staffEl.selectedIndex].text);
        }

        return 'Showing: ' + parts.join(', ');
    }

    function loadDashboard(payload) {
        setLoading(true);
        setText('dash-total',     '---');
        setText('dash-active',    '---');
        setText('dash-closed',    '---');
        setText('dash-failed',    '---');
        setText('dash-incentive', '---');
        setText('dash-overdue',   '---');

        var deptTbody = document.getElementById('dash-dept-body');
        if (deptTbody) { deptTbody.innerHTML = '<tr><td colspan="7" class="text-muted" style="font-size:12px;">Loading...</td></tr>'; }

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
        var staffEl = document.getElementById('dash-filter-staff');
        var deptEl = document.getElementById('dash-filter-dept');
        if (!staffEl || !CFG.staff) { return; }

        var deptId = deptEl ? parseInt(deptEl.value, 10) : 0;
        var currentValue = staffEl.value;

        staffEl.innerHTML = '<option value="">All Staff</option>';
        for (var i = 0; i < CFG.staff.length; i++) {
            var s = CFG.staff[i];
            if (deptId > 0 && (!s.deptIds || s.deptIds.indexOf(deptId) === -1)) { continue; }
            var opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.name;
            staffEl.appendChild(opt);
        }
        staffEl.value = currentValue;
        if (staffEl.value !== currentValue) { staffEl.value = ''; }
    }

    document.addEventListener('DOMContentLoaded', function () {
        populateDeptSelect();
        populateStaffSelect();

        var deptFilterEl = document.getElementById('dash-filter-dept');
        if (deptFilterEl) {
            deptFilterEl.addEventListener('change', populateStaffSelect);
        }

        var applyBtn  = document.getElementById('dash-apply-filter');
        var resetBtn  = document.getElementById('dash-reset-filter');
        var monthEl   = document.getElementById('dash-filter-month');
        var quarterEl = document.getElementById('dash-filter-quarter');

        if (monthEl) {
            monthEl.addEventListener('change', function () {
                if (this.value && quarterEl) { quarterEl.value = ''; }
            });
        }
        if (quarterEl) {
            quarterEl.addEventListener('change', function () {
                if (this.value && monthEl) { monthEl.value = ''; }
            });
        }

        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                loadDashboard(buildPayload());
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                var yearEl  = document.getElementById('dash-filter-year');
                var deptEl  = document.getElementById('dash-filter-dept');
                var staffEl = document.getElementById('dash-filter-staff');
                if (yearEl)    { yearEl.value    = '2026'; }
                if (monthEl)   { monthEl.value   = ''; }
                if (quarterEl) { quarterEl.value = ''; }
                if (deptEl)    { deptEl.value    = ''; }
                if (staffEl)   { staffEl.value   = ''; }
                loadDashboard({ filter_year: 2026 });
            });
        }

        loadDashboard({ filter_year: 2026 });
    });
}());
