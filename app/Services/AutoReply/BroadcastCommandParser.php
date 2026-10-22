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

    /**
     * 預約傳送**自己打的訊息**（沒有公告代號，內容直接寫在句子裡）
     *
     * 「我要預約傳送訊息給 lv-uat，時間是 1 小時後，訊息內容是…」
     *
     * 跟 `broadcast` 共用同一條預約管線（`TelegramBroadcastService::schedule()`
     * 本來就吃 `content`，`template_code` 是選填），差別只在內容從哪來：
     * 一個是建檔公告，一個是當場打的字。
     *
     * @var string
     */
    const INTENT_MESSAGE = 'message';

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
     * @return array{intent: string, send_at: \Illuminate\Support\Carbon|null, template_codes: array<string>, target: string|null, content: string|null}|null
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

        if ($intent === self::INTENT_MESSAGE) {
            return $this->normalizeMessage($result, $now);
        }

        if ($intent !== self::INTENT_BROADCAST) {
            return $this->unknown();
        }

        $sendAt = $this->parseTime(Arr::get($result, 'send_at'));
        $codes = $this->codes(Arr::get($result, 'template_codes'));
        $target = trim((string) Arr::get($result, 'target'));

        if (blank($sendAt) || blank($codes) || blank($target) || $sendAt->lessThanOrEqualTo($now)) {
            Log::info('預約公告解析結果不完整', [
                'send_at' => Arr::get($result, 'send_at'),
                'codes'   => $codes,
                'target'  => $target,
            ]);

            return $this->unknown();
        }

        return [
            'intent'         => self::INTENT_BROADCAST,
            'send_at'        => $sendAt,
            'template_codes' => $codes,
            'target'         => $target,
            'content'        => null,
        ];
    }

    /**
     * 整理代號清單
     *
     * ⚠ **還是要自己拆一次**：schema 寫了陣列，但模型仍可能把兩個代號塞在
     * 同一個元素裡（`["T11,T12"]` 或 `["T11 和 T12"]`）。2026-10-22 那次
     * 就是接成一串送進查詢，結果回「找不到代號 T11,T12 的公告」——
     * 而兩個代號其實都在。
     *
     * ⚠ 去重但**保留順序**：他講的順序就是他想發的順序。
     *
     * @param mixed $value
     * @return array<string>
     */
    private function codes($value)
    {
        $codes = [];

        foreach ((array) $value as $item) {
            foreach (preg_split('/[,、\s和跟與]+/u', (string) $item) as $code) {
                $code = trim($code);

                if (filled($code) && !in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }

    /**
     * 預約自己打的訊息
     *
     * ⚠ **時間、對象、內容缺一不可**，跟公告那條一樣 —— 自己補預設值
     * （「沒說對象就發給全部」）是這個功能最危險的事。
     *
     * ⚠ **內容要原文**。這段字會原封不動發給客戶，模型只能把它抄出來，
     * 不能改寫、不能補字、不能截斷。
     *
     * ⚠ `looksLikeJson()` 擋整包 JSON 被塞進 content —— 這一欄正是
     * 2026-10-12 那個 bug 出現的地方（見
     * bugfix/2026-10-12-double-wrapped-json-prompt）。
     *
     * @param array                      $result
     * @param \Illuminate\Support\Carbon $now
     * @return array
     */
    private function normalizeMessage(array $result, $now)
    {
        $sendAt = $this->parseTime(Arr::get($result, 'send_at'));
        $target = trim((string) Arr::get($result, 'target'));
        $content = trim((string) Arr::get($result, 'content'));

        if (blank($sendAt) || blank($target) || blank($content) || $sendAt->lessThanOrEqualTo($now)) {
            Log::info('預約自訂訊息解析結果不完整', [
                'send_at' => Arr::get($result, 'send_at'),
                'target'  => $target,
                'has_content' => filled($content),
            ]);

            return $this->unknown();
        }

        if ($this->looksLikeJson($content)) {
            Log::warning('預約自訂訊息的內容是一整包 JSON，不可用', ['content' => $content]);

            return $this->unknown();
        }

        return [
            'intent'         => self::INTENT_MESSAGE,
            'send_at'        => $sendAt,
            'template_codes' => [],
            'target'         => $target,
            'content'        => $content,
        ];
    }

    /**
     * 這段內容是不是一整包 JSON
     *
     * 誤殺的代價只是請對方重講一次；放過去的代價是**客戶收到一串大括號**。
     *
     * @param string $content
     * @return bool
     */
    private function looksLikeJson($content)
    {
        return is_array(json_decode($content, true));
    }

    /**
     * @return array
     */
    private function unknown()
    {
        return [
            'intent' => self::INTENT_UNKNOWN, 'send_at' => null,
            'template_codes' => [], 'target' => null, 'content' => null,
        ];
    }

    /**
     * @return array
     */
    private function notBroadcast()
    {
        return [
            'intent' => self::INTENT_NOT_BROADCAST, 'send_at' => null,
            'template_codes' => [], 'target' => null, 'content' => null,
        ];
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
                    'enum' => [
                        self::INTENT_BROADCAST,
                        self::INTENT_MESSAGE,
                        self::INTENT_NOT_BROADCAST,
                        self::INTENT_UNKNOWN,
                    ],
                    'description' => '要預約發建檔公告（broadcast）、要預約傳送自己打的訊息（message）、根本不是在講這兩種（not_broadcast）、是在講但缺東西（unknown）',
                ],
                'send_at' => [
                    'type'        => ['string', 'null'],
                    'description' => '什麼時候發，格式 YYYY-MM-DD HH:MM。看不懂就填 null，不要猜',
                ],
                /*
                 * ⚠ **一次可以講好幾個代號**（需求方 2026-10-22：
                 * 「1 分鐘後發 T11 和 T12 公告給 ED」）。原本是單一字串，
                 * 模型把兩個代號接成 `T11,T12` 回來，查不到就回
                 * 「找不到代號 T11,T12 的公告」—— 而兩個代號其實都存在。
                 */
                'template_codes' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => '公告的代號，例如 T01。他講了幾個就放幾個（「發 T11 和 T12」→ ["T11","T12"]），一個元素放一個代號、不要接成一串。沒講代號就給空陣列，不要猜一個',
                ],
                'target' => [
                    'type'        => ['string', 'null'],
                    'description' => '要發給誰：群組名稱原文（例如 LV_UAT，不要帶「群組」兩字），要發給所有客戶就填 all。沒講就填 null',
                ],
                'content' => [
                    'type'        => ['string', 'null'],
                    'description' => 'intent=message 時：要發出去的訊息內容，照抄原文不要改寫、不要補字、不要截斷，也不要包含時間與對象那段描述。純文字、不要放 JSON。其他 intent 一律填 null',
                ],
            ],
            'required' => ['intent', 'send_at', 'template_codes', 'target', 'content'],
        ];
    }
}
