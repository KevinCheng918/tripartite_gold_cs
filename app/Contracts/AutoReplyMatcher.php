<?php

namespace App\Contracts;

/**
 * 自動回覆比對器
 *
 * 職責只有一件事：拿客人的話，從題庫裡挑一則最相符的，回傳它的 id。
 *
 * **實作一律不得自行生成回覆文字** —— 客戶收到的內容必須等於題庫原文，
 * 這樣才有可稽核性，也才不會把金流話術的正確性交給模型。
 *
 * 抽成介面是為了保留換實作的空間（改走 API、加本地預篩），
 * 目前唯一實作是 ClaudeCodeMatcher。
 */
interface AutoReplyMatcher
{
    /**
     * 比對客人的訊息
     *
     * @param string $text    客人的話
     * @param array  $context 額外脈絡，目前支援：
     *                        candidate_ids array 反問後的第二輪，上一輪給客人的候選題目 id
     *                        group_id       int  觸發的對話（記錄用量用）
     * @return array|null 比對結果，失敗（逾時、額度用盡、格式錯誤）時回 null：
     *                    item_id       int|null 命中的題庫 id，null 表示題庫裡沒有
     *                    confidence    string   high / low
     *                    candidate_ids array    低信心時的候選題目 id
     */
    public function resolve($text, array $context = []);
}
