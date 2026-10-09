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
     * 檢查一句模型寫的話能不能送給客人
     *
     * ⚠ **規則可以換一套**（`$rule`，`constants.` 底下的完整路徑）。
     * 承接句、「轉人工時的稍等」、「陌生人的回覆」各有各的禁語
     * 不完全一樣：前者禁止「幫您轉給專員」這類替人承諾的話（模型保證不了真的
     * 有人會做），後者那句卻是**事實**（求助單已經開出來了）。
     * 共用的是「認帳」與「保證」那幾組 —— 那兩組在任何情況下都不能說。
     *
     * @param string|null $text
     * @param string      $rule `constants.` 底下的完整路徑，例如 `AUTO_REPLY.WAIT`
     * @return string|null 可用的句子（已去頭尾空白）；不可用時為 null
     */
    public function sanitize($text, $rule = 'AUTO_REPLY.OPENING')
    {
        if (blank($text)) {
            return null;
        }

        $text = trim($text);
        $maxLength = (int) config("constants.{$rule}.MAX_LENGTH");

        if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
            Log::warning('模型寫的句子過長，退回固定話術', ['rule' => $rule, 'text' => $text]);

            return null;
        }

        foreach ((array) config("constants.{$rule}.BLACKLIST") as $word) {
            if (mb_strpos($text, $word) !== false) {
                Log::warning('模型寫的句子含不該由系統說的話，退回固定話術', [
                    'rule' => $rule,
                    'word' => $word,
                    'text' => $text,
                ]);

                return null;
            }
        }

        return $text;
    }
}
