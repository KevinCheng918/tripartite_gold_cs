<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 陌生人私訊 bot 時回的那一句
 *
 * 需求方 2026-10-09：不要用固定公版，讓模型寫 —— 但**這一則的重點不是變化，
 * 是不要洩漏系統資訊**。
 *
 * 舊版那句寫著「請到後台『我的帳號』填寫您的 Telegram 帳號」，等於告訴陌生人
 * 這背後有後台、有哪個頁面。對隨機路人沒差，對想摸清楚系統的人是免費情報。
 *
 * ⚠ **護欄比 prompt 重要。** 模型很愛「熱心幫忙」寫出「請聯繫管理員」
 * 「請提供您的帳號」—— prompt 寫了它還是可能寫，所以 `STRANGER.BLACKLIST`
 * 再擋一次。過不了就退公版。
 */
class StrangerReplyWriter
{
    private $matcher;
    private $sanitizer;

    public function __construct(ClaudeCodeMatcher $matcher, OpeningSanitizer $sanitizer)
    {
        $this->matcher = $matcher;
        $this->sanitizer = $sanitizer;
    }

    /**
     * 寫一句婉轉的回覆
     *
     * @return string
     */
    public function write()
    {
        $config = (array) config('constants.TELEGRAM.BIND.STRANGER');
        $text = $this->generate($config);

        return filled($text) ? $text : (string) Arr::get($config, 'FALLBACK');
    }

    /**
     * 叫模型寫，過不了護欄就回 null
     *
     * @param array $config
     * @return string|null
     */
    private function generate(array $config)
    {
        try {
            $text = $this->matcher->generateText(
                (string) Arr::get($config, 'PROMPT'),
                (string) Arr::get($config, 'INPUT')
            );
        } catch (\Exception $e) {
            Log::warning('陌生人回覆生成失敗，改用公版', ['error' => $e->getMessage()]);

            return null;
        }

        return $this->sanitizer->sanitize($text, 'TELEGRAM.BIND.STRANGER');
    }
}
