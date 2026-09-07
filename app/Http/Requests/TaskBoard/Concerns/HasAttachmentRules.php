<?php

namespace App\Http\Requests\TaskBoard\Concerns;

use App\Http\Requests\Concerns\BlocksExecutableUploads;

/**
 * 任務附件的共用驗證規則
 *
 * 新增任務、更新任務、描述編輯器上傳三處共用同一份限制，
 * 避免各寫各的造成一鬆一緊（例如某處能傳 30MB、另一處只准 5MB）。
 */
trait HasAttachmentRules
{
    use BlocksExecutableUploads;

    /** @var int 檔案大小上限（KB），與文件區一致 */
    public static $attachmentMaxKb = 20480;

    /**
     * 單一附件的驗證規則：不限類型但擋掉可執行／腳本檔
     *
     * @return array
     */
    protected function attachmentRules()
    {
        return [
            'file',
            'max:' . self::$attachmentMaxKb,
            $this->blockedExtensionRule('task_board.msg.file_type_blocked'),
        ];
    }

    /**
     * 附件過大的訊息（MB 為單位比較好讀）
     *
     * @return string
     */
    protected function attachmentMaxMessage()
    {
        return trans('task_board.msg.file_too_large', ['value' => self::$attachmentMaxKb / 1024]);
    }
}
