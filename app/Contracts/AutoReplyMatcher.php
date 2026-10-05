<?php

namespace App\Contracts;

/**
 * 自動回覆比對器
 *
 * 兩件事：判斷客人這則訊息是什麼性質，以及（只有在問問題時）從題庫挑一則最相符的。
 *
 * **答案本體一律不得由實作自行生成** —— 客戶看到的說明內容必須等於題庫原文，
 * 這樣才有可稽核性，也才不會把金流話術的正確性交給模型。
 *
 * 唯一的例外是 opening（承接句）：那是「回應客人的那一兩句」，
 * 不含任何規格、價格與承諾，純粹為了讓對話不像罐頭。
 * 護欄寫在 prompt 裡，`AutoReplyService` 收到後還會再驗一次。
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
     *                        group_id int                觸發的對話（記錄用量用）
     *                        history  array<int, string>  近期對話，一行一則、舊的在前。
     *                                                     客人說「這是什麼錯誤呢」時，
     *                                                     他指的東西在前一則裡
     * @return array|null 比對結果，失敗（逾時、額度用盡、格式錯誤）時回 null：
     *                    intent     string      question / request / chat
     *                    item_id    int|null    命中的題庫 id；非 question 時為 null
     *                    confidence string      high / low
     *                    opening    string|null 承接句；純寒暄不需回應時為 null
     */
    public function resolve($text, array $context = []);
}
