<?php

namespace App\Services\AutoReply;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 把「給我三方規章」解析成要哪一份規章
 *
 * ⚠ **`title` 要原文照抄**。那會拿去 `LIKE` 比對 `project_regulation.title`
 * —— 模型自作主張改寫（「三方」→「三方金流」）就對不到了。
 *
 * ⚠ **`project` 是選填**：需求方給的例子（三方規章／跑分員規章／商城規章）
 * 都只講標題。有講專案才填，沒講就 null —— 不要自己從標題猜專案，
 * 「三方規章」不保證就在「三方」專案底下。
 */
class RegulationCommandParser
{
    /** @var string 要拿某一份規章 */
    const INTENT_REGULATION = 'regulation';

    /** @var string 不是在要規章（呼叫端要換下一個 handler） */
    const INTENT_NOT_REGULATION = 'not_regulation';

    /** @var string 是在要規章，但沒講要哪一份 */
    const INTENT_UNKNOWN = 'unknown';

    private $matcher;

    public function __construct(ClaudeCodeMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    /**
     * 解析一句話
     *
     * @param string $text 已經去掉 @機器人
     * @return array{intent: string, title: string|null, project: string|null}|null null = 模型不可用
     */
    public function parse($text)
    {
        $text = trim((string) $text);

        if (blank($text)) {
            return ['intent' => self::INTENT_UNKNOWN, 'title' => null, 'project' => null];
        }

        $config = (array) config('constants.REGULATION_COMMAND');

        try {
            $result = $this->matcher->generateStructured(
                (string) Arr::get($config, 'PROMPT'),
                strtr((string) Arr::get($config, 'INPUT'), ['{text}' => $text]),
                $this->schema()
            );
        } catch (\Exception $e) {
            Log::warning('規章指令解析失敗', ['error' => $e->getMessage()]);

            return null;
        }

        if (blank($result)) {
            return null;
        }

        return $this->normalize($result);
    }

    /**
     * @param array $result
     * @return array
     */
    private function normalize(array $result)
    {
        $intent = (string) Arr::get($result, 'intent', self::INTENT_UNKNOWN);

        if ($intent === self::INTENT_NOT_REGULATION) {
            return ['intent' => self::INTENT_NOT_REGULATION, 'title' => null, 'project' => null];
        }

        $title = trim((string) Arr::get($result, 'title'));

        if ($intent !== self::INTENT_REGULATION || blank($title)) {
            return ['intent' => self::INTENT_UNKNOWN, 'title' => null, 'project' => null];
        }

        $project = trim((string) Arr::get($result, 'project'));

        return [
            'intent'  => self::INTENT_REGULATION,
            'title'   => $title,
            'project' => filled($project) ? $project : null,
        ];
    }

    /**
     * @return array
     */
    private function schema()
    {
        return [
            'type'       => 'object',
            'properties' => [
                'intent' => [
                    'type' => 'string',
                    'enum' => [self::INTENT_REGULATION, self::INTENT_NOT_REGULATION, self::INTENT_UNKNOWN],
                    'description' => '要拿某一份規章（regulation）、不是在要規章（not_regulation）、是在要但沒講哪一份（unknown）',
                ],
                'title' => [
                    'type'        => ['string', 'null'],
                    'description' => '規章的名字，原文照抄不要改寫。例如三方規章、跑分員規章、商城規章。純文字、不要放 JSON。沒講就填 null',
                ],
                'project' => [
                    'type'        => ['string', 'null'],
                    'description' => '他有沒有講是哪一個專案的。有講才填，沒講就填 null。不要從規章名字去猜專案',
                ],
            ],
            'required' => ['intent', 'title', 'project'],
        ];
    }
}
