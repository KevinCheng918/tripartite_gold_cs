<?php

namespace App\Http\Requests\Concerns;

/**
 * 擋掉可執行／腳本類上傳檔的共用規則
 *
 * 上傳目的地在 storage/app/public 底下、對外可直接存取，
 * 讓這類檔案進來等於開了一條執行任意程式碼的路。
 *
 * 任務看板附件、Telegram 傳檔、群發公告附件都用這支，
 * 各自複製一份遲早會改一邊漏一邊。
 */
trait BlocksExecutableUploads
{
    /**
     * @param string $messageKey 錯誤訊息的語系鍵（各模組的文案不同）
     * @return \Closure
     */
    protected function blockedExtensionRule($messageKey)
    {
        return function ($attribute, $value, $fail) use ($messageKey) {
            if (!$value || !method_exists($value, 'getClientOriginalExtension')) {
                return;
            }

            $ext = strtolower((string) $value->getClientOriginalExtension());
            if (in_array($ext, config('rules.UPLOAD_BLOCKED_EXTENSIONS'), true)) {
                $fail(trans($messageKey));
            }
        };
    }
}
