/**
 * SmartPartMan — Floating AI Assistant (revisi UI Fase 8).
 *
 * Widget mengambang yang dirender lewat layout utama. Semua data & konfigurasi
 * dibaca dari elemen JSON yang disertakan server; endpoint memakai sesi & CSRF
 * yang sama dengan aplikasi. Tanpa framework eksternal.
 */
(function () {
    'use strict';

    var WIDGET_ID = 'spAiWidget';
    var CONFIG_ID = 'spAiConfig';

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /** Escape HTML lalu ubah baris baru menjadi <br> (menyesuaikan render server). */
    function escapeText(value) {
        return escapeHtml(value).replace(/\r\n|\r|\n/g, '<br>');
    }

    function nowLabel() {
        var d = new Date();
        var h = String(d.getHours());
        var m = String(d.getMinutes());
        return (h.length < 2 ? '0' + h : h) + ':' + (m.length < 2 ? '0' + m : m);
    }

    function init(config) {
        var root = document.getElementById(WIDGET_ID);
        var fab = document.getElementById('spAiFab');
        var panel = document.getElementById('spAiPanel');
        var closeBtn = document.getElementById('spAiClose');
        var newBtn = document.getElementById('spAiNew');
        var messagesEl = document.getElementById('spAiMessages');
        var suggestionsEl = document.getElementById('spAiSuggestions');
        var form = document.getElementById('spAiForm');
        var input = document.getElementById('spAiInput');
        var sendBtn = document.getElementById('spAiSend');

        if (!root || !fab || !panel || !messagesEl || !form || !input || !sendBtn) {
            return;
        }
        if (root.dataset.spaiInit === '1') {
            return;
        }
        root.dataset.spaiInit = '1';

        var lastFocused = null;

        function isOpen() {
            return panel.hidden === false;
        }

        function scrollBottom() {
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        function open() {
            lastFocused = document.activeElement;
            panel.hidden = false;
            fab.setAttribute('aria-expanded', 'true');
            scrollBottom();
            if (config.canSend) {
                input.focus();
            }
        }

        function close() {
            panel.hidden = true;
            fab.setAttribute('aria-expanded', 'false');
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
        }

        function statusBadge(status) {
            var meta = config.statusLabels ? config.statusLabels[status] : null;
            if (!meta) {
                return '';
            }
            return '<span class="badge text-bg-' + escapeHtml(meta[1]) + ' sp-ai-status-badge">'
                + escapeHtml(meta[0]) + '</span>';
        }

        function messageHtml(message) {
            var isUser = message.role === 'user';
            var avatar = isUser
                ? ''
                : '<div class="sp-ai-bubble-avatar" aria-hidden="true">AI</div>';
            return '<div class="sp-ai-row ' + (isUser ? 'is-user' : 'is-assistant') + '">'
                + '<div class="sp-ai-bubble ' + (isUser ? 'bubble-user' : 'bubble-assistant') + '">'
                + avatar
                + '<div class="sp-ai-bubble-body">'
                + '<div class="sp-ai-meta">'
                + '<span class="sp-ai-sender">' + (isUser ? 'Anda' : 'Asisten') + '</span>'
                + '<span class="sp-ai-time">' + escapeHtml(message.at || nowLabel()) + '</span>'
                + (isUser ? '' : statusBadge(message.status))
                + '</div>'
                + '<div class="sp-ai-text">' + escapeText(message.text || '') + '</div>'
                + '</div></div></div>';
        }

        function renderMessages(list) {
            var messages = Array.isArray(list) ? list : [];
            if (messages.length === 0) {
                messagesEl.innerHTML = '<div class="sp-ai-welcome" id="spAiWelcome">'
                    + '<div class="sp-ai-welcome-avatar" aria-hidden="true">AI</div>'
                    + '<p class="sp-ai-welcome-text mb-0">' + escapeHtml(config.welcome) + '</p></div>';
                return;
            }
            messagesEl.innerHTML = messages.map(messageHtml).join('');
        }

        function appendMessage(message) {
            var welcome = document.getElementById('spAiWelcome');
            if (welcome) {
                welcome.remove();
            }
            messagesEl.insertAdjacentHTML('beforeend', messageHtml(message));
        }

        function loadingNode() {
            var node = document.createElement('div');
            node.className = 'sp-ai-row is-assistant';
            node.setAttribute('data-loading', '1');
            node.innerHTML = '<div class="sp-ai-bubble bubble-assistant">'
                + '<div class="sp-ai-bubble-avatar" aria-hidden="true">AI</div>'
                + '<div class="sp-ai-bubble-body"><div class="sp-ai-text">'
                + '<span class="sp-ai-typing"><span></span><span></span><span></span></span>'
                + ' <span class="sp-ai-typing-label">AI sedang memproses…</span>'
                + '</div></div></div>';
            return node;
        }

        function showSystem(text) {
            var node = document.createElement('div');
            node.className = 'sp-ai-system';
            node.setAttribute('role', 'alert');
            node.innerHTML = '<span class="sp-ai-system-text">' + escapeHtml(text) + '</span>';
            messagesEl.appendChild(node);
            scrollBottom();
        }

        function autoResize() {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 112) + 'px';
        }

        function postForm(url, fields) {
            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams(fields).toString()
            });
        }

        function send() {
            var text = input.value.trim();
            if (!text) {
                return;
            }
            if (!config.canSend) {
                showSystem(config.notice || 'Chatbot belum tersedia.');
                return;
            }

            input.value = '';
            autoResize();
            appendMessage({ role: 'user', text: text, status: 'user', at: nowLabel() });
            var loading = loadingNode();
            messagesEl.appendChild(loading);
            scrollBottom();
            sendBtn.disabled = true;

            postForm(config.apiUrl, { _token: config.token, question: text })
                .then(function (res) {
                    if (!res.ok) {
                        throw new Error('HTTP ' + res.status);
                    }
                    return res.json();
                })
                .then(function (data) {
                    if (data && typeof data === 'object' && data.appended === true) {
                        if (Array.isArray(data.messages)) {
                            renderMessages(data.messages);
                        }
                    }
                    if (!data || data.ok === false) {
                        showSystem((data && data.answer) || 'Gagal memproses permintaan.');
                    }
                })
                .then(null, function () {
                    showSystem('Koneksi gagal. Periksa koneksi internet lalu coba lagi.');
                })
                .then(function () {
                    if (loading.parentNode) {
                        loading.remove();
                    }
                    sendBtn.disabled = false;
                    scrollBottom();
                });
        }

        function startNew() {
            var done = function () { renderMessages([]); };
            if (config.canSend) {
                postForm(config.clearUrl, { _token: config.token })
                    .then(done)
                    .then(done, done);
            } else {
                done();
            }
            input.value = '';
            autoResize();
        }

        fab.addEventListener('click', function () {
            if (isOpen()) {
                close();
            } else {
                open();
            }
        });
        if (closeBtn) {
            closeBtn.addEventListener('click', close);
        }
        if (newBtn) {
            newBtn.addEventListener('click', startNew);
        }
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            send();
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                send();
            }
        });
        input.addEventListener('input', autoResize);
        if (suggestionsEl) {
            suggestionsEl.addEventListener('click', function (event) {
                var chip = event.target.closest('[data-suggestion]');
                if (!chip) {
                    return;
                }
                input.value = chip.getAttribute('data-suggestion') || '';
                autoResize();
                input.focus();
            });
        }
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && isOpen()) {
                close();
            }
        });

        scrollBottom();
    }

    function boot() {
        var configEl = document.getElementById(CONFIG_ID);
        if (!configEl) {
            return;
        }
        var config;
        try {
            config = JSON.parse(configEl.textContent);
        } catch (e) {
            return;
        }
        if (!config || typeof config !== 'object') {
            return;
        }
        init(config);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
