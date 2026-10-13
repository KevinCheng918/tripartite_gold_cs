<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 把「20 分鐘後發送 T01 公告給 LV_UAT 群組」解析成時間、範本代號、對象
 *
 * 跟 `ReminderParser` 刻意分成兩支（需求方 2026-10-13）：這一支解析出來的
 * 東西會**發到客戶群組**，規則與護欄跟「提醒自己人」完全不同，混在一支裡
 * 兩邊的 prompt 會互相干擾。
 *
 * ⚠ **呼叫端要先用關鍵字分流**，不要每則訊息都跑兩支模型。
 * 但「明天提醒我發公告」這種句子含「公告」卻是提醒，所以這一支要能回
 * `not_broadcast`，讓呼叫端退回提醒那條路。
 *
 * ⚠ **解析不出來一律 unknown，絕不猜。** 猜錯範本代號 ＝ 錯誤的公告發給客戶，
 * 猜錯群組 ＝ 對的公告發給錯的人。兩者都收不回來。
 */
class BroadcastCommandParser
{
    /** @var string 要預約發公告 */
    const INTENT_BROADCAST = 'broadcast';

    /** @var string 看起來不是在講發公告（呼叫端要退回提醒那條路） */
    const INTENT_NOT_BROADCAST = 'not_broadcast';

    /** @var string 是在講發公告，但缺東西或看不懂 */
    const INTENT_UNKNOWN = 'unknown';

    /** @var string 對象是「全部客戶」 */
    const TARGET_ALL = 'all';

    private $matcher;

    public function __construct(ClaudeCodeMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * 解析一句話
     *
     * @param string $text 使用者說的話（已經去掉 @機器人）
     * @return array{intent: string, send_at: \Illuminate\Support\Carbon|null, template_code: string|null, target: string|null}|null
     *         回 null 代表模型不可用
     */
    public function parse($text)
    {
        $text = trim((string) $text);

        if (blank($text)) {
            return $this->unknown();
        }

        $config = (array) config('constants.BROADCAST_COMMAND');
        $now = now();

        try {
            $result = $this->matcher->generateStructured(
                (string) Arr::get($config, 'PROMPT'),
                strtr((string) Arr::get($config, 'INPUT'), [
                    '{now}'  => $now->format('Y-m-d H:i'),
                    '{text}' => $text,
                ]),
                $this->schema()
            );
        } catch (\Exception $e) {
            Log::warning('預約公告解析失敗', ['error' => $e->getMessage()]);

            return null;
        }

        if (blank($result)) {
            return null;
        }

        return $this->normalize($result, $now);
    }

    /**
     * 整理成呼叫端要的形狀
     *
     * ⚠ 時間、範本代號、對象**缺一不可**。少了任何一個就是 unknown ——
     * 自己補一個預設值（例如「沒說對象就發給全部」）是這個功能最危險的事。
     *
     * @param array                      $result
     * @param \Illuminate\Support\Carbon $now
     * @return array
     */
    private function normalize(array $result, $now)
    {
        $intent = (string) Arr::get($result, 'intent', self::INTENT_UNKNOWN);

        if ($intent === self::INTENT_NOT_BROADCAST) {
            return $this->notBroadcast();
        }

        if ($intent !== self::INTENT_BROADCAST) {
            return $this->unknown();
        }

        $sendAt = $this->parseTime(Arr::get($result, 'send_at'));
        $code = trim((string) Arr::get($result, 'template_code'));
        $target = trim((string) Arr::get($result, 'target'));

        if (blank($sendAt) || blank($code) || blank($target) || $sendAt->lessThanOrEqualTo($now)) {
            Log::info('預約公告解析結果不完整', [
                'send_at'  => Arr::get($result, 'send_at'),
                'code'     => $code,
                'target'   => $target,
            ]);

            return $this->unknown();
        }

        return [
            'intent'        => self::INTENT_BROADCAST,
            'send_at'       => $sendAt,
            'template_code' => $code,
            'target'        => $target,
        ];
    }

    /**
     * @return array
     */
    private function unknown()
    {
        return ['intent' => self::INTENT_UNKNOWN, 'send_at' => null, 'template_code' => null, 'target' => null];
    }

    /**
     * @return array
     */
    private function notBroadcast()
    {
        return ['intent' => self::INTENT_NOT_BROADCAST, 'send_at' => null, 'template_code' => null, 'target' => null];
    }

    /**
     * `Y-m-d H:i` → Carbon
     *
     * ⚠ 用 `createFromFormat` 而不是 `Carbon::parse()`：後者什麼都吃得下，
     * 模型回一句「明天下午」也會被解析成某個時間。
     *
     * @param mixed $value
     * @return \Illuminate\Support\Carbon|null
     */
    private function parseTime($value)
    {
        if (blank($value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d H:i', trim((string) $value));
        } catch (\Exception $e) {
            return null;
        }

        return filled($date) ? $date->seconds(0) : null;
    }

    /**
     * 要模型填的欄位
     *
     * @return array
     */
    private function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => [self::INTENT_BROADCAST, self::INTENT_NOT_BROADCAST, self::INTENT_UNKNOWN],
                    'description' => '要預約發公告（broadcast）、根本不是在講發公告（not_broadcast）、還是在講但看不懂（unknown）',
                ],
                'send_at' => [
                    'type'        => ['string', 'null'],
                    'description' => '什麼時候發，格式 YYYY-MM-DD HH:MM。看不懂就填 null，不要猜',
                ],
                'template_code' => [
                    'type'        => ['string', 'null'],
                    'description' => '公告的代號，例如 T01。純文字、不要放 JSON。沒講代號就填 null，不要猜一個',
                ],
                'target' => [
                    'type'        => ['string', 'null'],
                    'description' => '要發給誰：群組名稱原文（例如 LV_UAT，不要帶「群組」兩字），要發給所有客戶就填 all。沒講就填 null',
                ],
            ],
            'required' => ['intent', 'send_at', 'template_code', 'target'],
        ];
    }
}
