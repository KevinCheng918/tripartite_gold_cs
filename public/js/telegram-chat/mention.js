/**
 * 對話視窗打 `@` 叫人
 *
 * 需求方 2026-11-19：「我後台的 Telegram 對話要能夠 @ 人，無論是自己人還是
 * 站台客人，但要留意有些人是沒有 id 的」「可以做成像 Telegram 一樣的介面，
 * 如果有打 @，就自動跳出來讓客服可以選擇」。
 *
 * ⚠⚠ **沒有 username 的人要用 `tg://user?id=` 叫**（text_mention）。
 * 對那種人打 `@名字` 是沒有用的 —— Telegram 不會變成 mention，
 * 對方收不到任何通知。
 *
 * 輸入框裡**不能直接塞 HTML**（客服會看到一串 `<a href=...>`），所以：
 * 插入時顯示 `@權志龍`，**送出前**才由 `T.applyMentions()` 換成連結。
 *
 * 詳見 .claude/knowledge/features/chat-mention.md
 */
(function (window, document) {
    'use strict';

    var T = window.TgChat;

    if (!T) { return; }

    /** 打了 `@` 之後幾毫秒才去查（邊打邊查會把每一個字都送出去） */
    var SEARCH_DELAY = 180;

    /** 目前這一輪的狀態 */
    var state = {
        open: false,
        // 候選清單
        items: [],
        // 鍵盤選到第幾個
        active: 0,
        // `@` 在 textarea 裡的位置
        at: -1,
        timer: null,
    };

    /**
     * 待套用的 text_mention：`@label` → user id
     *
     * ⚠ 只有**沒有 username** 的人會進這裡。送出時才用得到。
     *
     * @type {Array<{label: string, id: number}>}
     */
    var pending = [];

    /**
     * 取語系字串
     *
     * @param {string} key
     * @param {string} fallback
     * @returns {string}
     */
    function t(key, fallback) {
        return (T.i18n && T.i18n[key]) || fallback;
    }

    // ---------------------------------------------------------------
    //  偵測
    // ---------------------------------------------------------------

    /**
     * 游標前面那個「還沒打完的 @ 詞」
     *
     * ⚠ **`@` 前面必須是行首或空白**：不然打 email（`a@b.com`）
     * 或價格（`5@3`）時選單會一直跳出來。
     *
     * @param {HTMLTextAreaElement} textarea
     * @returns {{at: number, keyword: string}|null}
     */
    function tokenAt(textarea) {
        var caret = textarea.selectionStart;
        var text = textarea.value.slice(0, caret);
        var at = text.lastIndexOf('@');

        if (at < 0) { return null; }

        var before = at === 0 ? '' : text.charAt(at - 1);

        if (before !== '' && !/\s/.test(before)) { return null; }

        var keyword = text.slice(at + 1);

        // 打到空白就當這個 @ 結束了（已經選完或放棄了）
        if (/\s/.test(keyword)) { return null; }

        return { at: at, keyword: keyword };
    }

    /**
     * 每次輸入都看一下要不要跳選單
     *
     * @param {HTMLTextAreaElement} textarea
     */
    function onInput(textarea) {
        var token = tokenAt(textarea);

        if (!token) { close(); return; }

        state.at = token.at;

        // 邊打邊查會把每一個字都送出去，壓一下
        if (state.timer) { clearTimeout(state.timer); }

        state.timer = setTimeout(function () {
            search(token.keyword, textarea);
        }, SEARCH_DELAY);
    }

    /**
     * 跟後端要候選
     *
     * @param {string}              keyword
     * @param {HTMLTextAreaElement} textarea
     */
    function search(keyword, textarea) {
        if (!T.selectedGroupId) { return; }

        var url = '/admin/telegram-chat/ajax-mention-candidates?group_id=' + encodeURIComponent(T.selectedGroupId)
            + '&keyword=' + encodeURIComponent(keyword);

        T.apiFetch(url)
            .then(function (data) {
                var items = (data && data.candidates) || [];

                // 查完才發現使用者已經把 @ 刪掉了 —— 不要硬跳出來
                if (!tokenAt(textarea)) { close(); return; }

                if (!items.length) { close(); return; }

                state.items = items;
                state.active = 0;
                render(textarea);
            })
            /*
             * ⚠ 查不到就安靜關掉。這只是個輔助選單，為它跳一個錯誤訊息
             * 會打斷客服正在打的那句話。
             */
            .catch(function () { close(); });
    }

    // ---------------------------------------------------------------
    //  選單
    // ---------------------------------------------------------------

    /**
     * 畫出選單，浮在輸入框上方
     *
     * @param {HTMLTextAreaElement} textarea
     */
    function render(textarea) {
        var box = document.getElementById('tg-mention-box');

        if (!box) {
            box = document.createElement('div');
            box.id = 'tg-mention-box';
            box.className = 'tg-mention-box';
            document.body.appendChild(box);
        }

        box.innerHTML = '';

        state.items.forEach(function (item, index) {
            box.appendChild(row(item, index, textarea));
        });

        var rect = textarea.getBoundingClientRect();
        box.style.left = rect.left + 'px';
        box.style.width = rect.width + 'px';
        // 浮在輸入框「上方」—— 下方是送出鈕，蓋住它很容易誤按
        box.style.bottom = (window.innerHeight - rect.top + 6) + 'px';
        box.style.display = 'block';

        state.open = true;
    }

    /**
     * 一列候選
     *
     * @param {object}              item
     * @param {number}              index
     * @param {HTMLTextAreaElement} textarea
     * @returns {HTMLElement}
     */
    function row(item, index, textarea) {
        var el = document.createElement('button');
        el.type = 'button';
        el.className = 'tg-mention-item' + (index === state.active ? ' tg-mention-active' : '');

        var name = document.createElement('span');
        name.className = 'tg-mention-name';
        name.textContent = item.label;

        var tag = document.createElement('span');
        tag.className = 'tg-mention-tag';
        /*
         * 沒有 username 的人要標出來 —— 客服會好奇「為什麼這個沒有 @」，
         * 而那正是我們用 id 叫他的情況。
         */
        tag.textContent = item.username
            ? '@' + item.username
            : t('mention_no_username', '（用名字叫）');

        el.appendChild(name);

        if (item.is_staff) {
            var staff = document.createElement('span');
            staff.className = 'tg-mention-staff';
            staff.textContent = t('mention_staff', '我們');
            el.appendChild(staff);
        }

        el.appendChild(tag);

        // mousedown 而不是 click：click 會先讓 textarea 失焦，游標位置就沒了
        el.addEventListener('mousedown', function (e) {
            e.preventDefault();
            choose(index, textarea);
        });

        return el;
    }

    /**
     * 關掉選單
     */
    function close() {
        var box = document.getElementById('tg-mention-box');

        if (box) { box.style.display = 'none'; }

        state.open = false;
        state.items = [];
        state.active = 0;
        state.at = -1;
    }

    // ---------------------------------------------------------------
    //  選定
    // ---------------------------------------------------------------

    /**
     * 把選到的人插進輸入框
     *
     * @param {number}              index
     * @param {HTMLTextAreaElement} textarea
     */
    function choose(index, textarea) {
        var item = state.items[index];

        if (!item || state.at < 0) { close(); return; }

        /*
         * 有 username 就插 `@username`（Telegram 自己會解析）；
         * 沒有的話插 `@顯示名稱`，並記下來等送出時換成連結。
         */
        var inserted = item.username ? '@' + item.username : '@' + item.label;

        if (!item.username && item.user_id) {
            pending.push({ label: item.label, id: item.user_id });
        }

        var caret = textarea.selectionStart;
        var before = textarea.value.slice(0, state.at);
        var after = textarea.value.slice(caret);

        // 補一個空白：客服十之八九還要接著打字，沒空白會黏在一起
        textarea.value = before + inserted + ' ' + after;

        var position = (before + inserted + ' ').length;
        textarea.setSelectionRange(position, position);
        textarea.focus();

        close();
    }

    /**
     * 鍵盤：上下選、Enter／Tab 選定、Esc 關掉
     *
     * ⚠ **選單開著時 Enter 不可以送出訊息** —— 那會把一則沒打完的話送到
     * 客戶群組。所以這支要在送出那段 keydown **之前**攔截（`capture`）。
     *
     * @param {KeyboardEvent}       e
     * @param {HTMLTextAreaElement} textarea
     * @returns {boolean} 有沒有吃掉這個按鍵
     */
    function onKeydown(e, textarea) {
        if (!state.open || !state.items.length) { return false; }

        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var step = e.key === 'ArrowDown' ? 1 : -1;
            state.active = (state.active + step + state.items.length) % state.items.length;
            render(textarea);

            return true;
        }

        if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            choose(state.active, textarea);

            return true;
        }

        if (e.key === 'Escape') {
            e.preventDefault();
            close();

            return true;
        }

        return false;
    }

    // ---------------------------------------------------------------
    //  送出前
    // ---------------------------------------------------------------

    /**
     * 把 `@顯示名稱` 換成 Telegram 的 text_mention 連結
     *
     * ⚠ **換不到就原樣送出，絕不擋著不送**：客服可能手動改過那段字
     * （刪了一個字、中間插了標點）。找不到就當他不想 @ 了 ——
     * 為了一個 mention 讓整則訊息發不出去是本末倒置。
     *
     * ⚠ **只換第一個出現的位置，而且用過就丟掉**：同一個名字在訊息裡
     * 出現兩次時，兩個都換會變成兩個 mention。
     *
     * @param {string} text
     * @returns {string}
     */
    T.applyMentions = function (text) {
        if (!pending.length) { return text; }

        var format = '<a href="tg://user?id={id}">{label}</a>';
        var result = text;

        pending.forEach(function (one) {
            var token = '@' + one.label;
            var at = result.indexOf(token);

            if (at < 0) { return; }

            var link = format.replace('{id}', one.id).replace('{label}', escapeHtml(one.label));
            result = result.slice(0, at) + link + result.slice(at + token.length);
        });

        pending = [];

        return result;
    };

    /**
     * 送出失敗時要把待套用的清單留著，否則重送就變成純文字
     *
     * @param {Array} saved
     */
    T.restoreMentions = function (saved) {
        pending = saved || [];
    };

    /**
     * 目前待套用的清單（送出前先備份，失敗時還原）
     *
     * @returns {Array}
     */
    T.pendingMentions = function () {
        return pending.slice();
    };

    /**
     * 換對話就清掉 —— 上一個對話的成員 id 套到別的群組是叫不到人的
     */
    T.clearMentions = function () {
        pending = [];
        close();
    };

    /**
     * 綁在輸入框上（`renderInput()` 重建輸入區後呼叫）
     *
     * @param {HTMLTextAreaElement} textarea
     */
    T.bindMention = function (textarea) {
        if (!textarea) { return; }

        textarea.addEventListener('input', function () { onInput(textarea); });
        textarea.addEventListener('blur', function () {
            // 延遲關閉：點選單那一下會先觸發 blur
            setTimeout(close, 150);
        });

        /*
         * ⚠ `capture: true` —— 一定要排在「Enter 送出」那個 handler 前面，
         * 否則選單開著按 Enter 會把沒打完的話送到客戶群組。
         */
        textarea.addEventListener('keydown', function (e) {
            if (onKeydown(e, textarea)) { e.stopPropagation(); }
        }, true);
    };

    /**
     * @param {string} text
     * @returns {string}
     */
    function escapeHtml(text) {
        return String(text == null ? '' : text).replace(/[&<>]/g, function (char) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[char];
        });
    }
})(window, document);
