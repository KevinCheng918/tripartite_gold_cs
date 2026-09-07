/**
 * Telegram Chat — 輸入區的表情符號選單
 *
 * 與 reactions.js 的選單不同：那個是對「已收到的訊息」按表情回應，
 * 這個是把表情插進輸入框變成訊息內容的一部分。
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    /**
     * 分類的 key 對應語系檔 telegram_chat.emoji_cat_*
     */
    var CATEGORIES = [
        {
            key: 'face',
            emojis: [
                '😀', '😁', '😂', '🤣', '😊', '😇', '🙂', '😉',
                '😍', '🥰', '😘', '😋', '😜', '🤪', '🤗', '🤔',
                '🤨', '😐', '😏', '😒', '🙄', '😔', '😪', '😴',
                '😷', '🤒', '🥵', '🥶', '😵', '🤯', '😎', '🥳',
                '😕', '😟', '😥', '😰', '😭', '😱', '😡', '🤬',
                '😈', '💀', '👻', '🤡', '🙈', '🙉', '🙊', '😺'
            ]
        },
        {
            key: 'gesture',
            emojis: [
                '👍', '👎', '👌', '✌️', '🤞', '🤝', '👏', '🙏',
                '💪', '🫡', '👋', '🤙', '☝️', '👉', '👈', '✍️',
                '🙋', '🤷', '🤦', '💁', '🙆', '🙅', '💅', '👀'
            ]
        },
        {
            key: 'object',
            emojis: [
                '💰', '💵', '💳', '🧾', '📊', '📈', '📉', '🏦',
                '📱', '💻', '⌨️', '🖥️', '🔌', '🔋', '📡', '🛠️',
                '🔑', '🔒', '🔓', '📌', '📎', '📁', '📄', '🗑️',
                '⏰', '📅', '✅', '❌', '⚠️', '❗', '❓', '🔔'
            ]
        },
        {
            key: 'other',
            emojis: [
                '❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '💔',
                '🔥', '⭐', '✨', '🎉', '🎊', '🎁', '🏆', '🥇',
                '💯', '⚡', '☀️', '🌙', '☔', '🌈', '🍀', '🚀',
                '☕', '🍺', '🍜', '🍎', '🐶', '🐱', '🦄', '🕊️'
            ]
        }
    ];

    var picker = null;
    var activeCategory = CATEGORIES[0].key;

    /**
     * 分類標題（語系缺字時退回 key）
     *
     * @param {string} key
     * @returns {string}
     */
    function categoryLabel(key) {
        return (T.i18n && T.i18n['emoji_cat_' + key]) || key;
    }

    /**
     * 建立選單節點
     *
     * @returns {HTMLElement}
     */
    function buildPicker() {
        var el = document.createElement('div');
        el.className = 'tg-emoji-picker';

        var tabs = document.createElement('div');
        tabs.className = 'tg-emoji-picker__tabs';

        var grid = document.createElement('div');
        grid.className = 'tg-emoji-picker__grid';

        CATEGORIES.forEach(function (cat) {
            var tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'tg-emoji-picker__tab' + (cat.key === activeCategory ? ' is-active' : '');
            tab.textContent = categoryLabel(cat.key);
            tab.addEventListener('click', function () {
                activeCategory = cat.key;
                tabs.querySelectorAll('.tg-emoji-picker__tab').forEach(function (t) { t.classList.remove('is-active'); });
                tab.classList.add('is-active');
                renderGrid(grid);
            });
            tabs.appendChild(tab);
        });

        el.appendChild(tabs);
        el.appendChild(grid);
        renderGrid(grid);

        return el;
    }

    /**
     * @param {HTMLElement} grid
     */
    function renderGrid(grid) {
        var cat = CATEGORIES.filter(function (c) { return c.key === activeCategory; })[0];
        grid.innerHTML = '';

        cat.emojis.forEach(function (emoji) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tg-emoji-picker__item';
            btn.textContent = emoji;
            // 不關閉選單，讓客服可以連續挑好幾個
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                insertEmoji(emoji);
            });
            grid.appendChild(btn);
        });
    }

    /**
     * 插到游標位置而不是接在最後面 —— 打到一半想加表情時才不會跑到句尾
     *
     * @param {string} emoji
     */
    function insertEmoji(emoji) {
        var textarea = document.getElementById('tg-reply-text');
        if (!textarea) { return; }

        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;

        // 舊瀏覽器取不到 selection 時退回附加到結尾
        if (typeof start !== 'number' || typeof end !== 'number') {
            textarea.value += emoji;
        } else {
            textarea.value = textarea.value.substring(0, start) + emoji + textarea.value.substring(end);
            var caret = start + emoji.length;
            textarea.setSelectionRange(caret, caret);
        }

        textarea.focus();
        // 觸發 input 事件讓輸入框自動長高的邏輯跟著跑
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /**
     * 定位在按鈕正上方。用 fixed 而非 absolute，
     * 免得被聊天區的 overflow 裁掉（reactions.js 的選單也是這樣處理）
     *
     * @param {HTMLElement} el
     * @param {HTMLElement} anchor
     */
    function positionPicker(el, anchor) {
        var rect = anchor.getBoundingClientRect();
        var width = el.offsetWidth;
        var left = rect.left;

        if (left + width > window.innerWidth - 8) { left = window.innerWidth - width - 8; }
        if (left < 8) { left = 8; }

        var top = rect.top - el.offsetHeight - 6;
        // 上方放不下就改放下方
        if (top < 8) { top = rect.bottom + 6; }

        el.style.left = left + 'px';
        el.style.top = top + 'px';
    }

    function closePicker() {
        if (!picker) { return; }

        picker.remove();
        picker = null;
        document.removeEventListener('mousedown', closeOnOutside);
    }

    function closeOnOutside(e) {
        if (picker && picker.contains(e.target)) { return; }
        if (e.target.closest && e.target.closest('#btn-tg-emoji')) { return; }

        closePicker();
    }

    /**
     * 開關表情選單
     *
     * @param {HTMLElement} anchor 觸發的按鈕
     */
    T.toggleEmojiPicker = function (anchor) {
        if (picker) {
            closePicker();
            return;
        }

        picker = buildPicker();
        document.body.appendChild(picker);
        positionPicker(picker, anchor);

        // 延遲綁定，否則這次點擊會立刻把剛開的選單關掉
        setTimeout(function () {
            document.addEventListener('mousedown', closeOnOutside);
        }, 0);
    };

    // 切換群組會重建輸入區，殘留的選單要收掉
    T.closeEmojiPicker = closePicker;
})();
