<?php

/**
 * 專案管理
 *
 * ⚠ 這個檔是 2026-10-30 做系統規章時才建的 —— 專案頁原本的文字是寫死在
 * Blade 裡的。既有那些沒有一起搬過來（不在這次範圍），新加的都放這裡。
 */

return [
    /*
     * 以下幾句原本寫死在 Blade 的 `<script>` 裡。JS 依規範抽到
     * `public/js/project.js` 之後那邊查不到 `trans()`，所以收進語系檔，
     * 由 `#project-app` 的 `data-i18n` 帶過去。
     */
    'project_add'   => '新增專案',
    'project_edit'  => '編輯專案',
    'saved'         => '已儲存',
    'deleted'       => '已刪除',
    'action_failed' => '操作失敗',
    /*
     * ⚠ 「看內容」與「編輯」是**先開視窗再填內容**（內容要去撈，列表沒帶）。
     * 這一句就是那段空檔要顯示的字 —— 少了它使用者會看到一個空白視窗，
     * 分不出「還在載」與「這份規章是空的」。
     */
    'loading'       => '載入中…',

    // 系統規章（掛在每個專案底下）
    'regulation_section'   => '系統規章',
    'regulation_empty'     => '這個專案還沒有規章',
    'regulation_add'       => '新增規章',
    'regulation_edit'      => '編輯',
    'regulation_delete'    => '刪除',
    'regulation_view'      => '看內容',
    'regulation_title'     => '規章標題',
    'regulation_content'   => '規章內容',
    'regulation_status'    => '狀態',
    'regulation_sort'      => '排序',
    'regulation_updated_by' => '最後修改',
    'regulation_active'    => '啟用',
    'regulation_disabled'  => '停用',
    'regulation_title_placeholder'   => '例如：三方規章',
    'regulation_content_placeholder' => '純文字即可。小幫手會把這段原樣發到群組，太長時會自動分段。',
    // ⚠ 要讓人知道停用之後小幫手就取不到，不然會以為只是「列表上灰掉」
    'regulation_status_hint' => '停用之後，小幫手就不會再回覆這一份規章。',
    'regulation_delete_confirm' => '確定要刪除這份規章嗎？刪掉之後救不回來。',

    'regulation_created'       => '規章已新增',
    'regulation_updated'       => '規章已更新',
    'regulation_deleted'       => '規章已刪除',
    'regulation_save_failed'   => '規章儲存失敗',
    'regulation_delete_failed' => '規章刪除失敗',

    /*
     * ⚠ 這句是「沒參與這個專案」時回的，不是「沒有權限」——
     * 兩者對使用者來說要分得開：前者去找管理者加參與專案，
     * 後者去要權限。
     */
    'regulation_no_access' => '您沒有參與這個專案，看不到它的規章',

    'regulation_project_required'  => '請選擇規章所屬的專案',
    'regulation_title_required'    => '請填寫規章標題',
    'regulation_title_too_long'    => '規章標題太長了（最多 :max 個字）',
    'regulation_content_required'  => '請填寫規章內容',
    'regulation_content_too_long'  => '規章內容太長了（最多 :max 個字）',

    // ---------------------------------------------------------------
    //  專案負責人（需求方 2026-10-30，只會有一位）
    // ---------------------------------------------------------------
    'owner_label'  => '負責人',
    'owner_change' => '變更',
    'owner_change_title' => '變更專案負責人',
    /*
     * ⚠ 沒指派時顯示「未指派」而不是「-」——「-」看起來像沒這個欄位，
     * 標成橘色才看得出「這裡缺東西」。
     */
    'owner_unassigned' => '未指派',
    // 下拉的第一個選項。⚠ 要講明「清空是刻意的操作」，不然會以為是沒選
    'owner_select_none' => '未指派（不指定負責人）',
    /*
     * ⚠ 一定要寫出「管理者不在名單中」：管理者自己開這個下拉時找不到自己，
     * 不說的話只會以為名單撈壞了。
     */
    'owner_select_hint' => '一個專案只會有一位負責人，指派新的人就會取代原本那位。管理者不會出現在名單中。',
    /*
     * ⚠ 新增專案的下拉**列的是全部啟用帳號**（那一刻還沒有人勾得了參與它），
     * 所以這裡一定要提醒後續要去帳號管理補勾 —— 少了這一句，
     * 新負責人登入後會看到一個空的專案管理頁，而沒有人知道為什麼。
     */
    'owner_create_hint' => '新專案還沒有參與人員，所以這裡可以選任何同仁（管理者除外）。存檔後請記得到帳號管理把他加入參與專案，否則他登入後看不到這個專案。',
    'owner_candidates_empty' => '這個專案目前還沒有參與人員，請先到帳號管理勾選參與專案。',

    /*
     * ⚠ 這三句是「要不要標紅字提醒換人」的三種原因，**不能合成一句** ——
     * 處理方式完全不同：離職／停用要換人，沒參與要去帳號管理補勾。
     * 對應 `Project::ownerIssue()` 回的代碼。
     */
    'owner_issue_resigned' => '已離職，請更換負責人',
    'owner_issue_disabled' => '已停用，請更換負責人',
    /*
     * ⚠ 這一種是 2026-10-30 加上「管理者不可以當負責人」之前留下的**舊資料**。
     * 不標出來的話編輯視窗會顯示「未指派」（他已經不在候選名單裡），
     * 按下確認就把負責人清空，而操作者以為自己什麼都沒改。
     */
    'owner_issue_admin' => '是管理者，不可擔任負責人，請更換',
    'owner_issue_not_participant' => '尚未參與本專案，看不到這個專案，請到帳號管理勾選參與專案',

    'owner_assigned'      => '專案負責人已更新',
    'owner_assign_failed' => '專案負責人指派失敗',
    'owner_not_found'     => '找不到這個帳號，請重新選擇負責人',
    /*
     * ⚠ 這句一定要講**下一步**（去哪裡處理）。只回「不可以指派」的話，
     * 被擋下來的人不知道要去帳號管理勾參與專案。
     */
    'owner_not_participant' => '這位同仁尚未參與本專案，請先到帳號管理為他勾選參與專案，再回來指派負責人',
    /*
     * ⚠ 一定要跟 `owner_not_participant` **分開講**：處理方式完全不同 ——
     * 這一句要換一個人，那一句是去帳號管理補勾參與專案。
     * 回同一句的話，被擋的人會跑去幫管理者勾參與專案，然後發現還是不行。
     */
    'owner_not_allowed_admin' => '管理者不可擔任專案負責人，請改指派一位參與本專案的同仁',
];
