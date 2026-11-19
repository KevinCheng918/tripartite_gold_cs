/**
 * Telegram Chat — 輸入區域（發送文字 + 圖片 + 貼上截圖 + IME 處理）
 */
(function () {
    var T = window.TgChat;
    if (!T) { return; }

    // 後端 ajax-send-image 限制 max:5120（KB）
    var MAX_IMAGE_BYTES = 5120 * 1024;

    // 後端 SendFileRequest::$maxKb 限制 51200（KB），即 Telegram Bot API 的上傳上限
    var MAX_FILE_MB = 50;
    var MAX_FILE_BYTES = MAX_FILE_MB * 1024 * 1024;

    // 輸入框與功能鈕共用同一個字級
    var INPUT_FONT_SIZE = '0.9375rem';

    // 圖示相對文字放大：同樣 font-size 下中文字撐滿 em 方塊，
    // Font Awesome 的圖形只佔約 0.8em，不放大看起來會比文字小一截
    var ICON_FONT_SIZE = '1.15em';

    // 待傳送的附件（截圖與一般檔案）住在傳送視窗裡，見 `stagedFiles`

    /*
     * 關閉自動回覆的 modal 是不是按「確定」關掉的。
     *
     * 需要這個 flag 是因為 `hidden.bs.modal` 兩種情況都會觸發 ——
     * 取消要把勾勾彈回去，確定則不能彈（送出流程自己會管勾勾）。
     */
    var offConfirmed = false;

    // 目前引用的訊息 id，送出後清空
    var quotedId = null;

    /**
     * 在輸入框上方顯示引用預覽
     *
     * @param {number} id     後台的訊息 id
     * @param {string} sender
     * @param {string} text
     */
    T.setQuote = function (id, sender, text) {
        quotedId = id;

        var $bar = $('#tg-quote-bar');
        if (!$bar.length) { return; }

        $bar.html($('<div class="d-flex align-items-center gap-2">')
            .css({ borderLeft: '3px solid #a67c00', padding: '4px 8px', background: 'rgba(0,0,0,0.04)', borderRadius: '4px' })
            .append($('<div class="flex-fill" style="min-width:0;font-size:0.8125rem">')
                .append($('<div style="font-weight:700;color:#a67c00">').text(sender || ''))
                .append($('<div class="text-truncate text-muted">').text(text || '')))
            .append($('<button type="button" class="btn-close flex-shrink-0" style="font-size:0.625rem">')
                .attr('title', T.i18n.action_cancel_quote)
                .on('click', clearQuote))
        ).show();

        var textarea = document.getElementById('tg-reply-text');
        if (textarea) { textarea.focus(); }
    };

    /**
     * 取消引用
     */
    function clearQuote() {
        quotedId = null;
        $('#tg-quote-bar').hide().empty();
    }

    // 點訊息上的引用鈕
    $(document).on('click', '.js-quote-msg', function (e) {
        e.stopPropagation();
        var $btn = $(this);
        T.setQuote(parseInt($btn.data('id'), 10), $btn.data('sender'), $btn.data('text'));
    });

    /**
     * 輸入區上方的功能鈕（圖示 + 文字說明）
     *
     * @param {string} id
     * @param {string} icon  Font Awesome class
     * @param {string} label
     * @returns {string}
     */
    function toolButton(id, icon, label) {
        // 文字對齊輸入框字級，圖示再依 ICON_FONT_SIZE 放大，兩者視覺才等高
        return '<button class="btn btn-outline-secondary d-flex align-items-center" id="' + id + '" type="button" ' +
            'style="font-size:' + INPUT_FONT_SIZE + ';padding:0.25rem 0.625rem;line-height:1.5">' +
            '<i class="fas ' + icon + ' me-1" style="font-size:' + ICON_FONT_SIZE + ';line-height:1"></i>' + label +
            '</button>';
    }

    /**
     * 是否為手機版面。對齊 Bootstrap 的 md 斷點
     *
     * @returns {boolean}
     */
    function isMobileViewport() {
        return window.innerWidth < 768;
    }

    /**
     * 手機版寬度不夠放長提示，只有桌機顯示「可直接貼上截圖」
     *
     * @returns {string}
     */
    function placeholder() {
        if (isMobileViewport()) { return T.i18n.input_placeholder; }

        return T.i18n.input_placeholder_wide || T.i18n.input_placeholder;
    }

    /**
     * 自動回覆勾選框
     *
     * 未設定 Claude 憑證時停用並附上提示 —— 勾了也不會生效，
     * 不如一開始就讓人知道要去「AI 引擎」設定。
     *
     * @returns {string}
     */
    function autoReplyToggleHtml() {
        var available = T.autoReplyAvailable;
        var title = available ? '' : ' title="' + T.escapeHtml(T.i18n.auto_reply_unavailable || '') + '"';

        return '<div class="form-check form-switch ms-auto d-flex align-items-center gap-2 mb-0"' + title + '>' +
            '<input class="form-check-input mt-0" type="checkbox" id="tg-auto-reply"' +
            (T.autoReplyOn ? ' checked' : '') + (available ? '' : ' disabled') +
            ' style="cursor:pointer">' +
            '<label class="form-check-label" for="tg-auto-reply" style="font-size:' + INPUT_FONT_SIZE + ';white-space:nowrap">' +
            T.escapeHtml(T.i18n.auto_reply || '自動回覆') + '</label>' +
            '</div>';
    }

    /**
     * 自動回覆開啟時鎖住所有人工操作
     *
     * 這是刻意的：同一個對話不該同時有機器人和人在回，
     * 客人會收到兩套說法。
     */
    function applyAutoReplyLock() {
        var locked = T.autoReplyOn;
        var textarea = document.getElementById('tg-reply-text');

        if (textarea) {
            textarea.disabled = locked;
            textarea.placeholder = locked ? (T.i18n.auto_reply_placeholder || '') : placeholder();
        }

        ['btn-tg-send', 'btn-tg-image', 'btn-tg-shared-file', 'btn-tg-quick-reply', 'btn-tg-emoji'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.disabled = locked; }
        });

        // 鎖住時把待傳附件清掉，否則解鎖後可能送出早就過時的截圖
        if (locked && stagedFiles.length > 0) {
            stagedFiles = [];
            hideBsModal(document.getElementById('modal-tg-send-file'));
        }

        if (locked && T.closeEmojiPicker) { T.closeEmojiPicker(); }
    }

    /**
     * 勾選框被動到：開啟直接送，關閉要先問意圖
     *
     * 問意圖的理由：關掉的人有兩種完全不同的打算 —— 處理完就該勾回來的，
     * 與這個客戶之後一直人工的。只看「關閉」這個動作分不出來，而猜錯的
     * 其中一邊會把自動回覆的訊息發給不該收到的客人。
     *
     * @param {HTMLInputElement} toggle
     */
    function handleAutoReplyToggle(toggle) {
        // 開啟不問：勾回來沒有「暫時／永久」可言
        if (toggle.checked) {
            submitAutoReply(toggle, true, {});

            return;
        }

        // 自動恢復功能關著時也不問：沒有排程會幫他勾回來，多問一句只是多一次點擊
        if (!T.resume.enabled) {
            submitAutoReply(toggle, false, {});

            return;
        }

        openAutoReplyOffModal(toggle);
    }

    /**
     * 送出自動回覆開關
     *
     * **存檔成功才鎖 UI** —— 失敗要把勾勾彈回去，畫面不能跟資料庫說不一樣。
     *
     * @param {HTMLInputElement} toggle
     * @param {boolean}          enabled
     * @param {Object}           extra   關閉時的 mode / quiet_minutes
     */
    function submitAutoReply(toggle, enabled, extra) {
        toggle.disabled = true;

        var payload = Object.assign(
            { group_id: T.selectedGroupId, enabled: enabled },
            extra || {}
        );

        T.apiFetch('/admin/telegram-chat/ajax-toggle-auto-reply', {
            method: 'POST',
            body: JSON.stringify(payload),
        })
            .then(function (body) {
                T.autoReplyOn = !!body.auto_reply;
                toggle.checked = T.autoReplyOn;
                syncGroupCache(localResumeAt(enabled, extra));
                applyAutoReplyLock();

                // 忽略名單按鈕只在自動回覆開著時出現，標題列要跟著重畫，
                // 否則剛打開開關的人要切走再切回來才看得到那顆按鈕
                if (T.renderHeader) { T.renderHeader(T.selectedGroupId); }
                if (T.renderGroupList) { T.renderGroupList(); }
            })
            .catch(function (body) {
                toggle.checked = !enabled;
                showInputError((body && body.message) || T.i18n.msg.auto_reply_failed);
            })
            .then(function () {
                toggle.disabled = !T.autoReplyAvailable;
            });
    }

    /**
     * 本地先算出預計恢復時間，讓倒數立刻出現
     *
     * 不等下一次清單重載（最多 30 秒）—— 剛關掉的人要馬上看到「約 N 分後
     * 恢復」，否則他分不出自己剛剛選的是暫時還是永久。
     *
     * ⚠ 這只是樂觀顯示。權威值是後端算的 `max(關閉時間, 最後一則訊息時間)`
     * ＋分鐘數，下一次清單重載就會被蓋掉 —— 客人在這之間又說話的話，
     * 後端那個會比這裡晚，而後端才是對的。
     *
     * @param {boolean} enabled
     * @param {Object}  extra
     * @returns {string|null} ISO 8601 時間；開啟或永久關閉時為 null
     */
    function localResumeAt(enabled, extra) {
        var minutes = parseInt((extra || {}).quiet_minutes, 10);

        if (enabled || !(minutes > 0)) {
            return null;
        }

        return new Date(Date.now() + minutes * 60000).toISOString();
    }

    /**
     * 取消勾選時問一句：暫時轉人工，還是永久關閉
     *
     * ⚠ 關掉視窗（右上 ×、取消、按 Esc）**一定要把勾勾彈回去**：使用者取消了
     * 這個決定，開關卻已經視覺上變成關閉 —— 畫面會跟資料庫說不一樣，
     * 而他會以為自己已經關掉了。
     *
     * @param {HTMLInputElement} toggle
     */
    function openAutoReplyOffModal(toggle) {
        var modalEl = document.getElementById('modal-tg-auto-reply-off');

        if (!modalEl) {
            document.body.insertAdjacentHTML('beforeend', autoReplyOffModalHtml());
            modalEl = document.getElementById('modal-tg-auto-reply-off');

            document.getElementById('btn-tg-off-confirm')
                .addEventListener('click', function () { confirmAutoReplyOff(toggle); });

            // 快捷鈕只是把數字填進輸入框，真正送出的值一律讀輸入框
            modalEl.querySelectorAll('.js-tg-off-preset').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    document.getElementById('tg-off-minutes').value = btn.dataset.minutes;
                    updateOffModeUi();
                });
            });

            modalEl.querySelectorAll('input[name="tg-off-mode"]').forEach(function (radio) {
                radio.addEventListener('change', updateOffModeUi);
            });

            /*
             * 沒有按「確定關閉」就關掉視窗 —— 彈回去。
             *
             * 用 flag 區分：按確定時也會觸發 hidden，那時不該彈回去
             * （送出流程自己會管勾勾）。
             */
            modalEl.addEventListener('hidden.bs.modal', function () {
                if (offConfirmed) {
                    offConfirmed = false;

                    return;
                }

                toggle.checked = true;
            });
        }

        offConfirmed = false;
        modalEl.querySelector('#tg-off-mode-temporary').checked = true;
        document.getElementById('tg-off-minutes').value = T.resume.defaultMinutes;
        document.getElementById('tg-off-error').textContent = '';
        updateOffModeUi();
        showBsModal('modal-tg-auto-reply-off');
    }

    /**
     * modal 的 HTML
     *
     * ⚠ 提示文字要寫「對話靜置 N 分鐘」而不是「N 分鐘後」：客人還在聊的時候
     * 兩者行為完全不同（會繼續等，不會中途把 AI 放回來），而填數字的人
     * 預設會理解成固定延遲。
     *
     * @returns {string}
     */
    function autoReplyOffModalHtml() {
        var i18n = T.i18n;
        var presets = T.resume.presets.map(function (minutes) {
            return '<button type="button" class="btn btn-outline-secondary btn-sm js-tg-off-preset" ' +
                'data-minutes="' + minutes + '">' + minutes + '</button>';
        }).join('');

        return '<div class="modal fade" id="modal-tg-auto-reply-off" tabindex="-1">' +
            '<div class="modal-dialog">' +
            '<div class="modal-content">' +
            '<div class="modal-header py-2">' +
            '<h5 class="modal-title" style="font-size:0.9375rem">' +
            T.escapeHtml(i18n.off_title || '要轉成人工回覆嗎？') + '</h5>' +
            '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
            '<div class="modal-body">' +

            '<div class="form-check mb-1">' +
            '<input class="form-check-input" type="radio" name="tg-off-mode" id="tg-off-mode-temporary" checked>' +
            '<label class="form-check-label fw-bold" for="tg-off-mode-temporary">' +
            T.escapeHtml(i18n.off_temporary || '暫時轉人工') + '</label></div>' +

            '<div class="ms-4 mb-3" id="tg-off-temporary-box">' +
            '<div class="d-flex align-items-center gap-2 mb-1">' +
            '<label class="form-label mb-0" for="tg-off-minutes" style="font-size:0.8125rem">' +
            T.escapeHtml(i18n.off_minutes_label || '靜置多久後恢復') + '</label>' +
            '<input type="number" class="form-control form-control-sm" id="tg-off-minutes" ' +
            'style="width:6rem" min="' + T.resume.min + '" max="' + T.resume.max + '" step="1">' +
            '<span class="text-muted" style="font-size:0.8125rem">' +
            T.escapeHtml(i18n.off_minutes_unit || '分鐘') + '</span>' +
            '<div class="btn-group btn-group-sm ms-1">' + presets + '</div>' +
            '</div>' +
            '<small class="text-muted d-block" id="tg-off-temporary-hint"></small>' +
            '</div>' +

            '<div class="form-check mb-1">' +
            '<input class="form-check-input" type="radio" name="tg-off-mode" id="tg-off-mode-permanent">' +
            '<label class="form-check-label fw-bold" for="tg-off-mode-permanent">' +
            T.escapeHtml(i18n.off_permanent || '永久關閉') + '</label></div>' +
            '<small class="text-muted d-block ms-4">' +
            T.escapeHtml(i18n.off_permanent_hint || '') + '</small>' +

            '<div class="text-danger mt-2" id="tg-off-error" style="font-size:0.8125rem"></div>' +
            '</div>' +
            '<div class="modal-footer py-2">' +
            '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">' +
            T.escapeHtml(i18n.off_cancel || '取消') + '</button>' +
            '<button type="button" class="btn btn-primary btn-sm" id="btn-tg-off-confirm">' +
            T.escapeHtml(i18n.off_confirm || '確定關閉') + '</button>' +
            '</div></div></div></div>';
    }

    /**
     * 選了永久就把分鐘數那一區停用，並讓提示句跟著目前填的數字走
     */
    function updateOffModeUi() {
        var isTemporary = document.getElementById('tg-off-mode-temporary').checked;
        var box = document.getElementById('tg-off-temporary-box');
        var minutes = parseInt(document.getElementById('tg-off-minutes').value, 10) || T.resume.defaultMinutes;

        box.style.opacity = isTemporary ? '1' : '0.45';
        box.querySelectorAll('input, button').forEach(function (el) {
            el.disabled = !isTemporary;
        });

        document.getElementById('tg-off-temporary-hint').textContent = (T.i18n.off_temporary_hint || '')
            .replace(':minutes', minutes);
    }

    /**
     * 按下確定：驗分鐘數再送出
     *
     * 前端先驗一次是為了當場給錯誤訊息（modal 不關、數字還在），
     * 後端那層仍然會驗 —— 這裡不是唯一的防線。
     *
     * @param {HTMLInputElement} toggle
     */
    function confirmAutoReplyOff(toggle) {
        var isTemporary = document.getElementById('tg-off-mode-temporary').checked;
        var $error = document.getElementById('tg-off-error');

        if (!isTemporary) {
            offConfirmed = true;
            hideBsModal(document.getElementById('modal-tg-auto-reply-off'));
            submitAutoReply(toggle, false, { mode: T.resume.modePermanent });

            return;
        }

        var minutes = parseInt(document.getElementById('tg-off-minutes').value, 10);

        if (!(minutes > 0)) {
            $error.textContent = T.i18n.msg.quiet_minutes_required || '';

            return;
        }

        if (minutes < T.resume.min || minutes > T.resume.max) {
            $error.textContent = (T.i18n.msg.quiet_minutes_range || '')
                .replace(':min', T.resume.min)
                .replace(':max', T.resume.max);

            return;
        }

        offConfirmed = true;
        hideBsModal(document.getElementById('modal-tg-auto-reply-off'));
        submitAutoReply(toggle, false, {
            mode: T.resume.modeTemporary,
            quiet_minutes: minutes,
        });
    }

    /**
     * 把開關狀態寫回群組快取
     *
     * 不同步的話，切走再切回來會看到舊狀態。
     *
     * @param {string|null} resumeAt 預計恢復時間
     */
    function syncGroupCache(resumeAt) {
        (T.groupsData || []).forEach(function (g) {
            if (g.id !== T.selectedGroupId) { return; }

            g.auto_reply = T.autoReplyOn;
            g.auto_reply_resume_at = resumeAt || null;
        });
    }

    T.showInput = function () {
        var inputArea = document.getElementById('tg-input');
        if (!inputArea) { return; }

        // 切換群組時清空，避免附件或引用誤帶到別的對話
        stagedFiles = [];
        hideBsModal(document.getElementById('modal-tg-send-file'));
        quotedId = null;

        // 自動回覆是每個對話各自獨立的，切群組要重新套用
        var current = (T.groupsData || []).filter(function (g) { return g.id === T.selectedGroupId; })[0];
        T.autoReplyOn = !!(current && current.auto_reply);

        // 輸入區會整個重建，殘留的表情選單會指向已消失的按鈕
        if (T.closeEmojiPicker) { T.closeEmojiPicker(); }

        if (!T.canReply) {
            inputArea.style.display = 'none';
            return;
        }

        inputArea.style.display = 'block';
        inputArea.innerHTML =
            '<div id="tg-quote-bar" class="px-3 pt-2" style="display:none"></div>' +
            // ⚠ 待傳縮圖列已移除（2026-11-20）：附件一律在「傳送圖片」視窗裡
            //   管理，兩套並存會讓「我的圖到底在哪」變成要想一下的問題
            '<div id="tg-upload-progress" class="px-3 pt-2" style="display:none"></div>' +
            '<div id="tg-input-error" class="px-3 pt-2 text-danger" style="display:none;font-size:0.8125rem"></div>' +
            // 功能鈕獨立一列並帶文字說明，輸入框才有整列寬度
            '<div class="d-flex align-items-center flex-wrap gap-2 px-3 pt-2" id="tg-input-tools">' +
            // 不設 accept：圖片與一般檔案都從這個按鈕挑，選完再依 MIME 分流
            '<input type="file" id="tg-image-input" style="display:none">' +
            toolButton('btn-tg-image', 'fa-paperclip', T.i18n.btn_attachment || '檔案') +
            toolButton('btn-tg-shared-file', 'fa-file-alt', T.i18n.btn_file || '文件') +
            toolButton('btn-tg-quick-reply', 'fa-bolt', T.i18n.btn_quick_reply || '快速回覆') +
            autoReplyToggleHtml() +
            '</div>' +
            '<div class="d-flex align-items-center gap-1 px-3 py-2">' +
            // 表情鈕嵌在輸入框內側右邊（Telegram / LINE 的做法），送出鈕留在框外
            '<div class="tg-input-wrap">' +
            '<textarea id="tg-reply-text" class="form-control form-control-sm" placeholder="' + placeholder() + '" rows="1" style="resize:none;max-height:100px;border-radius:1rem;font-size:' + INPUT_FONT_SIZE + '"></textarea>' +
            '<button class="tg-emoji-btn" id="btn-tg-emoji" type="button" title="' + (T.i18n.btn_emoji || '表情') + '"><i class="far fa-smile"></i></button>' +
            '</div>' +
            '<button class="btn btn-primary rounded-circle d-flex align-items-center justify-content-center ms-1" id="btn-tg-send" type="button" style="width:38px;height:38px;flex-shrink:0"><i class="fas fa-paper-plane" style="font-size:0.875rem"></i></button>' +
            '</div>';

        var textarea = document.getElementById('tg-reply-text');
        var sendBtn = document.getElementById('btn-tg-send');
        var imageBtn = document.getElementById('btn-tg-image');
        var imageInput = document.getElementById('tg-image-input');

        sendBtn.addEventListener('click', function () { sendReply(); });
        imageBtn.addEventListener('click', function () { imageInput.click(); });
        imageInput.addEventListener('change', function () {
            if (imageInput.files.length > 0) {
                sendAttachment(imageInput.files[0]);
                imageInput.value = '';
            }
        });

        // 文件區按鈕
        var sfBtn = document.getElementById('btn-tg-shared-file');
        if (sfBtn) {
            sfBtn.addEventListener('click', function () { openSharedFileModal(); });
        }

        // 表情選單按鈕（實作在 emoji.js）
        var emojiBtn = document.getElementById('btn-tg-emoji');
        if (emojiBtn && T.toggleEmojiPicker) {
            emojiBtn.addEventListener('click', function () { T.toggleEmojiPicker(emojiBtn); });
        }

        // 快速回覆按鈕（實作在 quick-reply.js）
        var qrBtn = document.getElementById('btn-tg-quick-reply');
        if (qrBtn && T.openQuickReplyModal) {
            qrBtn.addEventListener('click', function () { T.openQuickReplyModal(); });
        }

        // 自動高度 + typing 通知
        var typingTimer = null;
        textarea.addEventListener('input', function () {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';

            // 節流：3 秒內只發一次 typing
            if (!typingTimer && T.selectedGroupId) {
                T.apiFetch('/admin/telegram-chat/ajax-typing', {
                    method: 'POST',
                    body: JSON.stringify({ group_id: T.selectedGroupId }),
                }).catch(function () {});
                typingTimer = setTimeout(function () { typingTimer = null; }, 3000);
            }
        });

        // Enter 送出（IME 安全）
        var imeActive = false;
        textarea.addEventListener('compositionstart', function () { imeActive = true; });
        textarea.addEventListener('compositionend', function () {
            setTimeout(function () { imeActive = false; }, 50);
        });
        textarea.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || e.shiftKey || imeActive) { return; }

            // 手機的 Enter 是軟鍵盤的換行鍵，攔下來當送出會讓人打不出多行訊息，
            // 而且很容易在句子沒打完時誤送。手機一律只用送出鈕
            if (isMobileViewport()) { return; }

            e.preventDefault();
            sendReply();
            textarea.style.height = '';
        });

        // 自動回覆勾選框
        var autoToggle = document.getElementById('tg-auto-reply');
        if (autoToggle) {
            autoToggle.addEventListener('change', function () { handleAutoReplyToggle(autoToggle); });
        }

        hideUploadProgress();
        applyAutoReplyLock();
    };

    // ===== 貼上截圖 =====

    /**
     * 綁在 document 上，截圖後不必先點輸入框就能直接 Ctrl+V
     */
    document.addEventListener('paste', function (e) {
        // 自動回覆中不接受任何人工輸入，貼上截圖也一樣
        if (!T.canReply || !T.selectedGroupId || T.autoReplyOn) { return; }
        if (!document.getElementById('tg-reply-text')) { return; }

        // 在別的輸入框（搜尋框等）貼上時不攔截
        var target = e.target;
        if (target && target !== document.getElementById('tg-reply-text')) {
            var tag = (target.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || target.isContentEditable) { return; }
        }

        var clipboard = e.clipboardData || window.clipboardData;
        if (!clipboard || !clipboard.items) { return; }

        var images = [];
        for (var i = 0; i < clipboard.items.length; i++) {
            var item = clipboard.items[i];
            if (item.kind !== 'file' || item.type.indexOf('image/') !== 0) { continue; }
            var file = item.getAsFile();
            if (file) { images.push(namedImage(file)); }
        }

        // 純文字貼上：交還給瀏覽器預設行為
        if (!images.length) { return; }

        e.preventDefault();
        images.forEach(addPendingFile);
    });

    // ===== 拖曳截圖進聊天視窗 =====

    // dragenter/dragleave 會在子元素之間反覆觸發，用計數避免遮罩閃爍
    var dragDepth = 0;

    /**
     * 拖的是檔案（而非選取的文字或連結）
     *
     * @param {DragEvent} e
     * @returns {boolean}
     */
    function isFileDrag(e) {
        var types = e.dataTransfer && e.dataTransfer.types;
        if (!types) { return false; }

        // types 在不同瀏覽器是 DOMStringList 或 Array
        return Array.prototype.indexOf.call(types, 'Files') !== -1;
    }

    /**
     * 落在聊天頁範圍內 —— 這層只用來阻止瀏覽器直接開啟檔案
     *
     * @param {DragEvent} e
     * @returns {boolean}
     */
    function inChatPage(e) {
        return !!(e.target && e.target.closest && T.root.contains(e.target));
    }

    /**
     * 真正接受截圖的範圍：右側聊天欄，且已選對話、有回覆權限
     *
     * @param {DragEvent} e
     * @returns {boolean}
     */
    function canDropHere(e) {
        // 同貼上：自動回覆中不接受拖進來的檔案
        if (!T.canReply || !T.selectedGroupId || T.autoReplyOn) { return false; }
        if (!isFileDrag(e) || !inChatPage(e)) { return false; }

        return !!e.target.closest('.app-inner-layout__content');
    }

    document.addEventListener('dragenter', function (e) {
        if (!canDropHere(e)) { return; }
        e.preventDefault();
        dragDepth++;
        toggleDropOverlay(true);
    });

    document.addEventListener('dragover', function (e) {
        // 聊天頁內一律 preventDefault，否則拖歪到左側列表會被瀏覽器當成開啟檔案而離開頁面
        if (!isFileDrag(e) || !inChatPage(e)) { return; }
        e.preventDefault();
        e.dataTransfer.dropEffect = canDropHere(e) ? 'copy' : 'none';
    });

    document.addEventListener('dragleave', function (e) {
        if (!dragDepth) { return; }

        // 拖出瀏覽器視窗時 relatedTarget 為 null，直接收掉遮罩免得卡住
        if (!e.relatedTarget) {
            dragDepth = 0;
            toggleDropOverlay(false);
            return;
        }

        if (!canDropHere(e)) { return; }
        dragDepth = Math.max(0, dragDepth - 1);
        if (!dragDepth) { toggleDropOverlay(false); }
    });

    // 拖曳取消（例如按 Esc）也要收掉遮罩
    document.addEventListener('dragend', function () {
        dragDepth = 0;
        toggleDropOverlay(false);
    });

    document.addEventListener('drop', function (e) {
        if (!isFileDrag(e) || !inChatPage(e)) { return; }

        // 落在聊天頁任何位置都吞掉，不讓瀏覽器開啟檔案
        e.preventDefault();
        dragDepth = 0;
        toggleDropOverlay(false);

        if (!canDropHere(e)) { return; }

        var files = e.dataTransfer.files;
        if (!files || !files.length) { return; }

        // 圖片與一般檔案都收，圖片補檔名是因為拖進來的截圖常叫 blob
        for (var i = 0; i < files.length; i++) {
            addPendingFile(isImageFile(files[i]) ? namedImage(files[i]) : files[i]);
        }
    });

    /**
     * 拖曳中的提示遮罩，蓋在右側聊天欄上
     *
     * @param {boolean} visible
     */
    function toggleDropOverlay(visible) {
        var content = document.querySelector('.app-inner-layout__content');
        if (!content) { return; }

        var overlay = document.getElementById('tg-drop-overlay');
        if (!overlay) {
            if (window.getComputedStyle(content).position === 'static') {
                content.style.position = 'relative';
            }

            overlay = document.createElement('div');
            overlay.id = 'tg-drop-overlay';
            // pointer-events:none —— 讓 dragleave/drop 能穿透到底下元素，否則遮罩會自己吃掉事件
            overlay.style.cssText = 'position:absolute;top:0;right:0;bottom:0;left:0;z-index:10;display:none;' +
                'align-items:center;justify-content:center;pointer-events:none;' +
                'background:rgba(212,175,55,0.12);border:2px dashed #d4af37;border-radius:0.5rem';
            overlay.innerHTML = '<div class="fw-bold" style="color:#a67c00;font-size:1.0625rem">' +
                '<i class="fas fa-paperclip me-2"></i>' + (T.i18n.drop_hint || '放開以加入附件') +
                '</div>';
            content.appendChild(overlay);
        }

        overlay.style.display = visible ? 'flex' : 'none';
    }

    /**
     * 剪貼簿與拖曳進來的圖片常沒有檔名（或叫 blob），補一個帶副檔名的名稱，
     * 後端 putFileAs 才不會存成沒有副檔名的檔案
     */
    function namedImage(file) {
        if (file.name && file.name !== 'blob' && file.name.indexOf('.') > -1) { return file; }

        var ext = (file.type.split('/')[1] || 'png').toLowerCase();
        if (ext === 'jpeg') { ext = 'jpg'; }
        var name = 'screenshot_' + new Date().getTime() + '.' + ext;

        try {
            return new File([file], name, { type: file.type });
        } catch (err) {
            // 舊瀏覽器不支援 File 建構子，退回原檔（後端仍會依 MIME 驗證）
            return file;
        }
    }

    /**
     * 上傳失敗時的訊息：後端回的 JSON 才採用它的 message，
     * 其他（例如 PHP upload_max_filesize 擋下、回傳非 JSON）一律用預設文案
     *
     * @param {*}      error
     * @param {string} [fallback] 沒帶時用圖片的失敗文案
     * @returns {string}
     */
    function errorMessage(error, fallback) {
        if (error && typeof error === 'object' && !(error instanceof Error)) {
            /*
             * ⚠ **422 要先挑 `errors` 裡那一句**，不能直接用 `message`。
             *
             * `message` 是 Laravel 的通用字串（「輸入資料無效」），說不出
             * 到底哪裡不對 —— 傳 .exe 被擋的時候，使用者看到的就是那句，
             * 完全不知道是副檔名的問題。真正的原因在 errors.file[0]
             * （「不支援此檔案類型」「檔案不可超過 50 MB」都走這條）。
             */
            var errors = error.errors;

            if (errors) {
                var first = Object.keys(errors)[0];

                if (first && errors[first] && errors[first].length) {
                    return errors[first][0];
                }
            }

            if (error.message) {
                return error.message;
            }
        }

        return fallback || T.i18n.msg.image_send_failed || '圖片傳送失敗';
    }

    /**
     * @param {File} file
     * @returns {boolean}
     */
    function isImageFile(file) {
        return (file.type || '').indexOf('image/') === 0;
    }

    /**
     * 加入待送區。圖片與一般檔案的大小上限不同，先在前端擋掉超標的，
     * 不必等整個檔案上傳完才收到 422
     *
     * @param {File} file
     */
    function addPendingFile(file) {
        if (isImageFile(file)) {
            if (file.size > MAX_IMAGE_BYTES) {
                showInputError(T.i18n.msg.image_too_large || '圖片超過 5MB，無法傳送');
                return;
            }
        } else if (file.size > MAX_FILE_BYTES) {
            showInputError(fileTooLargeMessage());
            return;
        }

        /*
         * 貼上與拖曳進來的也開傳送視窗（2026-11-20 改版，跟 Telegram 一樣）。
         *
         * 以前這些會堆在輸入框上方的小縮圖列，跟「從按鈕選檔」那條路的
         * 確認視窗是兩套 —— 新視窗本身就是「多張圖 ＋ 一段說明」的容器，
         * 功能完全涵蓋，所以縮圖列整個收掉。
         */
        stagedFiles.push(file);
        showInputError('');
        openSendModal();
    }

    /**
     * 檔案大小顯示成 KB / MB
     *
     * @param {number} bytes
     * @returns {string}
     */
    function formatSize(bytes) {
        if (bytes < 1024 * 1024) { return Math.max(1, Math.round(bytes / 1024)) + ' KB'; }

        return (bytes / 1024 / 1024).toFixed(1) + ' MB';
    }

    /**
     * 傳送中的狀態列：檔名 + 第幾筆 + 進度條
     *
     * 大檔案上傳要好幾秒，沒有回饋使用者會以為當掉而重複點發送。
     *
     * @param {string} filename
     * @param {number} index 從 1 起算
     * @param {number} total
     */
    function showUploadProgress(filename, index, total) {
        var box = document.getElementById('tg-upload-progress');
        if (!box) { return; }

        var label = T.i18n.msg.file_sending || '傳送中';
        var counter = total > 1 ? ' (' + index + '/' + total + ')' : '';

        box.style.display = 'block';
        box.innerHTML =
            '<div class="d-flex align-items-center gap-2 mb-1" style="font-size:0.75rem">' +
            '<i class="fas fa-spinner fa-spin text-muted"></i>' +
            '<span class="text-truncate" style="min-width:0">' + T.escapeHtml(label + counter + '：' + filename) + '</span>' +
            '<span class="ms-auto text-muted flex-shrink-0" id="tg-upload-percent">0%</span>' +
            '</div>' +
            '<div class="progress" style="height:4px">' +
            '<div class="progress-bar" id="tg-upload-bar" role="progressbar" style="width:0%"></div>' +
            '</div>';
    }

    /**
     * @param {number} percent 0–100
     */
    function updateUploadProgress(percent) {
        var bar = document.getElementById('tg-upload-bar');
        var text = document.getElementById('tg-upload-percent');
        var value = Math.max(0, Math.min(100, Math.round(percent)));

        if (bar) { bar.style.width = value + '%'; }
        if (text) { text.textContent = value + '%'; }
    }

    function hideUploadProgress() {
        var box = document.getElementById('tg-upload-progress');
        if (!box) { return; }

        box.style.display = 'none';
        box.innerHTML = '';
    }

    function showInputError(message) {
        var box = document.getElementById('tg-input-error');
        if (!box) { return; }

        if (!message) {
            box.style.display = 'none';
            box.textContent = '';
            return;
        }

        box.textContent = message;
        box.style.display = 'block';
    }

    function sendReply() {
        if (!T.selectedGroupId) { return; }

        // 最後一道防線：按鈕與輸入框雖然已被停用，快速回覆選單等路徑仍可能呼叫到這裡
        if (T.autoReplyOn) { return; }

        /*
         * ⚠ 這裡不再處理附件（2026-11-20 改版）：附件連同它的說明文字
         * 都在傳送視窗裡按「發送」送出，輸入框只管純文字。
         */
        var textarea = document.getElementById('tg-reply-text');
        var sendBtn = document.getElementById('btn-tg-send');
        var content = textarea.value.trim();
        if (!content) { return; }

        textarea.disabled = true;
        if (sendBtn) { sendBtn.disabled = true; sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size:0.875rem"></i>'; }

        T.apiFetch('/admin/telegram-chat/ajax-reply', {
            method: 'POST',
            body: JSON.stringify({
                group_id: T.selectedGroupId,
                content: content,
                reply_to_id: quotedId,
            }),
        })
            .then(function () {
                clearQuote();
                textarea.value = '';
                textarea.style.height = '';
                textarea.disabled = false;
                if (sendBtn) { sendBtn.disabled = false; sendBtn.innerHTML = '<i class="fas fa-paper-plane" style="font-size:0.875rem"></i>'; }
                textarea.focus();
                // 重新載入訊息（不依賴 Pusher）
                T.loadMessages(T.selectedGroupId);
            })
            .catch(function (error) {
                textarea.disabled = false;
                if (sendBtn) { sendBtn.disabled = false; sendBtn.innerHTML = '<i class="fas fa-paper-plane" style="font-size:0.875rem"></i>'; }
                alert(error.message || T.i18n.msg.reply_failed);
            });
    }

    /**
     * 選檔後先開預覽視窗確認，不直接送出 ——
     * 選錯檔案卻已經送到客戶群組是收不回來的
     *
     * @param {File} file
     */
    function sendAttachment(file) {
        if (!T.selectedGroupId) { return; }

        addPendingFile(file);
    }

    /** 等待確認送出的那幾個檔案（傳送視窗裡列的就是它） */
    var stagedFiles = [];

    /**
     * 傳送視窗：一次管好幾張圖，點圖可以編輯
     *
     * 需求方 2026-11-20 指定照 Telegram 那個版面做：圖片本身就是按鈕
     * （點下去進編輯器），右上角浮著換一張與刪除，底下一段共用的說明文字。
     *
     * ⚠ **編輯完不直接送**，一定要退回這裡再看一眼 ——
     * 送到客戶群組是收不回來的。
     */
    function openSendModal() {
        if (!stagedFiles.length) { return; }

        var modalEl = document.getElementById('modal-tg-send-file');
        if (!modalEl) {
            document.body.insertAdjacentHTML('beforeend',
                /*
                 * modal-fullscreen-sm-down：手機撐滿，電腦維持置中視窗
                 * （需求方要兩邊同一套版面）。
                 *
                 * ⚠ **刻意不用 `modal-dialog-scrollable`**：那個 class 會讓
                 * Bootstrap 自己管 modal-body 的捲動，跟 CSS 裡「圖片區吃掉
                 * 剩餘空間」的 flex 版面打架。捲動由 `#tg-send-file-preview`
                 * 自己負責，而且只有多張圖時才會出現。
                 */
                '<div class="modal fade" id="modal-tg-send-file" tabindex="-1">' +
                '<div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">' +
                '<div class="modal-content">' +
                '<div class="modal-header py-2">' +
                '<h5 class="modal-title" id="tg-send-title" style="font-size:0.9375rem"></h5>' +
                '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
                '<div class="modal-body">' +
                '<div class="tg-send-hint" id="tg-send-hint"></div>' +
                '<div id="tg-send-file-preview"></div>' +
                '<label class="form-label" style="font-size:0.8125rem">' + T.escapeHtml(T.i18n.send_file_caption || '說明文字') + '</label>' +
                '<textarea id="tg-send-file-caption" class="form-control" rows="2" maxlength="1024"></textarea>' +
                '</div>' +
                '<div class="modal-footer py-2 justify-content-between">' +
                '<button type="button" class="btn btn-link btn-sm text-decoration-none p-0" id="btn-tg-send-add">' +
                '<i class="fas fa-plus me-1"></i>' + T.escapeHtml(T.i18n.btn_add_more || '新增') + '</button>' +
                '<div class="d-flex gap-2">' +
                '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">' + T.escapeHtml(T.i18n.btn_cancel || '取消') + '</button>' +
                '<button type="button" class="btn btn-primary btn-sm" id="btn-tg-send-file-ok">' +
                '<i class="fas fa-paper-plane me-1"></i>' + T.escapeHtml(T.i18n.btn_send || '發送') + '</button>' +
                '</div></div></div></div></div>');

            modalEl = document.getElementById('modal-tg-send-file');
            document.getElementById('btn-tg-send-file-ok').addEventListener('click', confirmSendFile);
            document.getElementById('btn-tg-send-add').addEventListener('click', pickMoreFiles);

            // 關閉時清掉暫存，避免下次開啟殘留上一批
            modalEl.addEventListener('hidden.bs.modal', function () {
                stagedFiles = [];
                document.getElementById('tg-send-file-preview').innerHTML = '';
                document.getElementById('tg-send-file-caption').value = '';
            });
        }

        renderStagedList();

        // 已經開著的時候（例如按了「新增」）不要重設說明文字
        if (!modalEl.classList.contains('show')) {
            document.getElementById('tg-send-file-caption').value = '';
            showBsModal('modal-tg-send-file');
        }
    }

    /**
     * 「新增」：再挑一個檔案加進來
     *
     * ⚠ 用完就把 input 移掉 —— 留著的話下次選同一個檔案不會觸發 change
     * （值沒變），使用者會以為按了沒反應。
     */
    function pickMoreFiles() {
        var picker = document.createElement('input');
        picker.type = 'file';
        picker.style.display = 'none';

        picker.addEventListener('change', function () {
            var file = picker.files && picker.files[0];
            picker.remove();

            if (!file || !withinLimit(file)) { return; }

            stagedFiles.push(isImageFile(file) ? namedImage(file) : file);
            renderStagedList();
        });

        document.body.appendChild(picker);
        picker.click();
    }

    /**
     * 大小有沒有超過上限
     *
     * @param {File} file
     * @returns {boolean}
     */
    function withinLimit(file) {
        if (isImageFile(file)) {
            if (file.size <= MAX_IMAGE_BYTES) { return true; }

            showModalError(T.i18n.msg.image_too_large || '圖片超過 5MB，無法傳送');

            return false;
        }

        if (file.size <= MAX_FILE_BYTES) { return true; }

        showModalError(fileTooLargeMessage());

        return false;
    }

    /**
     * 畫出待送清單：圖片可以點，其他檔案只給檔名與大小
     *
     * ⚠ pdf、doc 這類沒辦法在這裡預覽內容，硬塞一個框只是浪費空間，
     * 而且它們也沒有東西可編輯。
     */
    function renderStagedList() {
        var box = document.getElementById('tg-send-file-preview');
        if (!box) { return; }

        // 一張圖都沒了就把視窗收掉，不要留一個空視窗給人按發送
        if (!stagedFiles.length) {
            hideBsModal(document.getElementById('modal-tg-send-file'));

            return;
        }

        var hasImage = stagedFiles.some(isImageFile);

        document.getElementById('tg-send-title').textContent = hasImage
            ? (T.i18n.send_image_title || '傳送圖片')
            : (T.i18n.send_file_title || '傳送檔案');

        document.getElementById('tg-send-hint').textContent = hasImage
            ? (T.i18n.edit_image_hint || '')
            : '';

        box.innerHTML = '';
        stagedFiles.forEach(function (file, index) {
            box.appendChild(isImageFile(file) ? imageCard(file, index) : fileCard(file, index));
        });
    }

    /**
     * 一張圖的卡片：圖本身可點（編輯），右上角換一張／刪除
     *
     * @param {File}   file
     * @param {number} index
     * @returns {HTMLElement}
     */
    function imageCard(file, index) {
        var card = document.createElement('div');
        card.className = 'tg-send-item';

        var img = document.createElement('img');
        img.src = URL.createObjectURL(file);
        img.title = T.i18n.btn_edit_image || '編輯圖片';
        // 釋放掉 object URL，不然開幾十張就是幾十份留在記憶體裡
        img.onload = function () { URL.revokeObjectURL(img.src); };
        img.addEventListener('click', function () { editImageAt(index); });

        card.appendChild(img);
        var tools = itemTools(index);
        if (tools) { card.appendChild(tools); }

        return card;
    }

    /**
     * 非圖片的卡片
     *
     * @param {File}   file
     * @param {number} index
     * @returns {HTMLElement}
     */
    function fileCard(file, index) {
        var card = document.createElement('div');
        card.className = 'tg-send-item';

        var inner = document.createElement('div');
        inner.className = 'tg-send-item-file';
        inner.innerHTML =
            '<i class="fas fa-file-alt fa-2x text-muted"></i>' +
            '<div style="min-width:0">' +
            '<div class="text-truncate" style="font-size:0.875rem">' + T.escapeHtml(file.name) + '</div>' +
            '<div class="text-muted" style="font-size:0.75rem">' + T.escapeHtml(formatSize(file.size)) + '</div>' +
            '</div>';

        card.appendChild(inner);
        var tools = itemTools(index);
        if (tools) { card.appendChild(tools); }

        return card;
    }

    /**
     * 右上角的移除鈕
     *
     * ⚠ **只有一張的時候整組不印**（需求方 2026-11-20）：那時「換一張」與
     * 「移除」都等於「關掉視窗重來」—— 旁邊就有取消鈕，多兩顆只是擋住圖。
     *
     * ⚠ **「換一張」整個拿掉了**，多張時也沒有：要換那一張，刪掉再按「新增」
     * 就是同一件事，而且少一顆按鈕少一個要解釋的東西。
     *
     * @param {number} index
     * @returns {HTMLElement|null}
     */
    function itemTools(index) {
        if (stagedFiles.length < 2) { return null; }

        var tools = document.createElement('div');
        tools.className = 'tg-send-item-tools';

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.title = T.i18n.btn_remove_image || '移除';
        remove.innerHTML = '<i class="fas fa-trash"></i>';
        remove.addEventListener('click', function () {
            stagedFiles.splice(index, 1);
            renderStagedList();
        });

        tools.appendChild(remove);

        return tools;
    }

    /**
     * 點圖片：開編輯器，完成後換掉那一張
     *
     * @param {number} index
     */
    function editImageAt(index) {
        var file = stagedFiles[index];
        if (!file) { return; }

        /*
         * ⚠ **不要靜靜 return。** 第一版寫成「編輯器不在就不做事」，
         * 而編輯器剛好因為掛錯全域物件而不存在 —— 結果是點圖片完全沒反應，
         * 沒有任何線索。載不到就要講。
         */
        if (!T.openImageEditor) {
            showModalError(T.i18n.msg.editor_failed || '圖片編輯器開啟失敗');

            return;
        }

        T.openImageEditor(file, function (edited) {
            stagedFiles[index] = edited;
            renderStagedList();
        }, showModalError);
    }

    /**
     * 視窗裡的錯誤訊息（不是 alert）
     *
     * @param {string} message
     */
    function showModalError(message) {
        var hint = document.getElementById('tg-send-hint');
        if (!hint) { return; }

        hint.textContent = message;
        hint.classList.add('text-danger');
    }

    /**
     * 按下傳送：關掉視窗後才開始上傳，進度條顯示在輸入區
     *
     * ⚠ **逐個序列送出而非平行**，客戶端看到的順序才會跟這裡列的一致。
     * ⚠ 說明文字只掛第一個 —— 沿用改版前的規則，也跟 Telegram 一致。
     */
    function confirmSendFile() {
        var queue = stagedFiles.slice();
        if (!queue.length) { return; }

        var caption = $('#tg-send-file-caption').val().trim();
        hideBsModal(document.getElementById('modal-tg-send-file'));

        var sentCount = 0;
        var chain = Promise.resolve();

        queue.forEach(function (file, index) {
            chain = chain.then(function () {
                showUploadProgress(file.name, index + 1, queue.length);

                return uploadAttachment(file, index === 0 ? caption : '', updateUploadProgress)
                    .then(function (result) {
                        sentCount = index + 1;

                        return result;
                    });
            });
        });

        chain
            .then(function () {
                hideUploadProgress();
                T.loadMessages(T.selectedGroupId);
            })
            .catch(function (error) {
                hideUploadProgress();
                showInputError(errorMessage(error, sendFailedMessage(queue[sentCount])));

                /*
                 * 已經送出去的不重送，把失敗那筆與其後沒送的留著 ——
                 * 他可以直接再按一次，而不是重新挑一遍檔案。
                 */
                stagedFiles = queue.slice(sentCount);
                T.loadMessages(T.selectedGroupId);

                if (stagedFiles.length) { openSendModal(); }
            });
    }

    /**
     * 檔案過大的提示（後端訊息帶 :value，前端自己組同一句）
     *
     * @returns {string}
     */
    function fileTooLargeMessage() {
        var template = T.i18n.msg.file_too_large;
        if (!template) { return '檔案不可超過 ' + MAX_FILE_MB + ' MB'; }

        return template.replace(':value', MAX_FILE_MB);
    }

    /**
     * 上傳附件並透過 Bot API 送出
     *
     * 用 XMLHttpRequest 而非 fetch —— fetch 沒有上傳進度事件，
     * 而大檔案要好幾秒，沒有進度條使用者會以為卡住
     *
     * @param {File}     file
     * @param {string}   caption
     * @param {Function} [onProgress] 收到 0–100 的百分比
     * @returns {Promise}
     */
    function uploadAttachment(file, caption, onProgress) {
        var isImage = isImageFile(file);
        var url = isImage
            ? '/admin/telegram-chat/ajax-send-image'
            : '/admin/telegram-chat/ajax-send-file';

        var formData = new FormData();
        formData.append('group_id', T.selectedGroupId);
        formData.append(isImage ? 'image' : 'file', file);
        if (caption) { formData.append('caption', caption); }

        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', url, true);
            xhr.setRequestHeader('X-CSRF-TOKEN', T.csrfToken);
            xhr.setRequestHeader('Accept', 'application/json');

            if (onProgress && xhr.upload) {
                xhr.upload.addEventListener('progress', function (e) {
                    // 來源不明長度時（例如壓縮傳輸）就不更新，維持上一個百分比
                    if (e.lengthComputable) { onProgress(e.loaded / e.total * 100); }
                });
            }

            xhr.addEventListener('load', function () {
                var body = null;
                try { body = JSON.parse(xhr.responseText); } catch (err) { body = null; }

                // 非 JSON 回應（PHP upload_max_filesize 擋下時會回 HTML）交給 errorMessage 用預設文案
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve(body);
                    return;
                }

                reject(body);
            });

            xhr.addEventListener('error', function () { reject(null); });
            xhr.addEventListener('abort', function () { reject(null); });

            xhr.send(formData);
        });
    }

    /**
     * 依失敗的是圖片還是檔案選預設文案
     *
     * @param {File} [file]
     * @returns {string}
     */
    function sendFailedMessage(file) {
        if (file && !isImageFile(file)) {
            return T.i18n.msg.file_send_failed || '檔案傳送失敗';
        }

        return T.i18n.msg.image_send_failed || '圖片傳送失敗';
    }

    // ===== 文件區 Modal =====
    function openSharedFileModal() {
        // 動態建 modal（如果不存在）
        var modalEl = document.getElementById('modal-tg-shared-files');
        if (!modalEl) {
            var html = '<div class="modal fade" id="modal-tg-shared-files" tabindex="-1">' +
                '<div class="modal-dialog modal-dialog-scrollable">' +
                '<div class="modal-content">' +
                '<div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>文件區</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
                '<div class="modal-body">' +
                '<ul class="nav nav-tabs mb-2"><li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#sf-modal-shared">共用</button></li><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#sf-modal-personal">個人</button></li></ul>' +
                '<div class="tab-content"><div class="tab-pane show active" id="sf-modal-shared"><div class="text-center text-muted py-3">載入中...</div></div><div class="tab-pane" id="sf-modal-personal"><div class="text-center text-muted py-3">載入中...</div></div></div>' +
                '</div></div></div></div>';
            document.body.insertAdjacentHTML('beforeend', html);
            modalEl = document.getElementById('modal-tg-shared-files');
        }

        // 載入檔案
        T.apiFetch('/admin/telegram-chat/ajax-shared-files')
            .then(function (data) {
                renderSfModalTab('sf-modal-shared', data.shared || []);
                renderSfModalTab('sf-modal-personal', data.personal || []);
            });

        var modal = new bootstrap.Modal(modalEl);
        modal.show();
    }

    function renderSfModalTab(tabId, folders) {
        var container = document.getElementById(tabId);
        if (!container) return;
        if (!folders.length) {
            container.innerHTML = '<div class="text-center text-muted py-3">無檔案</div>';
            return;
        }
        var html = '';
        folders.forEach(function (f) {
            html += '<div class="fw-bold mb-1" style="font-size:0.875rem"><i class="fas fa-folder text-warning me-1"></i>' + T.escapeHtml(f.folder_name) + '</div>';
            f.files.forEach(function (file) {
                html += '<div class="d-flex justify-content-between align-items-center py-1 ps-3 border-bottom" style="font-size:0.875rem">';
                html += '<span class="text-truncate" style="max-width:70%"><i class="fas fa-file me-1 text-muted"></i>' + T.escapeHtml(file.original_name) + '</span>';
                html += '<button class="btn btn-sm btn-primary js-sf-send-file" data-file-id="' + file.id + '" style="flex-shrink:0"><i class="fas fa-paper-plane me-1"></i>傳送</button>';
                html += '</div>';
            });
            html += '<div class="mb-2"></div>';
        });
        container.innerHTML = html;

        // 綁定傳送按鈕
        container.querySelectorAll('.js-sf-send-file').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var fileId = parseInt(btn.dataset.fileId, 10);
                if (!T.selectedGroupId) { alert('請先選擇對話'); return; }
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                T.apiFetch('/admin/telegram-chat/ajax-send-document', {
                    method: 'POST',
                    body: JSON.stringify({ group_id: T.selectedGroupId, file_id: fileId }),
                }).then(function () {
                    var modalEl = document.getElementById('modal-tg-shared-files');
                    if (modalEl) { bootstrap.Modal.getInstance(modalEl).hide(); }
                    T.loadMessages(T.selectedGroupId);
                }).catch(function (err) {
                    alert(err.message || '傳送失敗');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>傳送';
                });
            });
        });
    }
})();
