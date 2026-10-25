/**
 * 登入頁：送出前補一份最新的 CSRF token
 *
 * `SESSION_LIFETIME` 預設 120 分鐘。登入頁開著不動超過這個時間，session 過期、
 * 表單裡的 `_token` 就失效，按下登入直接吃 419。
 *
 * ⚠ **419 錯誤頁本身解不開這個狀況** —— 那頁重新整理會重送同一份 POST，
 * 帶的還是同一個舊 token，所以永遠 419。使用者只能自己手動回登入頁。
 *
 * 這是三層防護的第一層（主要手段）：
 *   1. 這支 —— 攔一次 submit，先取最新 token 換掉 `_token` 再送
 *   2. `Handler::render()` —— 第一層失敗時把 419 換成「導回 GET 登入頁」
 *   3. `LoginController::ajaxToken()` —— 提供最新 token 的端點
 *
 * ⚠ **為什麼攔 submit 而不是定時刷新**：定時刷新要猜間隔，猜短了浪費請求，
 * 猜長了還是會過期。攔 submit 的時機最準 —— 只在真的要送出時取一次。
 */
(function () {
    'use strict';

    var TIMEOUT_MS = 5000;

    var form = document.getElementById('login-form');

    if (!form) {
        return;
    }

    var url = form.getAttribute('data-token-url');
    var field = form.querySelector('input[name="_token"]');

    if (!url || !field) {
        return;
    }

    /*
     * ⚠ `form.submit()`（JS 方法）依規範**不會觸發 submit 事件**，
     * 所以不會回到這個 handler 形成迴圈。這個旗標只是保險 ——
     * 哪天改用 `requestSubmit()` 就真的會再觸發一次。
     */
    var submitting = false;

    form.addEventListener('submit', function (event) {
        if (submitting) {
            return;
        }

        event.preventDefault();

        fetchToken(function (token) {
            if (token) {
                field.value = token;
            }

            submitting = true;
            form.submit();
        });
    });

    /**
     * 取最新的 token
     *
     * ⚠ **取不到就照原本的 token 送出去**（逾時、斷線、回傳不是 JSON 都算）。
     * 不要因為補 token 失敗就讓使用者連送都送不出去 —— 那比 419 更糟。
     * 失敗的情況交給第二層（`Handler::render()`）接住。
     *
     * @param {function(string|null)} done
     */
    function fetchToken(done) {
        var finished = false;

        var timer = window.setTimeout(function () {
            finish(null);
        }, TIMEOUT_MS);

        function finish(token) {
            if (finished) {
                return;
            }

            finished = true;
            window.clearTimeout(timer);
            done(token);
        }

        var xhr = new XMLHttpRequest();

        xhr.open('GET', url, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');

        xhr.onload = function () {
            if (xhr.status !== 200) {
                finish(null);

                return;
            }

            try {
                var data = JSON.parse(xhr.responseText);

                finish(data && data.token ? data.token : null);
            } catch (e) {
                // 回的不是 JSON（例如被導去別的頁面）—— 照原本的 token 送
                finish(null);
            }
        };

        xhr.onerror = function () {
            finish(null);
        };

        xhr.send();
    }
})();
