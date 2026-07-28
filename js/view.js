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
    // Key Result Progress (read-only list, no edit controls)
    // ---------------------------------------------------------------
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var krListEl = document.getElementById('okr-kr-list');
    if (krListEl) {
        var krList = CFG.keyResults ? CFG.keyResults.slice() : [];
        var atemMap = {};

        var krRowHtml = function (row, index, isSubtask) {
            var statusText = row.status_value;
            var statusClass = 'okr-pill ' + row.pill_class;
            var dates = '<div class="okr-kr-dates">'
                + '<input type="date" value="' + (row.start_date || '') + '" disabled>'
                + '<input type="date" value="' + (row.end_date || '') + '" disabled>'
                + '</div>';

            var atemBadge = '';
            if (row.atem_id) {
                var atem = atemMap[row.atem_id];
                var atemLabel = atem ? escapeHtml(atem.title) : ('ATEM #' + row.atem_id);
                atemBadge = '<div class="okr-kr-atem-badge">'
                    + '<i class="bi bi-link-45deg"></i> '
                    + '<a href="' + CFG.atemViewUrl + '?id=' + row.atem_id + '" target="_blank" rel="noopener">' + atemLabel + '</a>'
                    + '</div>';
            }

            return '<div class="okr-kr-row' + (isSubtask ? ' okr-kr-row--subtask' : '') + '">'
                + '<div class="okr-kr-num">' + index + '</div>'
                + '<div class="okr-kr-body">'
                + '<div class="okr-kr-desc"><span class="okr-kr-desc-label">Action Details</span>'
                + '<textarea class="okr-kr-desc-input" rows="1" readonly>' + escapeHtml(row.description) + '</textarea>'
                + atemBadge + '</div>'
                + '<div><span class="okr-kr-dates-label">Dates</span>' + dates + '</div>'
                + '<div><span class="okr-kr-assignee-label">Created By</span><span class="okr-kr-assignee-name">' + escapeHtml(row.creator_name || '') + '</span></div>'
                + '<div><span class="okr-kr-progress-label">Status</span><span class="' + statusClass + '">' + escapeHtml(statusText) + '</span></div>'
                + '</div>'
                + '</div>';
        };

        function renderKeyResultsReadOnly() {
            var topLevel = krList.filter(function (r) { return !r.parent_id; });
            if (topLevel.length === 0) {
                krListEl.innerHTML = '<div class="okr-kr-empty">No Key Results added yet.</div>';
                return;
            }
            var html = '';
            topLevel.forEach(function (row, i) {
                html += krRowHtml(row, (i + 1), false);
                krList.filter(function (r) { return r.parent_id === row.id; }).forEach(function (sub, j) {
                    html += krRowHtml(sub, (i + 1) + '.' + (j + 1), true);
                });
            });
            krListEl.innerHTML = html;
        }

        if (krList.some(function (r) { return r.atem_id; })) {
            fetch(CFG.atemApiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'list-atems' })
            })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    var items = (res && res.success) ? (res.data || []) : [];
                    items.forEach(function (a) { atemMap[a.id] = a; });
                    renderKeyResultsReadOnly();
                })
                .catch(function () { renderKeyResultsReadOnly(); });
        } else {
            renderKeyResultsReadOnly();
        }
    }

    // ---------------------------------------------------------------
    // Star rating (0-5, half-star steps). Read-only display for everyone;
    // CEO/admin (CFG.canRate) get clickable half-star hit zones. Clicking
    // the currently-set value again clears the rating.
    // ---------------------------------------------------------------
    var starDisplayEl = document.getElementById('okr-star-display');
    var starMetaEl = document.getElementById('okr-star-meta');
    var currentRating = (CFG.card && CFG.card.rating !== null && CFG.card.rating !== undefined) ? Number(CFG.card.rating) : null;
    var starIcons = [];

    function starIconClass(value, i) {
        if (value === null) { return 'bi-star'; }
        if (value >= i) { return 'bi-star-fill'; }
        if (value >= i - 0.5) { return 'bi-star-half'; }
        return 'bi-star';
    }

    // Only repaints the 5 icon classNames - the hit-zone spans themselves are
    // built once in buildStars() and never touched again, so hover/click
    // never destroys the element the mouse is currently over.
    function paintStars(value) {
        for (var i = 0; i < starIcons.length; i++) {
            starIcons[i].className = 'bi ' + starIconClass(value, i + 1);
        }
    }

    function buildStars() {
        if (!starDisplayEl) { return; }
        starDisplayEl.innerHTML = '';
        starIcons = [];
        for (var i = 1; i <= 5; i++) {
            (function (starIndex) {
                var wrap = document.createElement('span');
                wrap.className = 'okr-star';
                var icon = document.createElement('i');
                starIcons.push(icon);
                wrap.appendChild(icon);

                if (CFG.canRate) {
                    ['left', 'right'].forEach(function (half) {
                        var hitValue = half === 'left' ? starIndex - 0.5 : starIndex;
                        var hit = document.createElement('span');
                        hit.className = 'okr-star-hit okr-star-hit-' + half;
                        hit.addEventListener('mouseenter', function () { paintStars(hitValue); });
                        hit.addEventListener('click', function () { submitRating(currentRating === hitValue ? null : hitValue); });
                        wrap.appendChild(hit);
                    });
                }

                starDisplayEl.appendChild(wrap);
            }(i));
        }
        paintStars(currentRating);
    }

    if (starDisplayEl && CFG.canRate) {
        starDisplayEl.addEventListener('mouseleave', function () { paintStars(currentRating); });
    }

    // "2026-07-27 14:05:00" -> "2026-07-27". Keeps the meta line to a date,
    // same as the PHP-rendered version on initial page load.
    function ratingDateOnly(datetime) {
        return datetime ? String(datetime).substring(0, 10) : '';
    }

    function ratingMetaText(value, ratedByName, ratedAt) {
        if (value === null || value === undefined) { return 'Not yet rated'; }
        var pct = Math.round((value / 5) * 100);
        var text = 'Rated ' + value.toFixed(1) + ' / 5 (' + pct + '%)';
        if (ratedByName) { text += ' by ' + ratedByName; }
        if (ratedAt) { text += ' on ' + ratingDateOnly(ratedAt); }
        return text;
    }

    function submitRating(value) {
        var payload = new URLSearchParams();
        payload.set('action', 'rateCard');
        payload.set('id', CFG.card.id);
        payload.set('rating', value === null ? '' : value);

        fetch(CFG.apiUrl, { method: 'POST', body: payload })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    currentRating = value;
                    paintStars(currentRating);
                    if (starMetaEl) {
                        starMetaEl.textContent = ratingMetaText(value, res.rated_by_name, res.rated_at);
                    }
                } else {
                    alert(res.message || 'Failed to save rating.');
                }
            })
            .catch(function () {
                alert('Network error. Please try again.');
            });
    }

    buildStars();

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

    // ---------------------------------------------------------------
    // Force Terminate - the other branch of Suspend > Appeal > Active/Force
    // Terminate, only reachable from Suspended, remark required (same
    // pattern as the Suspend reason box above).
    // ---------------------------------------------------------------
    var forceTerminateBtn = document.getElementById('okr-force-terminate-btn');
    var forceTerminateWrap = document.getElementById('okr-force-terminate-wrap');
    var forceTerminateRemarkInput = document.getElementById('okr-force-terminate-remark');
    var forceTerminateConfirmBtn = document.getElementById('okr-force-terminate-confirm-btn');

    if (forceTerminateBtn && forceTerminateWrap) {
        forceTerminateBtn.addEventListener('click', function () {
            setError('okr-suspend', '');
            forceTerminateWrap.style.display = 'block';
            forceTerminateBtn.style.display = 'none';
        });
    }

    if (forceTerminateConfirmBtn) {
        forceTerminateConfirmBtn.addEventListener('click', function () {
            setError('okr-force-terminate-remark', '');
            var remark = forceTerminateRemarkInput.value.trim();
            if (!remark) {
                setError('okr-force-terminate-remark', 'A remark is required to force terminate an OKR.');
                return;
            }
            if (!confirm('Force terminate this OKR? This cannot be undone.')) { return; }

            var payload = new URLSearchParams();
            payload.set('action', 'forceTerminateCard');
            payload.set('id', CFG.card.id);
            payload.set('remark', remark);

            fetch(CFG.apiUrl, { method: 'POST', body: payload })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        setError('okr-force-terminate-remark', res.message || 'Failed to force terminate OKR.');
                    }
                })
                .catch(function () {
                    setError('okr-force-terminate-remark', 'Network error. Please try again.');
                });
        });
    }

    // ---------------------------------------------------------------
    // Appeal Suspension - Issuer-only, one pending appeal per suspension
    // cycle.
    // ---------------------------------------------------------------
    var appealBtn = document.getElementById('okr-appeal-btn');
    var appealWrap = document.getElementById('okr-appeal-wrap');
    var appealJustificationInput = document.getElementById('okr-appeal-justification');
    var appealConfirmBtn = document.getElementById('okr-appeal-confirm-btn');

    if (appealBtn && appealWrap) {
        appealBtn.addEventListener('click', function () {
            setError('okr-appeal', '');
            appealWrap.style.display = 'block';
            appealBtn.style.display = 'none';
        });
    }

    if (appealConfirmBtn) {
        appealConfirmBtn.addEventListener('click', function () {
            setError('okr-appeal-justification', '');
            var justification = appealJustificationInput.value.trim();
            if (!justification) {
                setError('okr-appeal-justification', 'A justification is required to appeal.');
                return;
            }

            var payload = new URLSearchParams();
            payload.set('action', 'appealSuspension');
            payload.set('id', CFG.card.id);
            payload.set('justification', justification);

            fetch(CFG.apiUrl, { method: 'POST', body: payload })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        setError('okr-appeal-justification', res.message || 'Failed to submit appeal.');
                    }
                })
                .catch(function () {
                    setError('okr-appeal-justification', 'Network error. Please try again.');
                });
        });
    }

    // ---------------------------------------------------------------
    // Chat Box - per-card discussion thread, modeled after ATEM's Chat Box.
    // Own messages can be edited/unsent within a 60s window (also enforced
    // server-side); a 4s poll does a full resync so edits/unsends from other
    // viewers propagate, mirroring ATEM's polling pattern.
    // ---------------------------------------------------------------
    var chatWrapEl = document.getElementById('okr-chat-wrap');
    if (chatWrapEl && CFG.card && CFG.card.id) {
        var CHAT_EDIT_WINDOW_MS = 60000;
        var chatMessages = CFG.chatMessages ? CFG.chatMessages.slice() : [];
        var chatInput = document.getElementById('okr-chat-input');
        var chatSendBtn = document.getElementById('okr-chat-send-btn');
        var chatPollTimer = null;
        var chatVisibilityTimer = null;

        function chatWithinEditWindow(createdAt) {
            var createdMs = new Date(String(createdAt).replace(' ', 'T')).getTime();
            return (Date.now() - createdMs) < CHAT_EDIT_WINDOW_MS;
        }

        function chatBubbleHtml(m) {
            var mine = CFG.currentStaffId && m.sender_staff_id === CFG.currentStaffId;
            var canEdit = mine && chatWithinEditWindow(m.created_at);
            var actions = canEdit
                ? '<div class="okr-chat-bubble-actions">'
                    + '<button type="button" class="okr-chat-edit-btn" data-id="' + m.id + '">Edit</button>'
                    + '<button type="button" class="okr-chat-unsend-btn" data-id="' + m.id + '">Unsend</button>'
                    + '</div>'
                : '';
            return '<div class="okr-chat-bubble' + (mine ? ' okr-chat-bubble-mine' : '') + '" data-message-id="' + m.id + '" data-created-at="' + escapeHtml(m.created_at) + '">'
                + '<div class="okr-chat-bubble-header"><strong>' + escapeHtml(m.sender_name) + '</strong> <span class="okr-chat-bubble-time">' + escapeHtml(m.created_at) + '</span></div>'
                + '<div class="okr-chat-bubble-body" id="okr-chat-body-' + m.id + '">' + escapeHtml(m.message) + '</div>'
                + actions
                + '</div>';
        }

        function renderChat() {
            if (chatMessages.length === 0) {
                chatWrapEl.innerHTML = '<div class="okr-empty-state">No messages yet.</div>';
                return;
            }
            var html = '';
            chatMessages.forEach(function (m) { html += chatBubbleHtml(m); });
            chatWrapEl.innerHTML = html;
            chatWrapEl.scrollTop = chatWrapEl.scrollHeight;
        }

        // Strips expired Edit/Unsend buttons every 5s without a full
        // re-render, so the 60s window closes without waiting for a poll.
        function refreshChatActionVisibility() {
            chatMessages.forEach(function (m) {
                var mine = CFG.currentStaffId && m.sender_staff_id === CFG.currentStaffId;
                var bubble = chatWrapEl.querySelector('[data-message-id="' + m.id + '"]');
                if (!bubble) { return; }
                var actionsEl = bubble.querySelector('.okr-chat-bubble-actions');
                var stillEditable = mine && chatWithinEditWindow(m.created_at);
                if (!stillEditable && actionsEl) { actionsEl.remove(); }
            });
        }

        function loadChatMessages() {
            var body = new URLSearchParams();
            body.set('action', 'listChatMessages');
            body.set('id', CFG.card.id);
            fetch(CFG.apiUrl + '?' + body.toString())
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.success) { return; }
                    var incoming = res.data || [];
                    if (JSON.stringify(incoming) !== JSON.stringify(chatMessages)) {
                        chatMessages = incoming;
                        renderChat();
                    }
                })
                .catch(function () {});
        }

        function sendChatMessage() {
            var message = chatInput.value.trim();
            if (!message) { return; }
            setError('okr-chat', '');

            var body = new URLSearchParams();
            body.set('action', 'sendChatMessage');
            body.set('id', CFG.card.id);
            body.set('message', message);

            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        chatMessages.push({
                            id: res.id,
                            sender_staff_id: res.sender_staff_id,
                            sender_name: res.sender_name,
                            message: res.message,
                            created_at: res.created_at,
                            updated_at: res.updated_at
                        });
                        chatInput.value = '';
                        renderChat();
                    } else {
                        setError('okr-chat', res.message || 'Failed to send message.');
                    }
                })
                .catch(function () {
                    setError('okr-chat', 'Network error. Please try again.');
                });
        }

        function startEditChatMessage(id) {
            var m = chatMessages.filter(function (x) { return x.id === id; })[0];
            if (!m) { return; }
            var bodyEl = document.getElementById('okr-chat-body-' + id);
            if (!bodyEl) { return; }
            bodyEl.innerHTML = '<textarea class="okr-chat-edit-textarea" rows="2">' + escapeHtml(m.message) + '</textarea>'
                + '<div class="okr-chat-edit-actions">'
                + '<button type="button" class="okr-chat-save-btn" data-id="' + id + '">Save</button>'
                + '<button type="button" class="okr-chat-cancel-btn" data-id="' + id + '">Cancel</button>'
                + '</div>';
            bodyEl.querySelector('textarea').focus();
        }

        function cancelEditChatMessage(id) {
            var m = chatMessages.filter(function (x) { return x.id === id; })[0];
            var bodyEl = document.getElementById('okr-chat-body-' + id);
            if (m && bodyEl) { bodyEl.textContent = m.message; }
        }

        function saveEditChatMessage(id) {
            var bodyEl = document.getElementById('okr-chat-body-' + id);
            if (!bodyEl) { return; }
            var textarea = bodyEl.querySelector('textarea');
            var message = textarea ? textarea.value.trim() : '';
            if (!message) { return; }

            var body = new URLSearchParams();
            body.set('action', 'editChatMessage');
            body.set('id', id);
            body.set('message', message);

            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        var m = chatMessages.filter(function (x) { return x.id === id; })[0];
                        if (m) { m.message = res.message; }
                        renderChat();
                    } else {
                        alert(res.message || 'Failed to save message.');
                    }
                })
                .catch(function () {
                    alert('Network error. Please try again.');
                });
        }

        function unsendChatMessage(id) {
            if (!confirm('Unsend this message?')) { return; }
            var body = new URLSearchParams();
            body.set('action', 'unsendChatMessage');
            body.set('id', id);
            fetch(CFG.apiUrl, { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success) {
                        chatMessages = chatMessages.filter(function (x) { return x.id !== id; });
                        renderChat();
                    } else {
                        alert(res.message || 'Failed to unsend message.');
                    }
                })
                .catch(function () {
                    alert('Network error. Please try again.');
                });
        }

        chatWrapEl.addEventListener('click', function (e) {
            var editBtn = e.target.closest('.okr-chat-edit-btn');
            var unsendBtn = e.target.closest('.okr-chat-unsend-btn');
            var saveBtn = e.target.closest('.okr-chat-save-btn');
            var cancelBtn = e.target.closest('.okr-chat-cancel-btn');
            if (editBtn) { startEditChatMessage(parseInt(editBtn.getAttribute('data-id'), 10)); }
            else if (unsendBtn) { unsendChatMessage(parseInt(unsendBtn.getAttribute('data-id'), 10)); }
            else if (saveBtn) { saveEditChatMessage(parseInt(saveBtn.getAttribute('data-id'), 10)); }
            else if (cancelBtn) { cancelEditChatMessage(parseInt(cancelBtn.getAttribute('data-id'), 10)); }
        });

        if (chatSendBtn) {
            chatSendBtn.addEventListener('click', sendChatMessage);
        }
        if (chatInput) {
            chatInput.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    e.preventDefault();
                    sendChatMessage();
                }
            });
        }

        renderChat();
        chatPollTimer = setInterval(loadChatMessages, 4000);
        chatVisibilityTimer = setInterval(refreshChatActionVisibility, 5000);
    }
})();
