(function () {
    var root = document.getElementById('telegram-chat-app');
    if (!root) { return; }

    var i18n = JSON.parse(root.dataset.i18n);
    var currentUserId = parseInt(root.dataset.userId, 10);
    var nickname = root.dataset.userNickname;
    var canReply = root.dataset.canReply === '1';
    // 值班客服自動從排班系統指派，不需手動操作
    var wsKey = root.dataset.wsKey;
    var wsHost = root.dataset.wsHost || '127.0.0.1';
    var wsPort = parseInt(root.dataset.wsPort || '6001', 10);
    var wsScheme = root.dataset.wsScheme || 'http';
    var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    var selectedGroupId = null;
    var groupsData = [];

    // ---------------------------------------------------------------
    //  共用工具
    // ---------------------------------------------------------------

    function apiFetch(url, options) {
        options = options || {};
        options.headers = Object.assign({
            'X-CSRF-TOKEN': csrfToken,
            'Content-Type': 'application/json',
            Accept: 'application/json',
        }, options.headers || {});

        return fetch(url, options).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) { throw body; }
                return body;
            });
        });
    }

    // ---------------------------------------------------------------
    //  Pusher 即時訊息
    // ---------------------------------------------------------------

    var pusher = null;
    if (wsKey) {
        try {
            pusher = new Pusher(wsKey, {
                wsHost: wsHost,
                wsPort: wsPort,
                wssPort: wsPort,
                forceTLS: wsScheme === 'https',
                encrypted: wsScheme === 'https',
                disableStats: true,
                enabledTransports: ['ws', 'wss'],
            });
            var channel = pusher.subscribe('telegram-chat');

            // 收到新訊息 / reaction 更新
            channel.bind('telegram.message', function (data) {
                var msg = data.message;
                if (!msg) { return; }

                // reaction 更新
                if (msg.type === 'reaction_update') {
                    if (msg.group_id === selectedGroupId) {
                        // 用 telegram_message_id 找到對應的 bubble
                        var bubbles = document.querySelectorAll('.tg-bubble[data-msg-id]');
                        // 需要用 data API 重新載入以取得最新 reactions
                        loadMessages(selectedGroupId);
                    }
                    return;
                }

                // 一般訊息
                loadGroups();
                if (msg.group_id === selectedGroupId) {
                    appendMessage(msg);
                }
            });

            // 收到告警
            channel.bind('telegram.alert', function (data) {
                showAlert(data);
            });
        } catch (e) {
            console.warn('Pusher 初始化失敗', e);
        }
    }

    // ---------------------------------------------------------------
    //  渲染主框架
    // ---------------------------------------------------------------

    function renderLayout() {
        root.innerHTML =
            '<div class="tg-chat">' +
            '<div class="tg-chat__sidebar">' +
            '<div class="tg-chat__sidebar-header">' + i18n.group_list + '</div>' +
            '<div class="tg-chat__group-list" id="tg-group-list"></div>' +
            '</div>' +
            '<div class="tg-chat__main">' +
            '<div class="tg-chat__header" id="tg-header"></div>' +
            '<div class="tg-chat__messages" id="tg-messages">' +
            '<div class="tg-chat__empty">' + i18n.select_group + '</div>' +
            '</div>' +
            '<div class="tg-chat__input" id="tg-input" style="display:none"></div>' +
            '</div>' +
            '</div>' +
            '<div class="tg-alert-bar" id="tg-alert-bar" style="display:none"></div>';
    }

    // ---------------------------------------------------------------
    //  群組列表
    // ---------------------------------------------------------------

    function loadGroups() {
        apiFetch('/admin/telegram-chat/ajax-groups')
            .then(function (body) {
                groupsData = body;
                renderGroupList(body);
            });
    }

    function renderGroupList(groups) {
        var container = document.getElementById('tg-group-list');
        if (!container) { return; }

        if (groups.length === 0) {
            container.innerHTML = '<div class="tg-chat__empty">' + i18n.no_groups + '</div>';
            return;
        }

        container.innerHTML = groups.map(function (g) {
            var active = g.id === selectedGroupId ? ' tg-group--active' : '';
            var unread = g.unread_count > 0 ? '<span class="tg-unread">' + g.unread_count + '</span>' : '';
            var time = g.last_message_at ? g.last_message_at.substring(11, 16) : '';

            return (
                '<div class="tg-group' + active + '" data-id="' + g.id + '">' +
                '<div class="tg-group__info">' +
                '<div class="tg-group__title">' + g.title + '</div>' +
                '<div class="tg-group__meta">' +
                (g.on_duty_users && g.on_duty_users.length > 0 ? '<span class="tg-group__assigned">' + g.on_duty_users.join('、') + '</span>' : '') +
                '<span class="tg-group__time">' + time + '</span>' +
                '</div>' +
                '</div>' +
                unread +
                '</div>'
            );
        }).join('');

        // 綁定點擊
        container.querySelectorAll('.tg-group').forEach(function (el) {
            el.addEventListener('click', function () {
                selectedGroupId = parseInt(el.dataset.id, 10);
                renderGroupList(groupsData);
                loadMessages(selectedGroupId);
                renderHeader(selectedGroupId);
                showInput();

                // RWD：手機版切換到聊天畫面
                var chat = document.querySelector('.tg-chat');
                if (chat) { chat.classList.add('tg-chat--chatting'); }
            });
        });
    }

    // ---------------------------------------------------------------
    //  聊天標頭
    // ---------------------------------------------------------------

    function renderHeader(groupId) {
        var header = document.getElementById('tg-header');
        var group = groupsData.filter(function (g) { return g.id === groupId; })[0];
        if (!group || !header) { return; }

        header.innerHTML =
            '<button class="tg-header__back" id="btn-tg-back">&larr;</button>' +
            '<div class="tg-header__title">' + group.title + '</div>' +
            '<div class="tg-header__right">' +
            '<span class="tg-header__assigned">' + i18n.assigned_to + '：' + (group.on_duty_users && group.on_duty_users.length > 0 ? group.on_duty_users.join('、') : i18n.unassigned) + '</span>' +
            '</div>';

        // RWD 返回按鈕
        var backBtn = document.getElementById('btn-tg-back');
        if (backBtn) {
            backBtn.addEventListener('click', function () {
                var chat = document.querySelector('.tg-chat');
                if (chat) { chat.classList.remove('tg-chat--chatting'); }
            });
        }
    }

    // ---------------------------------------------------------------
    //  訊息
    // ---------------------------------------------------------------

    function loadMessages(groupId) {
        var container = document.getElementById('tg-messages');
        container.innerHTML = '<div class="tg-chat__empty">Loading...</div>';

        apiFetch('/admin/telegram-chat/ajax-messages?group_id=' + groupId + '&per_page=100')
            .then(function (body) {
                var messages = (body.data || []).reverse(); // API 回傳倒序，反轉為正序
                renderMessages(messages);
            });
    }

    var QUICK_EMOJIS = [
        '👍', '❤️', '😂', '😮', '😢', '🙏',
        '❤️‍🔥', '👌', '😁', '🤗', '🔥', '🤔',
        '👎', '🥰', '👏', '🤯', '🎉', '🤩',
        '🤮', '💩', '🕊️', '🤡', '🫣', '😌',
        '😍', '🐳', '🌚', '🌭', '💯', '🤣',
        '⚡', '🍌', '🏆', '💔', '🤨', '🙂',
        '🍓', '🍾', '💋', '🖕', '😈', '😴',
        '😭', '🤓', '👻', '🤷', '👀', '🎃', '🙈',
        '😇', '😱', '🤝', '✍️', '🫡', '🎅', '🎄',
        '☃️', '🎆', '🤪', '🗿', '🆒', '💘', '🙉',
        '🦄', '🥴', '🙊', '👾', '🤷‍♂️'
    ];

    function buildBubbleContent(m) {
        var mediaHtml = '';
        if (m.media_type === 'photo' && m.media_url) {
            mediaHtml = '<div class="tg-bubble__media"><img src="' + m.media_url + '" alt="photo" loading="lazy"></div>';
        } else if (m.media_type === 'sticker' && m.media_url) {
            mediaHtml = '<div class="tg-bubble__media tg-bubble__sticker"><img src="' + m.media_url + '" alt="sticker" loading="lazy"></div>';
        }

        var textHtml = m.content ? '<div class="tg-bubble__content">' + escapeHtml(m.content) + '</div>' : '';

        var reactionsHtml = buildReactionsHtml(m.reactions, m.id);

        return mediaHtml + textHtml + reactionsHtml;
    }

    function buildReactionsHtml(reactions, messageId) {
        var html = '<div class="tg-reactions" data-msg-id="' + messageId + '">';

        if (reactions && reactions.length > 0) {
            reactions.forEach(function (r) {
                html += '<span class="tg-reaction">' + r.emoji + (r.count > 1 ? '<span class="tg-reaction__count">' + r.count + '</span>' : '') + '</span>';
            });
        }

        // 加號按鈕（新增 reaction）
        if (canReply) {
            html += '<button class="tg-reaction tg-reaction--add" data-msg-id="' + messageId + '" title="React">+</button>';
        }

        html += '</div>';
        return html;
    }

    function renderMessages(messages) {
        var container = document.getElementById('tg-messages');

        if (messages.length === 0) {
            container.innerHTML = '<div class="tg-chat__empty">' + i18n.no_messages + '</div>';
            return;
        }

        container.innerHTML = messages.map(function (m) {
            var isOutbound = m.direction === 2;
            var bubbleCls = isOutbound ? 'tg-bubble--outbound' : 'tg-bubble--inbound';
            var time = m.created_at ? m.created_at.substring(11, 16) : '';

            return (
                '<div class="tg-bubble ' + bubbleCls + '" data-msg-id="' + m.id + '">' +
                '<div class="tg-bubble__sender">' + m.sender_name + '</div>' +
                buildBubbleContent(m) +
                '<div class="tg-bubble__time">' + time + '</div>' +
                '</div>'
            );
        }).join('');

        // 初次載入直接跳底（不動畫）
        requestAnimationFrame(function () {
            container.scrollTop = container.scrollHeight;
        });
        bindReactionButtons();
    }

    function appendMessage(msg) {
        var container = document.getElementById('tg-messages');
        if (!container) { return; }

        // 移除空訊息提示
        var empty = container.querySelector('.tg-chat__empty');
        if (empty) { empty.remove(); }

        var isOutbound = msg.direction === 2;
        var bubbleCls = isOutbound ? 'tg-bubble--outbound' : 'tg-bubble--inbound';
        var time = msg.created_at ? msg.created_at.substring(11, 16) : '';

        var html =
            '<div class="tg-bubble ' + bubbleCls + '" data-msg-id="' + msg.id + '">' +
            '<div class="tg-bubble__sender">' + msg.sender_name + '</div>' +
            buildBubbleContent(msg) +
            '<div class="tg-bubble__time">' + time + '</div>' +
            '</div>';

        container.insertAdjacentHTML('beforeend', html);
        // 新訊息追加用平滑捲動
        container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' });
        bindReactionButtons();
    }

    // ---------------------------------------------------------------
    //  Reaction 互動
    // ---------------------------------------------------------------

    function bindReactionButtons() {
        document.querySelectorAll('.tg-reaction--add').forEach(function (btn) {
            btn.removeEventListener('click', handleReactionAdd);
            btn.addEventListener('click', handleReactionAdd);
        });
    }

    function handleReactionAdd(e) {
        e.stopPropagation();
        var btn = e.currentTarget;
        var msgId = btn.dataset.msgId;

        // 關閉其他已開啟的 picker
        closeReactionPicker();

        // 建立 emoji picker
        var picker = document.createElement('div');
        picker.className = 'tg-reaction-picker';
        picker.dataset.msgId = msgId;

        picker.innerHTML = QUICK_EMOJIS.map(function (emoji) {
            return '<button class="tg-reaction-picker__item" data-emoji="' + emoji + '">' + emoji + '</button>';
        }).join('');

        // 掛到 body 用 fixed 定位，避免跑版
        document.body.appendChild(picker);

        // 計算位置：在按鈕上方顯示
        var rect = btn.getBoundingClientRect();
        var pickerWidth = 280;
        var pickerHeight = Math.min(220, picker.scrollHeight);
        var left = rect.left;
        var top = rect.top - pickerHeight - 4;

        // 超出右邊界
        if (left + pickerWidth > window.innerWidth) {
            left = window.innerWidth - pickerWidth - 8;
        }
        // 超出左邊界
        if (left < 8) { left = 8; }
        // 超出上邊界，改顯示在下方
        if (top < 8) { top = rect.bottom + 4; }

        picker.style.left = left + 'px';
        picker.style.top = top + 'px';

        picker.querySelectorAll('.tg-reaction-picker__item').forEach(function (item) {
            item.addEventListener('click', function (ev) {
                ev.stopPropagation();
                sendReaction(parseInt(msgId, 10), item.dataset.emoji);
                closeReactionPicker();
            });
        });

        // 點其他地方關閉
        setTimeout(function () {
            document.addEventListener('click', closeReactionPicker, { once: true });
        }, 0);
    }

    function closeReactionPicker() {
        var existing = document.querySelector('.tg-reaction-picker');
        if (existing) { existing.remove(); }
    }

    function sendReaction(messageId, emoji) {
        apiFetch('/admin/telegram-chat/ajax-react', {
            method: 'POST',
            body: JSON.stringify({ message_id: messageId, emoji: emoji }),
        })
            .then(function (body) {
                // 更新本地顯示
                updateBubbleReactions(messageId, body.reactions);
            })
            .catch(function () {
                // 靜默失敗
            });
    }

    function updateBubbleReactions(messageId, reactions) {
        var bubble = document.querySelector('.tg-bubble[data-msg-id="' + messageId + '"]');
        if (!bubble) { return; }

        var container = bubble.querySelector('.tg-reactions');
        if (!container) { return; }

        container.innerHTML = '';

        if (reactions && reactions.length > 0) {
            reactions.forEach(function (r) {
                var span = document.createElement('span');
                span.className = 'tg-reaction';
                span.innerHTML = r.emoji + (r.count > 1 ? '<span class="tg-reaction__count">' + r.count + '</span>' : '');
                container.appendChild(span);
            });
        }

        if (canReply) {
            var addBtn = document.createElement('button');
            addBtn.className = 'tg-reaction tg-reaction--add';
            addBtn.dataset.msgId = String(messageId);
            addBtn.title = 'React';
            addBtn.textContent = '+';
            container.appendChild(addBtn);
            addBtn.addEventListener('click', handleReactionAdd);
        }
    }

    // ---------------------------------------------------------------
    //  輸入框
    // ---------------------------------------------------------------

    function showInput() {
        var inputArea = document.getElementById('tg-input');
        if (!inputArea) { return; }

        if (!canReply) {
            inputArea.style.display = 'none';
            return;
        }

        inputArea.style.display = 'flex';
        inputArea.innerHTML =
            '<input type="file" id="tg-image-input" accept="image/*" style="display:none">' +
            '<button class="btn-sm" id="btn-tg-image" title="' + (i18n.btn_image || '傳送圖片') + '">&#128247;</button>' +
            '<textarea id="tg-reply-text" placeholder="' + i18n.input_placeholder + '" rows="1"></textarea>' +
            '<button class="btn-primary" id="btn-tg-send">' + i18n.btn_send + '</button>';

        var textarea = document.getElementById('tg-reply-text');
        var sendBtn = document.getElementById('btn-tg-send');
        var imageBtn = document.getElementById('btn-tg-image');
        var imageInput = document.getElementById('tg-image-input');

        sendBtn.addEventListener('click', function () { sendReply(); });
        imageBtn.addEventListener('click', function () { imageInput.click(); });
        imageInput.addEventListener('change', function () {
            if (imageInput.files.length > 0) {
                sendImage(imageInput.files[0]);
                imageInput.value = '';
            }
        });

        // 輸入框自動高度
        function autoResize() {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
        }
        textarea.addEventListener('input', autoResize);

        // 連按兩次 Enter 送出（Shift+Enter 換行）
        // Enter 一律攔截不換行，500ms 內連按兩次送出
        var lastEnterTime = 0;
        textarea.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') { return; }
            // IME 組字中不處理
            if (e.isComposing || e.keyCode === 229) { return; }
            // Shift+Enter 換行，不攔截
            if (e.shiftKey) { return; }

            e.preventDefault();

            var now = Date.now();
            if (now - lastEnterTime < 500) {
                sendReply();
                lastEnterTime = 0;
                textarea.style.height = '';
            } else {
                lastEnterTime = now;
            }
        });
    }

    function sendReply() {
        var textarea = document.getElementById('tg-reply-text');
        var sendBtn = document.getElementById('btn-tg-send');
        var content = textarea.value.trim();
        if (!content || !selectedGroupId) { return; }

        textarea.disabled = true;
        if (sendBtn) { sendBtn.disabled = true; sendBtn.textContent = '...'; }

        apiFetch('/admin/telegram-chat/ajax-reply', {
            method: 'POST',
            body: JSON.stringify({ group_id: selectedGroupId, content: content }),
        })
            .then(function () {
                textarea.value = '';
                textarea.style.height = '';
                textarea.disabled = false;
                if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = i18n.btn_send; }
                textarea.focus();
            })
            .catch(function (error) {
                textarea.disabled = false;
                if (sendBtn) { sendBtn.disabled = false; sendBtn.textContent = i18n.btn_send; }
                var msg = error.message || i18n.msg.reply_failed;
                alert(msg);
            });
    }

    function sendImage(file) {
        if (!selectedGroupId) { return; }

        var formData = new FormData();
        formData.append('group_id', selectedGroupId);
        formData.append('image', file);

        var caption = document.getElementById('tg-reply-text').value.trim();
        if (caption) {
            formData.append('caption', caption);
        }

        fetch('/admin/telegram-chat/ajax-send-image', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                Accept: 'application/json',
            },
            body: formData,
        })
            .then(function (response) {
                return response.json().then(function (body) {
                    if (!response.ok) { throw body; }
                    return body;
                });
            })
            .then(function () {
                var textarea = document.getElementById('tg-reply-text');
                if (textarea) {
                    textarea.value = '';
                    textarea.focus();
                }
            })
            .catch(function (error) {
                var msg = error.message || (i18n.msg ? i18n.msg.reply_failed : 'Failed');
                alert(msg);
            });
    }

    // ---------------------------------------------------------------
    //  告警
    // ---------------------------------------------------------------

    function showAlert(data) {
        var bar = document.getElementById('tg-alert-bar');
        if (!bar) { return; }

        bar.style.display = 'block';
        bar.innerHTML =
            '<span class="tg-alert__icon">⚠</span>' +
            '<span>' + data.groupTitle + ' — ' +
            i18n.alert_unreplied.replace(':minutes', data.minutes) +
            '</span>';

        // 5 秒後自動隱藏
        setTimeout(function () {
            bar.style.display = 'none';
        }, 5000);
    }

    // ---------------------------------------------------------------
    //  工具
    // ---------------------------------------------------------------

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ---------------------------------------------------------------
    //  初始化
    // ---------------------------------------------------------------

    renderLayout();
    loadGroups();

    // Fallback polling（每 30 秒刷新群組列表）
    setInterval(loadGroups, 30000);
})();
