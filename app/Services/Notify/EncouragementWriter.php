<?php

namespace App\Services\Notify;

use App\Services\AutoReply\ClaudeCodeMatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 每日統計裡那幾句鼓勵的話
 *
 * 需求方 2026-10-06：昨天沒有超時的時候也要發統計，而那則不要用公版 ——
 * 個人版寫給本人的肯定、完整版寫整個團隊昨天的表現，讓模型自由發揮。
 *
 * ⚠ **一定要有公版退路**。這支掛在每天 08:30 的排程上，模型沒設定、額度用完、
 * CLI 逾時都是會發生的事 —— 那時候該發的訊息還是要發出去，只是換成固定句子。
 * 「沒有鼓勵的話」比「整則統計不發」好得多。
 *
 * ⚠ **逐人各生一句**（需求方指定）：帶上名字才有「針對他」的感覺。
 * 代價是一人一次 CLI 呼叫、每次幾秒 —— 所以呼叫端要留意人數，
 * 排程本身有 `withoutOverlapping` 擋著不會疊在一起跑。
 */
class EncouragementWriter
{
    private $matcher;

    public function __construct(ClaudeCodeMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * 寫給某位同仁（昨天沒有被提醒到）
     *
     * @param string $name
     * @return string
     */
    public function forPerson($name)
    {
        $config = (array) config('constants.AUTO_REPLY.REMIND.REPORT.ENCOURAGE');

        $text = $this->generate(
            (string) Arr::get($config, 'PERSON_PROMPT'),
            strtr((string) Arr::get($config, 'PERSON_INPUT'), ['{name}' => $name])
        );

        if (filled($text)) {
            return $text;
        }

        return strtr((string) Arr::get($config, 'PERSON_FALLBACK'), ['{name}' => $name]);
    }

    /**
     * 寫給某位同仁（這一期出勤乾乾淨淨）
     *
     * ⚠ **不共用 `forPerson()` 的 prompt**：那支寫的是「昨天沒有求助單卡住」，
     * 用在出勤報表會變成文不對題的肯定（他這週沒遲到，卻被誇沒有卡住題目）。
     * 情境不同就該有自己的 prompt 與公版。
     *
     * @param string $name
     * @param string $period 這一期的說法，例如「上週」「上個月」
     * @return string
     */
    public function forAttendance($name, $period)
    {
        $config = (array) config('constants.ATTENDANCE_REPORT.ENCOURAGE');

        $text = $this->generate(
            (string) Arr::get($config, 'PROMPT'),
            strtr((string) Arr::get($config, 'INPUT'), ['{name}' => $name, '{period}' => $period])
        );

        if (filled($text)) {
            return $text;
        }

        return strtr((string) Arr::get($config, 'FALLBACK'), ['{name}' => $name, '{period}' => $period]);
    }

    /**
     * 寫給整個團隊（這一期完全沒有超時）
     *
     * ⚠ `$period` 一定要傳：日報說「昨天」、週報說「上週」、月報說「上個月」。
     * 2026-10-10 加週月報之前這裡寫死「昨天」，週報會變成
     * 「昨天一題都沒卡住」—— 統計的是一整週，話卻在講昨天。
     *
     * @param string $period 這一期的說法
     * @return string
     */
    public function forTeam($period)
    {
        $config = (array) config('constants.AUTO_REPLY.REMIND.REPORT.ENCOURAGE');

        $text = $this->generate(
            (string) Arr::get($config, 'TEAM_PROMPT'),
            strtr((string) Arr::get($config, 'TEAM_INPUT'), ['{period}' => $period])
        );

        if (filled($text)) {
            return $text;
        }

        return strtr((string) Arr::get($config, 'TEAM_FALLBACK'), ['{period}' => $period]);
    }

    /**
     * 叫模型寫，順便把明顯不能用的擋掉
     *
     * ⚠ **長度一定要擋**。模型偶爾會寫成一整段 —— 這則訊息是接在統計後面的
     * 一句話，長出去會讓整則訊息看起來像廢文。擋掉就退公版。
     *
     * @param string $systemPrompt
     * @param string $userPrompt
     * @return string|null
     */
    private function generate($systemPrompt, $userPrompt)
    {
        if (blank($systemPrompt) || blank($userPrompt)) {
            return null;
        }

        try {
            $text = $this->matcher->generateText($systemPrompt, $userPrompt);
        } catch (\Exception $e) {
            // 排程不能因為模型掛掉就不發訊息
            Log::warning('鼓勵文案生成失敗，改用公版', ['error' => $e->getMessage()]);

            return null;
        }

        if (blank($text)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text));
        $max = (int) config('constants.AUTO_REPLY.REMIND.REPORT.ENCOURAGE.MAX_LENGTH');

        if ($max > 0 && mb_strlen($text) > $max) {
            Log::info('鼓勵文案太長，改用公版', ['length' => mb_strlen($text)]);

            return null;
        }

        return $text;
    }
}
