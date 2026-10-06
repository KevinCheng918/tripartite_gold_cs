<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 轉人工時回客人的那一句
 *
 * 需求方 2026-10-07：不用公版，讓模型順著客人問的內容現寫 ——
 * 固定一句話客人看幾次就知道是機器回的。
 *
 * ⚠ **護欄跟承接句（`OpeningSanitizer` 的 OPENING 那套）不一樣**：
 * 這時求助單已經開出來了，「已轉給專員」是事實、不該被擋。
 * 但認帳與保證那兩組在任何情況下都不能說，所以 `WAIT` 規則照搬過來，
 * 另外多擋「幾分鐘內回覆」這種系統保證不了的承諾。
 *
 * ⚠ **一定要有公版退路**。客人問完正在等 —— 模型沒設定、額度用完、CLI 逾時
 * 都會發生，那時候句子每次一樣也比完全不回好。
 *
 * ⚠ **這會多花幾秒**（多一次 CLI 呼叫）。可接受的理由：求助單在呼叫之前就已經
 * 開好、同仁已經收到通知了，客人多等幾秒拿到的是一句針對他問題寫的話。
 */
class WaitWriter
{
    private $matcher;
    private $sanitizer;

    public function __construct(ClaudeCodeMatcher $matcher, OpeningSanitizer $sanitizer)
    {
        $this->matcher = $matcher;
        $this->sanitizer = $sanitizer;
    }

    /**
     * 寫一句請客人稍候的話
     *
     * @param string      $question 客人問的內容（合併過的那一段）
     * @param string|null $opening  模型這次已經生成的承接句，模型不可用時當開頭
     * @return string
     */
    public function write($question, $opening = null)
    {
        $config = (array) config('constants.AUTO_REPLY.WAIT');
        $text = $this->generate($config, $question);

        if (filled($text)) {
            return $text;
        }

        return $this->fallback($config, $opening);
    }

    /**
     * 叫模型寫，過不了護欄就回 null
     *
     * @param array  $config
     * @param string $question
     * @return string|null
     */
    private function generate(array $config, $question)
    {
        if (blank($question)) {
            return null;
        }

        try {
            $text = $this->matcher->generateText(
                (string) Arr::get($config, 'PROMPT'),
                strtr((string) Arr::get($config, 'INPUT'), ['{question}' => $question])
            );
        } catch (\Exception $e) {
            // 客人在等，模型掛掉不能讓整條路跟著斷
            Log::warning('稍等文案生成失敗，改用公版', ['error' => $e->getMessage()]);

            return null;
        }

        return $this->sanitizer->sanitize($text, 'WAIT');
    }

    /**
     * 模型不可用時的退路
     *
     * 承接句是這一輪順便生出來的（不必再呼叫一次），有的話接在前面 ——
     * 退路也不必每次都一模一樣。
     *
     * @param array       $config
     * @param string|null $opening
     * @return string
     */
    private function fallback(array $config, $opening)
    {
        $wait = (string) Arr::get($config, 'FALLBACK');

        if (blank($opening)) {
            return $wait;
        }

        return "{$opening}\n{$wait}";
    }
}
