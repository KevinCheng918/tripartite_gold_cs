<?php

return [
    'nav_label'     => '任務看板',
    'page_title'    => '任務看板',
    'subtitle'      => '管理工作任務',
    'section_label' => '任務管理',

    'status_pending'     => '待處理',
    'status_in_progress' => '進行中',
    'status_testing'     => '測試中',
    'status_in_review'   => '審核中',
    'status_resolved'    => '已解決',

    'priority_low'    => '低',
    'priority_medium' => '中',
    'priority_high'   => '高',
    'priority_urgent' => '緊急',

    'field_project'     => '專案',
    'field_title'       => '任務標題',
    'field_description' => '任務描述',
    'field_status'      => '狀態',
    'field_priority'    => '優先順序',
    'field_assignee'    => '指派人員',
    'field_creator'     => '建立者',
    'field_due_date'    => '預期完成日',
    'field_attachment'  => '附件',
    'field_system'      => '系統',
    'field_station'     => '站台',
    'all_systems'       => '全部系統',
    'station_search_ph' => '搜尋站台...',
    'station_unset'     => '未選擇',

    'action_create_task'    => '新增任務',
    'action_create_project' => '新增專案',
    'action_edit'           => '編輯',
    'action_delete'         => '刪除',
    'action_filter'         => '篩選',
    'action_edit_comment'   => '編輯留言',
    'action_save'           => '儲存',
    'action_cancel'         => '取消',
    'comment_edited'        => '已編輯',
    'description_empty'     => '尚無描述，點右上角「編輯」新增',
    'checklist_tooltip'     => '勾選清單',
    'file_tooltip'          => '上傳檔案',
    /*
     * ⚠ 工具列那顆上傳鈕要有**文字**不只圖示：它原本只有 icon ＋ tooltip，
     * 又排在 `toolbar_mode: 'sliding'` 在窄面板會收起來的後段 ——
     * 使用者找不到它，就會回去用「附件」那個欄位，而那會排在描述底下。
     */
    'file_button'           => '檔案',
    /*
     * 描述欄位底下的提示（取代原本的「附件」上傳欄位）。
     *
     * ⚠ 一定要說「插在您放的那一行」—— 使用者原本的抱怨就是附件都集中在
     * 底下，不講清楚新的放法有什麼不同，他還是會去找那個已經被拔掉的欄位。
     */
    'description_drop_hint' => '圖片與檔案直接拖進描述，就會插在您放的那一行。',

    /*
     * 描述底下那一格降級成唯讀彙總之後的標題與說明。
     *
     * ⚠ 只有舊任務（`images` 已經有資料）才會出現這一格 ——
     * 標題要講明「先前上傳的」，不然看的人會以為新的附件還是會跑到這裡。
     */
    'attachment_legacy_title' => '先前上傳的附件',
    'attachment_legacy_hint'  => '這些是改版前上傳的附件。新的圖片與檔案請直接放進描述。',
    'checklist_placeholder' => '待辦事項',
    'checklist_progress'    => '已完成 :done / :total',
    'comment_image_locked'  => '附件無法編輯',

    'msg' => [
        'task_created'        => '任務已新增',
        'task_create_failed'  => '任務新增失敗',
        'task_updated'        => '任務已更新',
        'task_update_failed'  => '任務更新失敗',
        'attachment_deleted'       => '附件已刪除',
        'attachment_delete_failed' => '附件刪除失敗',
        'attachment_not_found'     => '找不到這個附件，可能已被刪除',
        'attachment_url_required'  => '請指定要刪除的附件',
        'task_deleted'        => '任務已刪除',
        'task_delete_failed'  => '任務刪除失敗',
        'project_create_failed' => '專案新增失敗',
        'confirm_delete'      => '確定刪除此任務？',
        'comment_updated'         => '留言已更新',
        'comment_update_failed'   => '留言更新失敗',
        'comment_create_failed'   => '留言失敗',
        'comment_not_owner'       => '只能編輯自己的留言',
        'comment_content_required' => '留言內容不可為空',
        'comment_content_max'     => '留言內容不可超過 :value 字元',
        'description_required'    => '描述內容不可為空',
        'checklist_updated'       => '已更新',
        'checklist_update_failed' => '勾選更新失敗',
        'file_required'      => '請選擇檔案',
        'file_too_large'     => '檔案不可超過 :value MB',
        'file_type_blocked'  => '不支援此檔案類型',
        'file_upload_failed' => '檔案上傳失敗',
    ],
];
