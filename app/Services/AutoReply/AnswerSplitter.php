<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;

/**
 * 長答案分則
 *
 * 題庫裡有十幾題是三、四百字的完整說明（錯誤碼判斷、必填欄位、L1～L4 流程）。
 * 一整塊丟過去，客人會跳著看，最後那段「麻煩提供代理帳號與訂單號」常常整個被略過 ——
 * 而那才是我們真正需要他做的事。
 *
 * 抽成獨立類別是因為有兩條送出路徑要用：自動回覆命中題庫，
 * 以及同仁在求助單按「用這題回覆」。同一份答案不該因為誰送的而排版不同。
 */
class AnswerSplitter
{
    /**
     * config 讀不到時的備用值。
     *
     * ⚠ config 快取沒跟著部署更新時，`auto_reply.answer.*` 會全部是 null。
     * 這裡失效只是退回「一則送完」，不像脈絡那樣難以察覺，但還是要有。
     *
     * @var array
     */
    private const FALLBACK = [
        'chunk_chars' => 300,
        'min_tail'    => 80,
    ];

    /** @var int 超過這個字數就不算標題行，理由見 looksLikeHeading() */
    private const HEADING_MAX_CHARS = 30;

    /**
     * 切成多則
     *
     * **只在段落邊界（空行）切**。寧可某一則長一點，也不要把一句話砍成兩半 ——
     * 切在句子中間比不切還糟。所以單一段落本身就超過上限時，那一則就是會比較長。
     *
     * @param string $content
     * @return array<int, string> 至少一則
     */
    public function split($content)
    {
        $chunkChars = $this->setting('chunk_chars');

        if ($chunkChars < 1 || mb_strlen($content) <= $chunkChars) {
            return [$content];
        }

        $paragraphs = preg_split('/\n\s*\n/u', trim($content));
        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if (blank($paragraph)) {
                continue;
            }

            $candidate = blank($buffer) ? $paragraph : "{$buffer}\n\n{$paragraph}";

            // buffer 是空的時候不能斷 —— 否則會產生一則空訊息
            if (filled($buffer) && mb_strlen($candidate) > $chunkChars) {
                list($keep, $heading) = $this->carryHeading($buffer);

                $chunks[] = $keep;
                $buffer = blank($heading) ? $paragraph : "{$heading}\n\n{$paragraph}";

                continue;
            }

            $buffer = $candidate;
        }

        if (filled($buffer)) {
            $chunks[] = $buffer;
        }

        return $this->mergeShortTail($chunks);
    }

    /**
     * 把結尾的標題行帶到下一則去
     *
     * 斷點剛好落在標題後面時會變成這樣：
     *
     *     （第 1 則）…只有在使用第三方通道時才會多出 L2 與 L3。
     *               判斷卡在哪一段：        ← 標題孤零零留在這裡
     *     （第 2 則）• 商戶開單當下就收到錯誤碼…
     *
     * 標題跟它的清單被拆開，比不分則還難讀。
     *
     * @param string $buffer
     * @return array [留在這一則的內容, 要帶去下一則的標題；沒有就是空字串]
     */
    private function carryHeading($buffer)
    {
        $parts = preg_split('/\n\s*\n/u', $buffer);

        // 只有一個段落就不能帶走 —— 帶走了這一則會變成空的
        if (count($parts) < 2) {
            return [$buffer, ''];
        }

        $last = trim(end($parts));

        if (!$this->looksLikeHeading($last)) {
            return [$buffer, ''];
        }

        array_pop($parts);

        return [implode("\n\n", $parts), $last];
    }

    /**
     * 這一段看起來是不是標題行
     *
     * 題庫的寫法有兩種：冒號結尾（「判斷卡在哪一段：」）
     * 與方括號包住（「【方法一：由會員自行填寫地址】」）。
     *
     * 長度也要看 —— 一整段說明文字剛好以冒號結尾的情況是有的，
     * 那種不該被當成標題搬走。
     *
     * @param string $text
     * @return bool
     */
    private function looksLikeHeading($text)
    {
        if (mb_strlen($text) > self::HEADING_MAX_CHARS || mb_strpos($text, "\n") !== false) {
            return false;
        }

        if (mb_substr($text, 0, 1) === '【') {
            return true;
        }

        return in_array(mb_substr($text, -1), ['：', ':'], true);
    }

    /**
     * 把過短的最後一則併回前一則
     *
     * 不做的話，結尾的「若還有不清楚的地方歡迎再告訴我們」會自己佔一則，
     * 看起來像系統多送了一句廢話。
     *
     * @param array<int, string> $chunks
     * @return array<int, string>
     */
    private function mergeShortTail(array $chunks)
    {
        $minTail = $this->setting('min_tail');

        if (count($chunks) < 2 || mb_strlen(end($chunks)) >= $minTail) {
            return $chunks;
        }

        $tail = array_pop($chunks);
        $chunks[count($chunks) - 1] .= "\n\n{$tail}";

        return $chunks;
    }

    /**
     * 讀設定，config 讀不到就用備用值
     *
     * 刻意填 0 關掉分則不會被蓋掉：blank(0) 是 false。
     *
     * @param string $key chunk_chars / min_tail
     * @return int
     */
    private function setting($key)
    {
        $value = config("auto_reply.answer.{$key}");

        if (blank($value)) {
            return (int) Arr::get(self::FALLBACK, $key);
        }

        return (int) $value;
    }
}
