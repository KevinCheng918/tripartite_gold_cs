<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Facades\Log;

/**
 * 承接句的護欄
 *
 * 承接句是整個自動回覆裡**唯一讓模型自由生成**的地方，所以 prompt 講過的
 * 限制這裡要再擋一次 —— **prompt 是請求，不是保證**。
 *
 * 任何一條不過就回 null，呼叫端退回固定話術：最壞情況等於沒有這個功能，
 * 不會更糟。
 *
 * 兩個地方用它，所以抽出來共用而不是各寫一份：
 *   - `AutoReplyService`        — 回應客人訊息時的承接句
 *   - `AutoReplySupportService` — 轉同仁答案給客人時的承接句
 *
 * 後者尤其需要：那段話會接在同仁寫的答案前面送給客人，
 * 模型在那裡亂講話的後果跟前者一樣嚴重。
 */
class OpeningSanitizer
{
    /**
     * 檢查一句承接能不能用
     *
     * @param string|null $opening
     * @return string|null 可用的承接句（已去頭尾空白）；不可用時為 null
     */
    public function sanitize($opening)
    {
        if (blank($opening)) {
            return null;
        }

        $opening = trim($opening);
        $maxLength = (int) config('constants.AUTO_REPLY.OPENING.MAX_LENGTH');

        if (mb_strlen($opening) > $maxLength) {
            Log::warning('承接句過長，退回固定話術', ['opening' => $opening]);

            return null;
        }

        foreach ((array) config('constants.AUTO_REPLY.OPENING.BLACKLIST') as $word) {
            if (mb_strpos($opening, $word) !== false) {
                Log::warning('承接句含不該由系統說的話，退回固定話術', [
                    'word'    => $word,
                    'opening' => $opening,
                ]);

                return null;
            }
        }

        return $opening;
    }
}
