/**
 * 專案管理（專案基本資料／負責人／系統規章）
 *
 * 原本內嵌在 resources/views/admin/project/index.blade.php 的 `<script>`，
 * 依專案規範（PROMPTS.md「有 Blade：CSS 分 public/css、JS 分 public/js」）
 * 抽出到這裡。
 *
 * 權限、語系與負責人候選名單由 #project-app 的 data-* 帶進來。
 */
$(function () {
    var root = document.getElementById('project-app');
    if (!root) { return; }

    var csrfToken = $('meta[name="csrf-token"]').attr('content');
    /** @type {Object} resources/lang/*\/project.php 整包 */
    var i18n = JSON.parse(root.dataset.i18n);
    /** @type {Object} 專案 id（字串）=> [{id, name}]，**編輯／變更**負責人用（只有參與該專案的人） */
    var ownerCandidates = JSON.parse(root.dataset.ownerCandidates || '{}');
    /** @type {Array} [{id, name}]，**新增專案**用（全部啟用帳號，那一刻還沒有人參與得了） */
    var ownerAssignable = JSON.parse(root.dataset.ownerAssignable || '[]');
    var canAssignOwner = root.dataset.canAssignOwner === '1';

    function showMsg(msg) {
        $('#modal-project-msg-text').text(msg);
        showBsModal('modal-project-msg');
    }

    /** API 回來的錯誤訊息，沒有就退回通用那句 */
    function errorMsg(xhr) {
        return (xhr.responseJSON && xhr.responseJSON.message) || i18n.action_failed;
    }

    /**
     * 存完要重新整理 —— 列表是 Blade 直接輸出的，沒有前端狀態可以更新
     *
     * ⚠ 監聽完要 `removeEventListener`：不拔掉的話同一個訊息視窗每開一次
     * 就多掛一個 reload，第二次存檔會連續觸發好幾次重新整理。
     */
    function reloadAfterMsg(message) {
        showMsg(message);
        document.getElementById('modal-project-msg').addEventListener('hidden.bs.modal', function handler() {
            location.reload();
            this.removeEventListener('hidden.bs.modal', handler);
        });
    }

    /** 關掉來源視窗再跳訊息 —— 兩個 modal 疊著切會把背景遮罩留在畫面上 */
    function closeThenReload(modalId, message) {
        hideBsModal(document.getElementById(modalId));
        setTimeout(function () { reloadAfterMsg(message); }, 400);
    }

    // ===============================================================
    //  專案負責人的下拉（新增／編輯／變更三個視窗共用）
    // ===============================================================

    /**
     * 把候選名單填進一個 `<select>`，並選回現任負責人
     *
     * ⚠ 三個視窗共用這一支（新增專案、編輯專案、變更負責人）——
     * 各寫一份的話，「現任負責人不在名單裡」這個判斷就會有三份實作，
     * 分岔的那一份就是會把負責人清空的那一份。
     *
     * @param {jQuery} $select
     * @param {Array}  candidates    [{id, name}]
     * @param {*}      currentOwnerId 現任負責人 id（沒有就傳空字串／null）
     * @return {boolean} 現任負責人**在不在**這份名單裡（不在就要開警示）
     */
    function fillOwnerSelect($select, candidates, currentOwnerId) {
        $select.empty().append($('<option>').val('').text(i18n.owner_select_none));

        var found = false;

        /*
         * ⚠ 用 `$('<option>').text(...)` 而不是字串拼 HTML：
         * 名字與帳號是使用者填的，拼進 HTML 就是 XSS。
         */
        $.each(candidates, function (idx, candidate) {
            var $option = $('<option>').val(candidate.id).text(candidate.name);

            if (String(candidate.id) === String(currentOwnerId)) {
                $option.prop('selected', true);
                found = true;
            }

            $select.append($option);
        });

        return found;
    }

    /**
     * 「現任負責人不在候選名單裡」的那條紅色警示
     *
     * ⚠ 離職／停用／沒參與這三種人都**不在**候選名單裡，所以下拉會落在
     * 「未指派」—— 這條警示是在講「您看到的不是沒改，是現任負責人已經
     * 不能用了」。少了它，操作者會以為自己什麼都沒動，
     * 按下確認卻把負責人清空。
     *
     * @param {jQuery}  $alert
     * @param {boolean} currentInList
     * @param {string}  ownerName
     * @param {string}  issueText
     */
    function renderOwnerIssue($alert, currentInList, ownerName, issueText) {
        if (!currentInList && ownerName) {
            $alert.removeClass('d-none').text(ownerName + '：' + issueText);
            return;
        }

        $alert.addClass('d-none').text('');
    }

    // ===============================================================
    //  專案基本資料
    // ===============================================================

    $('#btn-add-project').on('click', function () {
        $('#modal-project-title').text(i18n.project_add);
        $('#form-project')[0].reset();
        $('#project-edit-id').val('');
        $('#project-status-group').hide();

        /*
         * ⚠ 新增用的是**全部啟用帳號**那一份名單：專案是存檔那一刻才建立的，
         * 在那之前沒有人勾得了「參與它」—— 用逐專案篩的那一份會只剩管理者。
         * 所以提示也不一樣（`owner_create_hint` 會提醒存檔後要去帳號管理補勾）。
         */
        fillOwnerSelect($('#project-owner'), ownerAssignable, '');
        $('#project-owner-hint').text(i18n.owner_create_hint);
        $('#project-owner-issue').addClass('d-none').text('');

        showBsModal('modal-project');
    });

    $(document).on('click', '.js-edit-project', function () {
        var $btn = $(this);
        var projectId = String($btn.data('id'));

        $('#modal-project-title').text(i18n.project_edit);
        $('#project-edit-id').val(projectId);
        $('#project-name').val($btn.data('name'));
        $('#project-desc').val($btn.data('desc'));
        $('#project-status').val($btn.data('status'));
        $('#project-status-group').show();

        /*
         * 編輯時負責人**也改得動**（需求方 2026-10-30 回報「編輯沒有負責人
         * 可以更新」）。
         *
         * ⚠ 這裡用的是**逐專案篩過**的名單（只有參與這個專案的人）——
         * 專案已經存在了，指派一個沒參與的人會讓他看不到自己負責的專案。
         * 後端 `ProjectOwnerService::guardParticipation()` 會再驗一次。
         */
        var candidates = ownerCandidates[projectId] || [];
        var currentInList = fillOwnerSelect($('#project-owner'), candidates, $btn.data('owner'));

        // 這個專案還沒有人參與時只選得到「未指派」—— 要說出去哪裡處理
        $('#project-owner-hint').text(candidates.length ? i18n.owner_select_hint : i18n.owner_candidates_empty);
        renderOwnerIssue(
            $('#project-owner-issue'),
            currentInList,
            String($btn.data('owner-name') || ''),
            String($btn.data('issue') || '')
        );

        showBsModal('modal-project');
    });

    $('#form-project').on('submit', function (e) {
        e.preventDefault();

        var id = $('#project-edit-id').val();
        var data = {
            name: $('#project-name').val().trim(),
            description: $('#project-desc').val().trim()
        };

        if (!data.name) { return; }

        if (id) {
            data.status = parseInt($('#project-status').val(), 10);
        }

        /*
         * 負責人：**新增與編輯都帶**。
         *
         * ⚠ 這個欄位只有有 `project.assign_owner` 權限時才會存在（Blade 的
         * `@if`），所以 `length` 的判斷同時就是權限判斷 —— 沒有權限就不送，
         * 後端也會再用 `Arr::except()` 拔一次。
         *
         * ⚠ **空字串要送 `null` 不是 0**：`parseInt('', 10)` 是 NaN，
         * JSON 序列化後剛好變 null 而正確，但寫明白一點，免得下次有人改成
         * `|| 0`（那會指派到 id=0）。null 的語意是「清空負責人」，是合法操作。
         */
        if ($('#project-owner').length) {
            var owner = $('#project-owner').val();
            data.owner_id = owner === '' ? null : parseInt(owner, 10);
        }

        $.ajax({
            url: id ? '/admin/project/ajax-update/' + id : '/admin/project/ajax-store',
            method: id ? 'PUT' : 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function (body) {
                closeThenReload('modal-project', body.message || i18n.saved);
            },
            error: function (xhr) {
                showMsg(errorMsg(xhr));
            }
        });
    });

    // ===============================================================
    //  專案負責人（需求方 2026-10-30，只會有一位）
    // ===============================================================

    if (canAssignOwner) {
        $(document).on('click', '.js-owner-change', function () {
            var $btn = $(this);
            var projectId = String($btn.data('project'));
            var currentOwner = $btn.data('owner');
            var ownerName = String($btn.data('owner-name') || '');
            var issueText = String($btn.data('issue') || '');
            var candidates = ownerCandidates[projectId] || [];

            $('#owner-project-id').val(projectId);

            var currentInList = fillOwnerSelect($('#owner-select'), candidates, currentOwner);
            renderOwnerIssue($('#owner-issue-alert'), currentInList, ownerName, issueText);

            // 這個專案還沒有人參與時只選得到「未指派」—— 要說出去哪裡處理
            $('#owner-select-hint').text(candidates.length ? i18n.owner_select_hint : i18n.owner_candidates_empty);

            showBsModal('modal-owner');
        });

        $('#btn-owner-save').on('click', function () {
            var $btn = $(this);
            var selected = $('#owner-select').val();

            // 空字串＝清空負責人，這是合法操作（負責人離職而還沒決定接手的人）
            var data = { owner_id: selected === '' ? null : parseInt(selected, 10) };

            $btn.prop('disabled', true);

            $.ajax({
                url: '/admin/project/ajax-assign-owner/' + $('#owner-project-id').val(),
                method: 'PUT',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                contentType: 'application/json',
                data: JSON.stringify(data),
                success: function (body) {
                    closeThenReload('modal-owner', body.message || i18n.saved);
                    $btn.prop('disabled', false);
                },
                error: function (xhr) {
                    /*
                     * 422「這位同仁尚未參與本專案」會走到這裡。
                     * ⚠ **不要關掉視窗**：使用者要留在這裡重選一個人。
                     */
                    showMsg(errorMsg(xhr));
                    $btn.prop('disabled', false);
                }
            });
        });
    }

    // ===============================================================
    //  系統規章（掛在每個專案底下，需求方 2026-10-30）
    // ===============================================================

    $(document).on('click', '.js-regulation-add', function () {
        $('#modal-regulation-title').text(i18n.regulation_add);
        $('#regulation-id').val('');
        $('#regulation-project-id').val($(this).data('project'));
        $('#regulation-title').val('');
        $('#regulation-content').val('').prop('disabled', false);
        $('#regulation-status').val('1');
        $('#regulation-sort').val('0');
        $('#btn-regulation-save').prop('disabled', false);
        showBsModal('modal-regulation');
    });

    /**
     * 撈一份規章的完整內容
     *
     * ⚠ 列表那一份刻意沒帶 `content`（規章可能上萬字，一次輸出十幾份
     * 會把頁面撐爆），所以檢視與編輯都得多一趟。
     *
     * @param {number|string} id
     * @param {Function} onDone  成功時拿到 regulation 物件
     * @param {Function} onFail  失敗時收尾（通常是把已經開著的視窗關掉）
     */
    function fetchRegulation(id, onDone, onFail) {
        $.ajax({
            url: '/admin/project/ajax-regulation/' + id,
            method: 'GET',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) { onDone(body.regulation); },
            error: function (xhr) {
                if (onFail) { onFail(); }
                showMsg(errorMsg(xhr));
            }
        });
    }

    /*
     * ⚠ 檢視與編輯都是**先把視窗開起來，再把內容填進去**。
     *
     * 原本是「等 API 回來才 showBsModal」—— 按下按鈕後畫面會整整卡一拍
     * 什麼都不動，使用者不知道自己按到了沒，常常連按好幾次。
     * 標題、狀態、排序列表本來就有，先擺上去；只有內容那一格在等。
     */
    $(document).on('click', '.js-regulation-view', function () {
        var $btn = $(this);

        $('#modal-regulation-view-title').text($btn.data('title') || '');
        $('#modal-regulation-view-body').text(i18n.loading);
        showBsModal('modal-regulation-view');

        fetchRegulation($btn.data('id'), function (regulation) {
            $('#modal-regulation-view-title').text(regulation.title);
            // ⚠ 用 text() 不是 html()：規章是使用者輸入的，塞進 HTML 會變成 XSS
            $('#modal-regulation-view-body').text(regulation.content || '');
        }, function () {
            hideBsModal(document.getElementById('modal-regulation-view'));
        });
    });

    $(document).on('click', '.js-regulation-edit', function () {
        var $btn = $(this);
        var $content = $('#regulation-content');
        var $save = $('#btn-regulation-save');

        $('#modal-regulation-title').text(i18n.regulation_edit);
        $('#regulation-id').val($btn.data('id'));
        $('#regulation-project-id').val($btn.data('project'));
        $('#regulation-title').val($btn.data('title'));
        $('#regulation-status').val(String($btn.data('status')));
        $('#regulation-sort').val($btn.data('sort'));

        /*
         * ⚠ 內容還沒到之前要 `disabled`，而且儲存鈕也要關掉：
         * 不關的話使用者可以在空白的框裡打字然後按儲存 ——
         * 那會把整份規章覆蓋成他剛打的那幾個字。
         */
        $content.val('').attr('placeholder', i18n.loading).prop('disabled', true);
        $save.prop('disabled', true);
        showBsModal('modal-regulation');

        fetchRegulation($btn.data('id'), function (regulation) {
            $content.val(regulation.content || '').removeAttr('placeholder').prop('disabled', false);
            $save.prop('disabled', false);
            // 撈回來的才是權威值 —— 萬一列表是舊的（別人剛改過）就以這份為準
            $('#regulation-title').val(regulation.title);
            $('#regulation-status').val(String(regulation.status));
            $('#regulation-sort').val(regulation.sort);
        }, function () {
            $content.removeAttr('placeholder').prop('disabled', false);
            $save.prop('disabled', false);
            hideBsModal(document.getElementById('modal-regulation'));
        });
    });

    $('#btn-regulation-save').on('click', function () {
        var id = $('#regulation-id').val();
        var data = {
            title: $('#regulation-title').val().trim(),
            content: $('#regulation-content').val(),
            status: parseInt($('#regulation-status').val(), 10),
            sort: parseInt($('#regulation-sort').val(), 10) || 0
        };

        if (!data.title || !data.content.trim()) {
            showMsg(i18n.regulation_title_required);
            return;
        }

        // 新增才帶 project_id —— 更新不給改所屬專案（理由見 Service 的註解）
        if (!id) { data.project_id = parseInt($('#regulation-project-id').val(), 10); }

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: id ? '/admin/project/ajax-regulation-update/' + id : '/admin/project/ajax-regulation-store',
            method: id ? 'PUT' : 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function (body) {
                closeThenReload('modal-regulation', body.message || i18n.saved);
                $btn.prop('disabled', false);
            },
            error: function (xhr) {
                showMsg(errorMsg(xhr));
                $btn.prop('disabled', false);
            }
        });
    });

    /*
     * ⚠ 刪除確認走 **modal**，不是 `window.confirm`：
     * 這個系統所有對使用者的回饋一律走 modal —— 原生 confirm 的樣式不受控、
     * 套不到深色模式，而且在部分環境會被瀏覽器擋掉，被擋掉時 `confirm()`
     * 直接回 false，刪除會安靜地不發生。做法照 task-board 既有的確認視窗。
     */
    $(document).on('click', '.js-regulation-delete', function () {
        var $btn = $(this);
        $('#regulation-delete-id').val($btn.data('id'));
        // 一個專案可以有好幾份規章，把標題顯示出來才知道自己要刪的是哪一份
        $('#regulation-delete-title').text($btn.data('title') || '');
        showBsModal('modal-regulation-delete');
    });

    $('#btn-regulation-delete-ok').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: '/admin/project/ajax-regulation-destroy/' + $('#regulation-delete-id').val(),
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function (body) {
                closeThenReload('modal-regulation-delete', body.message || i18n.deleted);
                $btn.prop('disabled', false);
            },
            error: function (xhr) {
                hideBsModal(document.getElementById('modal-regulation-delete'));
                setTimeout(function () { showMsg(errorMsg(xhr)); }, 400);
                $btn.prop('disabled', false);
            }
        });
    });
});
