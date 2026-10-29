<?php

return [
    'project_add'   => '新增专案',
    'project_edit'  => '编辑专案',
    'saved'         => '已储存',
    'deleted'       => '已删除',
    'action_failed' => '操作失败',
    'loading'       => '载入中…',

    'regulation_section'   => '系统规章',
    'regulation_empty'     => '这个专案还没有规章',
    'regulation_add'       => '新增规章',
    'regulation_edit'      => '编辑',
    'regulation_delete'    => '删除',
    'regulation_view'      => '看内容',
    'regulation_title'     => '规章标题',
    'regulation_content'   => '规章内容',
    'regulation_status'    => '状态',
    'regulation_sort'      => '排序',
    'regulation_updated_by' => '最后修改',
    'regulation_active'    => '启用',
    'regulation_disabled'  => '停用',
    'regulation_title_placeholder'   => '例如：三方规章',
    'regulation_content_placeholder' => '纯文字即可。小帮手会把这段原样发到群组，太长时会自动分段。',
    'regulation_status_hint' => '停用之后，小帮手就不会再回覆这一份规章。',
    'regulation_delete_confirm' => '确定要删除这份规章吗？删掉之后救不回来。',

    'regulation_created'       => '规章已新增',
    'regulation_updated'       => '规章已更新',
    'regulation_deleted'       => '规章已删除',
    'regulation_save_failed'   => '规章储存失败',
    'regulation_delete_failed' => '规章删除失败',

    'regulation_no_access' => '您没有参与这个专案，看不到它的规章',

    'regulation_project_required'  => '请选择规章所属的专案',
    'regulation_title_required'    => '请填写规章标题',
    'regulation_title_too_long'    => '规章标题太长了（最多 :max 个字）',
    'regulation_content_required'  => '请填写规章内容',
    'regulation_content_too_long'  => '规章内容太长了（最多 :max 个字）',

    // 专案负责人（只会有一位）
    'owner_label'  => '负责人',
    'owner_change' => '变更',
    'owner_change_title' => '变更专案负责人',
    'owner_unassigned' => '未指派',
    'owner_select_none' => '未指派（不指定负责人）',
    'owner_select_hint' => '一个专案只会有一位负责人，指派新的人就会取代原本那位。管理者不会出现在名单中。',
    'owner_create_hint' => '新专案还没有参与人员，所以这里可以选任何同仁（管理者除外）。存档后请记得到帐号管理把他加入参与专案，否则他登入后看不到这个专案。',
    'owner_candidates_empty' => '这个专案目前还没有参与人员，请先到帐号管理勾选参与专案。',

    'owner_issue_resigned' => '已离职，请更换负责人',
    'owner_issue_disabled' => '已停用，请更换负责人',
    'owner_issue_admin' => '是管理者，不可担任负责人，请更换',
    'owner_issue_not_participant' => '尚未参与本专案，看不到这个专案，请到帐号管理勾选参与专案',

    'owner_assigned'      => '专案负责人已更新',
    'owner_assign_failed' => '专案负责人指派失败',
    'owner_not_found'     => '找不到这个帐号，请重新选择负责人',
    'owner_not_participant' => '这位同仁尚未参与本专案，请先到帐号管理为他勾选参与专案，再回来指派负责人',
    'owner_not_allowed_admin' => '管理者不可担任专案负责人，请改指派一位参与本专案的同仁',
];
