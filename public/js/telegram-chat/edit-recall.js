/**
 * Telegram Chat — 改掉／撤回已經送出去的訊息
 *
 * 需求方 2026-11-25：「我客服打出去的話，透過 Telegram 機器人送出去，
 * 這個我能改對話內容嗎」
 *
 * ⚠⚠ **兩個動作的適用範圍不一樣，不要合併成一顆按鈕：**
 *
 * | | 可用對象 | 時限 |
 * |---|---|---|
 * | 編輯 | 只有**人工送出的純文字** | 12 小時 |
 * | 撤回 | 我方送出的**任何**訊息（含圖片、自動回覆） | 48 小時 |
 *
 * ⚠ 按鈕畫不畫得出來由後端的 `can_edit`／`can_recall` 決定
 * （見 `messages.js`），這一支只管按下去之後的事。
 *
 * 詳見 .claude/knowledge/features/outbound-edit-recall.md
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    /**
     * 建一個共用的小視窗（第一次用到才建）
     *
     * ⚠ **不用 `confirm()`／`alert()`** —— 需求方明訂所有回饋走 modal。
     * `layout.js` 與 `input.js` 裡還留著幾個 `alert()`，那是舊程式碼，
     * 不要照抄。
     *
     * @returns {HTMLElement}
     */
    function ensureModal() {
        var el = document.getElementById('modal-tg-edit-sent');
        if (el) { return el; }

        var html = '<div class="modal fade" id="modal-tg-edit-sent" tabindex="-1">' +
            '<div class="modal-dialog">' +
            '<div class="modal-content">' +
            '<div class="modal-header">' +
            '<h5 class="modal-title" id="tg-edit-title"></h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
            '</div>' +
            '<div class="modal-body">' +
            '<div id="tg-edit-body"></div>' +
            '<div id="tg-edit-error" class="text-danger mt-2" style="font-size:0.875rem;display:none"></div>' +
            '</div>' +
            '<div class="modal-footer">' +
            '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' +
            T.escapeHtml(T.i18n.btn_cancel) + '</button>' +
            '<button type="button" class="btn btn-primary" id="tg-edit-ok"></button>' +
            '</div>' +
            '</div></div></div>';

        document.body.insertAdjacentHTML('beforeend', html);

        return document.getElementById('modal-tg-edit-sent');
    }

    /**
     * 顯示錯誤但**不關視窗**
     *
     * ⚠ 關掉的話他剛打好的內容就沒了 —— 而失敗最常見的原因
     * （超過時限、訊息被刪）都是他改了也沒用、但至少要看得到原因。
     *
     * @param {string} text
     * @returns {void}
     */
    function showError(text) {
        var box = document.getElementById('tg-edit-error');
        box.textContent = text;
        box.style.display = '';
    }

    /**
     * 開「改掉這一則」
     *
     * @param {number} id 後台訊息 id
     * @returns {void}
     */
    function openEdit(id) {
        var wrapper = document.querySelector('.chat-box-wrapper[data-msg-id="' + id + '"]');
        var current = wrapper ? (wrapper.dataset.rawContent || '') : '';

        var el = ensureModal();
        document.getElementById('tg-edit-title').textContent = T.i18n.action_edit_sent;
        document.getElementById('tg-edit-error').style.display = 'none';
        document.getElementById('tg-edit-body').innerHTML =
            '<textarea class="form-control" id="tg-edit-text" rows="5" maxlength="4096"></textarea>' +
            '<small class="form-text text-muted d-block mt-1">' +
            T.escapeHtml(T.i18n.edit_hint) + '</small>';
        document.getElementById('tg-edit-text').value = current;

        var ok = document.getElementById('tg-edit-ok');
        ok.className = 'btn btn-primary';
        ok.textContent = T.i18n.btn_save;
        ok.onclick = function () { submitEdit(id, ok); };

        new bootstrap.Modal(el).show();
    }

    /**
     * 送出編輯
     *
     * @param {number} id
     * @param {HTMLElement} ok 確認鈕（送出期間要鎖住）
     * @returns {void}
     */
    function submitEdit(id, ok) {
        var text = document.getElementById('tg-edit-text').value.trim();

        if (!text) {
            showError(T.i18n.msg.edit_content_required);

            return;
        }

        ok.disabled = true;

        T.apiFetch('/admin/telegram-chat/ajax-edit-sent/' + id, {
            method: 'PUT',
            body: JSON.stringify({ content: text })
        })
            .then(function () {
                bootstrap.Modal.getInstance(document.getElementById('modal-tg-edit-sent')).hide();
                T.loadMessages(T.selectedGroupId);
            })
            .catch(function (err) {
                // ⚠ 原因原樣顯示 —— 後端已經把 Telegram 的錯誤翻成人話了
                showError((err && err.message) || T.i18n.msg.edit_failed);
            })
            .then(function () { ok.disabled = false; });
    }

    /**
     * 開「撤回這一則」的二次確認
     *
     * ⚠ **一定要二次確認**：撤回會讓訊息從客人那邊消失，而且回不來。
     *
     * @param {number} id
     * @returns {void}
     */
    function openRecall(id) {
        var el = ensureModal();
        document.getElementById('tg-edit-title').textContent = T.i18n.action_recall_sent;
        document.getElementById('tg-edit-error').style.display = 'none';
        document.getElementById('tg-edit-body').innerHTML =
            '<p class="mb-0">' + T.escapeHtml(T.i18n.recall_confirm) + '</p>' +
            '<small class="form-text text-muted d-block mt-2">' +
            T.escapeHtml(T.i18n.recall_hint) + '</small>';

        var ok = document.getElementById('tg-edit-ok');
        // ⚠ 用紅色：這是不可逆的動作，不要跟「儲存」長得一樣
        ok.className = 'btn btn-danger';
        ok.textContent = T.i18n.action_recall_sent;
        ok.onclick = function () {
            ok.disabled = true;

            T.apiFetch('/admin/telegram-chat/ajax-recall-sent/' + id, { method: 'DELETE' })
                .then(function () {
                    bootstrap.Modal.getInstance(el).hide();
                    T.loadMessages(T.selectedGroupId);
                })
                .catch(function (err) {
                    showError((err && err.message) || T.i18n.msg.recall_failed);
                })
                .then(function () { ok.disabled = false; });
        };

        new bootstrap.Modal(el).show();
    }

    /*
     * ⚠ 綁在 document 上（事件委派）：訊息是每次重畫的，
     * 直接綁在按鈕上的話一重畫就失效。
     */
    document.addEventListener('click', function (e) {
        var edit = e.target.closest ? e.target.closest('.js-edit-msg') : null;
        if (edit) {
            e.preventDefault();
            e.stopPropagation();
            openEdit(edit.dataset.id);

            return;
        }

        var recall = e.target.closest ? e.target.closest('.js-recall-msg') : null;
        if (recall) {
            e.preventDefault();
            e.stopPropagation();
            openRecall(recall.dataset.id);
        }
    });
})();
