<?php

namespace App\Http\Requests\TaskBoard\Concerns;

/**
 * 任務附件的共用驗證規則
 *
 * 新增任務、更新任務、描述編輯器上傳三處共用同一份限制，
 * 避免各寫各的造成一鬆一緊（例如某處能傳 30MB、另一處只准 5MB）。
 */
trait HasAttachmentRules
{
    /** @var int 檔案大小上限（KB），與文件區一致 */
    public static $attachmentMaxKb = 20480;

    /**
     * 禁止的副檔名
     *
     * 上傳目的地在 storage/app/public 底下、對外可直接存取，
     * 若讓可執行或腳本類檔案進來，等於開了一條執行任意程式碼的路。
     *
     * @var array
     */
    public static $blockedExtensions = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar',
        'exe', 'com', 'bat', 'cmd', 'msi', 'scr',
        'sh', 'bash', 'zsh', 'ps1',
        'jsp', 'jspx', 'asp', 'aspx', 'cgi', 'pl',
        'htaccess', 'htpasswd',
    ];

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
            $this->blockedExtensionRule(),
        ];
    }

    /**
     * @return \Closure
     */
    protected function blockedExtensionRule()
    {
        return function ($attribute, $value, $fail) {
            if (!$value || !method_exists($value, 'getClientOriginalExtension')) {
                return;
            }

            $ext = strtolower((string) $value->getClientOriginalExtension());
            if (in_array($ext, self::$blockedExtensions, true)) {
                $fail(trans('task_board.msg.file_type_blocked'));
            }
        };
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
