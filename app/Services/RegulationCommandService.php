<?php

namespace App\Services;

use App\Repositories\ProjectRegulationRepository;
use App\Repositories\UserRepository;
use App\Services\AutoReply\RegulationCommandParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 小幫手取規章（「給我三方規章」）
 *
 * 需求方 2026-10-30：「我在小幫手這邊要可以取得規章，例如：三方規章、
 * 跑分員規章、商城規章。」
 *
 * ⚠ **對標題，撞名才問專案**（需求方選的）。找到一份就回那一份；
 * 不同專案底下有同名規章時**列出來問是哪一個**，不可以自己挑第一筆 ——
 * 這是規章，回錯一份比回「找不到」糟得多。
 *
 * ⚠ **內容超過 Telegram 單則上限就分段連發**（需求方選的）。
 * 規章多半是要整段貼給客戶或同仁看，截斷等於沒用。
 */
class RegulationCommandService
{
    /** @var string 這支指令要的權限 */
    const PERMISSION = 'telegram_chat.regulation';

    private $parser;
    private $regulationRepository;
    private $userRepository;
    private $supportGroup;

    public function __construct(
        RegulationCommandParser $parser,
        ProjectRegulationRepository $regulationRepository,
        UserRepository $userRepository,
        SupportGroupService $supportGroup
    ) {
        $this->parser = $parser;
        $this->regulationRepository = $regulationRepository;
        // 下指令的人要對到系統帳號才驗得了權限
        $this->userRepository = $userRepository;
        $this->supportGroup = $supportGroup;
    }

    /**
     * 處理一句指令
     *
     * @param string   $text     已經去掉 @機器人
     * @param array    $from     Telegram 的 from
     * @param int|null $threadId
     * @return bool 有處理就回 true；false 代表「這不是在要規章」，換下一個 handler
     */
    public function handle($text, array $from, $threadId)
    {
        $config = (array) config('constants.REGULATION_COMMAND');
        $parsed = $this->parser->parse($text);

        if (blank($parsed)) {
            $this->reply((string) Arr::get($config, 'UNAVAILABLE_TEXT'), $threadId);

            return true;
        }

        $intent = (string) Arr::get($parsed, 'intent');

        // 不是在要規章 —— 讓分派器換下一個
        if ($intent === RegulationCommandParser::INTENT_NOT_REGULATION) {
            return false;
        }

        $denied = $this->permissionError($from, $config);

        if (filled($denied)) {
            $this->reply($denied, $threadId);

            return true;
        }

        if ($intent !== RegulationCommandParser::INTENT_REGULATION) {
            $this->reply($this->availableText($config), $threadId);

            return true;
        }

        $this->replyRegulation($parsed, $threadId, $config);

        return true;
    }

    /**
     * 找出來並回覆
     *
     * @param array    $parsed
     * @param int|null $threadId
     * @param array    $config
     * @return void
     */
    private function replyRegulation(array $parsed, $threadId, array $config)
    {
        $title = (string) Arr::get($parsed, 'title');
        $matches = $this->regulationRepository->searchActiveByTitle($title);

        // 找不到就列出有哪些可以問 —— 絕不自己挑一份近似的
        if (blank($matches)) {
            $this->reply(strtr((string) Arr::get($config, 'NOT_FOUND'), [
                '{title}' => $title,
                '{list}'  => $this->titleList(),
            ]), $threadId);

            return;
        }

        $project = (string) Arr::get($parsed, 'project');

        // 有講專案就先照專案收斂
        if (filled($project)) {
            $narrowed = $matches->filter(function ($regulation) use ($project) {
                return filled($regulation->project)
                    && mb_strpos((string) $regulation->project->name, $project) !== false;
            })->values();

            if (filled($narrowed)) {
                $matches = $narrowed;
            }
        }

        /*
         * ⚠ **撞名時不挑，問清楚。** 不同專案底下可以有同名規章，
         * 挑第一筆就是賭 —— 而賭錯的後果是有人拿著別個專案的規則去對客戶。
         */
        if ($matches->count() > 1) {
            $this->reply(strtr((string) Arr::get($config, 'AMBIGUOUS'), [
                '{title}' => $title,
                '{list}'  => $this->projectList($matches),
            ]), $threadId);

            return;
        }

        $this->sendContent($matches->first(), $threadId, $config);
    }

    /**
     * 把規章內容發出去（太長就分段）
     *
     * ⚠ **Telegram 單則 4096 字，超過整則送不出去（不是截斷）。**
     * 所以超過就切段連發，每則標 `(1/3)` —— 不標的話看的人不知道
     * 還有沒有下文，而規章少看一段可能就是漏掉一條規則。
     *
     * @param \App\Models\ProjectRegulation $regulation
     * @param int|null                      $threadId
     * @param array                         $config
     * @return void
     */
    private function sendContent($regulation, $threadId, array $config)
    {
        $header = strtr((string) Arr::get($config, 'HEADER'), [
            '{title}'   => (string) $regulation->title,
            '{project}' => filled($regulation->project) ? (string) $regulation->project->name : '-',
        ]);

        $chunks = $this->split((string) $regulation->content, (int) Arr::get($config, 'CHUNK_SIZE'));
        $total = count($chunks);

        foreach ($chunks as $index => $chunk) {
            // 只有第一則掛標題；每則都標頁碼（只有一則時不標，那是噪音）
            $prefix = ($index === 0) ? $header : '';
            $page = $total > 1
                ? strtr((string) Arr::get($config, 'PAGE'), [
                    '{current}' => $index + 1,
                    '{total}'   => $total,
                ])
                : '';

            $this->reply($prefix . $page . $chunk, $threadId);
        }

        Log::info('小幫手已回覆規章', [
            'regulation_id' => $regulation->id,
            'chunks'        => $total,
        ]);
    }

    /**
     * 把長內容切成幾段
     *
     * ⚠ **盡量從換行處切**，不要切在句子中間。先找這一段裡最後一個換行，
     * 找不到才硬切 —— 規章是條列式的，從行尾切幾乎都切得乾淨。
     *
     * ⚠ 用 `mb_substr` 不是 `substr`：中文一個字三個位元組，用位元組切
     * 會把字切成亂碼。
     *
     * @param string $content
     * @param int    $size 每段最多幾個字
     * @return array<string>
     */
    private function split($content, $size)
    {
        $content = trim($content);

        // 短路：放得下就不必切
        if ($size < 1 || mb_strlen($content) <= $size) {
            return [$content];
        }

        $chunks = [];

        while (mb_strlen($content) > $size) {
            $slice = mb_substr($content, 0, $size);
            $breakAt = mb_strrpos($slice, "\n");

            // 整段都沒有換行（或換行太前面）就硬切，不然會切出一則很短的
            $cut = ($breakAt !== false && $breakAt > (int) ($size / 2)) ? $breakAt : $size;

            $chunks[] = trim(mb_substr($content, 0, $cut));
            $content = ltrim(mb_substr($content, $cut));
        }

        if (filled($content)) {
            $chunks[] = $content;
        }

        return $chunks;
    }

    /**
     * 有哪些規章可以問
     *
     * @return string
     */
    private function titleList()
    {
        $titles = $this->regulationRepository->getActiveTitles();

        if (blank($titles)) {
            return (string) config('constants.REGULATION_COMMAND.NO_REGULATION');
        }

        return $titles->map(function ($regulation) {
            $project = filled($regulation->project) ? (string) $regulation->project->name : '-';

            return "・{$regulation->title}（{$project}）";
        })->implode("\n");
    }

    /**
     * 撞名時列出是哪幾個專案的
     *
     * @param \Illuminate\Database\Eloquent\Collection $matches
     * @return string
     */
    private function projectList($matches)
    {
        return $matches->map(function ($regulation) {
            $project = filled($regulation->project) ? (string) $regulation->project->name : '-';

            return "・{$project} 的「{$regulation->title}」";
        })->implode("\n");
    }

    /**
     * 是在要規章但沒講哪一份時，回有哪些可以問
     *
     * @param array $config
     * @return string
     */
    private function availableText(array $config)
    {
        return strtr((string) Arr::get($config, 'UNKNOWN_TEXT'), ['{list}' => $this->titleList()]);
    }

    /**
     * 沒權限時要回的話；有權限回空字串
     *
     * ⚠ 對不到系統帳號也算沒權限 —— 不能因為「查不到這個人」就放行。
     *
     * @param array $from
     * @param array $config
     * @return string
     */
    private function permissionError(array $from, array $config)
    {
        $user = $this->userRepository->findByTelegramUserId((int) Arr::get($from, 'id'));

        if (blank($user)) {
            return (string) Arr::get($config, 'NOT_BOUND_TEXT');
        }

        if (!$user->hasPermission(self::PERMISSION)) {
            return (string) Arr::get($config, 'NO_PERMISSION_TEXT');
        }

        return '';
    }

    /**
     * 回到原本那個話題
     *
     * @param string   $text
     * @param int|null $threadId
     * @return void
     */
    private function reply($text, $threadId)
    {
        $this->supportGroup->sendToThread($text, $threadId);
    }
}
