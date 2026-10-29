<?php

/**
 * 專案管理
 *
 * ⚠ 這個檔是 2026-10-30 做系統規章時才建的 —— 專案頁原本的文字是寫死在
 * Blade 裡的。既有那些沒有一起搬過來（不在這次範圍），新加的都放這裡。
 */

return [
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
];
