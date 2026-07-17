(function () {
    var CFG = window.OKR_VIEW_CONFIG || {};
    var referenceLinks = CFG.referenceLinks ? CFG.referenceLinks.slice() : [];
    var attachments = CFG.attachments ? CFG.attachments.slice() : [];

    function setError(id, msg) {
        var el = document.getElementById(id + '-error');
        if (el) el.textContent = msg || '';
    }

    // ---------------------------------------------------------------
    // "Changes saved" toast — shown once after a redirect from Edit.
    // ---------------------------------------------------------------
    if (window.location.search.indexOf('saved=1') !== -1) {
        var savedToastEl = document.getElementById('okr-saved-toast');
        if (savedToastEl) {
            new bootstrap.Toast(savedToastEl).show();
        }
        var cleanUrl = window.location.pathname + window.location.search.replace(/[?&]saved=1/, '').replace(/^&/, '?');
        window.history.replaceState({}, '', cleanUrl);
    }

    function formatSize(bytes) {
        if (bytes >= 1024 * 1024) { return (bytes / (1024 * 1024)).toFixed(1) + ' MB'; }
        if (bytes >= 1024) { return (bytes / 1024).toFixed(1) + ' KB'; }
        return bytes + ' B';
    }

    // ---------------------------------------------------------------
    // Key Results: display only (set at creation, not editable here).
    // No toolbar, read-only, so it just renders the saved formatting.
    // ---------------------------------------------------------------
    var keyResultsEditorEl = document.getElementById('okr-key-results-editor');
    if (keyResultsEditorEl && typeof Quill !== 'undefined') {
        new Quill('#okr-key-results-editor', { theme: 'snow', readOnly: true, modules: { toolbar: false } });
        keyResultsEditorEl.classList.add('okr-quill-readonly');
    }

    // ---------------------------------------------------------------
    // Reference links (display only; set at creation, not editable here)
    // ---------------------------------------------------------------
    var reflinkListEl = document.getElementById('okr-reflink-list');

    function renderReferenceLinks() {
        if (!reflinkListEl) { return; }
        if (referenceLinks.length === 0) {
            reflinkListEl.innerHTML = '<div class="okr-empty-state">No Reference Link added.</div>';
            return;
        }
        reflinkListEl.innerHTML = '';
        referenceLinks.forEach(function (link) {
            var row = document.createElement('div');
            row.className = 'okr-reflink-row';
            row.innerHTML = '<a href="' + link.url + '" target="_blank" rel="noopener">' + link.name + '</a>';
            reflinkListEl.appendChild(row);
        });
    }

    renderReferenceLinks();

    // ---------------------------------------------------------------
    // Attachments (display only; set at creation, not editable here)
    // ---------------------------------------------------------------
    var fileListEl = document.getElementById('okr-file-list');

    function renderAttachments() {
        if (!fileListEl) { return; }
        if (attachments.length === 0) {
            fileListEl.innerHTML = '<div class="okr-empty-state">No files attached.</div>';
            return;
        }
        fileListEl.innerHTML = '';
        attachments.forEach(function (file) {
            var row = document.createElement('div');
            row.className = 'okr-file-row';
            row.innerHTML = '<a class="okr-file-name" href="okr/download.php?id=' + file.id + '">' + file.original_name + '</a>' +
                '<span class="okr-file-size">' + formatSize(file.size) + '</span>';
            fileListEl.appendChild(row);
        });
    }

    renderAttachments();

    // ---------------------------------------------------------------
    // Suspend / unsuspend
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
            payload.set('id', CFG.card.id);
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
            payload.set('id', CFG.card.id);

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
})();
