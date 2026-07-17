(function () {
    'use strict';

    function renderBackdateStatus(enabled) {
        var el = document.getElementById('okr-backdate-status');
        if (!el) { return; }
        if (enabled) {
            el.textContent = 'Enabled';
            el.style.color = '#198754';
        } else {
            el.textContent = 'Disabled';
            el.style.color = '#6c757d';
        }
    }

    renderBackdateStatus(typeof OKR_BACKDATE_ENABLED !== 'undefined' && OKR_BACKDATE_ENABLED);

    var toggle = document.getElementById('okr-backdate-toggle');
    if (toggle) {
        toggle.addEventListener('change', function () {
            var newVal = toggle.checked ? 1 : 0;
            toggle.disabled = true;

            var payload = new URLSearchParams();
            payload.set('value', newVal);

            fetch(OKR_ADMIN_BACKEND_URL + '?action=toggleBackdate', { method: 'POST', body: payload })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        renderBackdateStatus(res.value === 1);
                    } else {
                        toggle.checked = !toggle.checked;
                    }
                })
                .catch(function () {
                    toggle.checked = !toggle.checked;
                })
                .finally(function () {
                    toggle.disabled = false;
                });
        });
    }
})();
