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
     * ⚠ **不掛規章名稱與專案的抬頭**（需求方 2026-10-30）：這則訊息多半是
     * 要直接轉給客戶或同仁看的，抬頭是我們內部怎麼歸檔的事 —— 貼過去還得
     * 先手動刪掉那兩行。規章內容本身的第一行通常就是它自己的標題。
     *
     * @param \App\Models\ProjectRegulation $regulation
     * @param int|null                      $threadId
     * @param array                         $config
     * @return void
     */
    private function sendContent($regulation, $threadId, array $config)
    {
        $chunks = $this->split(
            (string) $regulation->content,
            (int) Arr::get($config, 'MAX_LENGTH'),
            (int) Arr::get($config, 'PAGE_RESERVE')
        );
        $total = count($chunks);

        foreach ($chunks as $index => $chunk) {
            // 每則都標頁碼（只有一則時不標，那是噪音）
            $page = $total > 1
                ? strtr((string) Arr::get($config, 'PAGE'), [
                    '{current}' => $index + 1,
                    '{total}'   => $total,
                ])
                : '';

            $this->reply("{$page}{$chunk}", $threadId);
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
     * ⚠ **長度一律用 UTF-16 單位量（`utf16Length()`），不是 `mb_strlen()`**：
     * Telegram 的 4096 是 UTF-16 code unit，BMP 外的 emoji 一個算兩單位。
     * 用 code point 數去比對會低估 —— emoji 很多的規章會「算起來沒超過但
     * 實際整則送不出去」。
     *
     * ⚠ **放得下就一則發完，那時候不要扣頁碼的位置**（bugfix 2026-10-30）：
     * 原本固定抓 3500「留餘裕」，結果一份 4,046 單位、明明放得下的規章被切
     * 成兩則。只有真的要分段時，每則才扣掉 `$reserve` 給頁碼。
     *
     * @param string $content
     * @param int    $max     單則硬上限（UTF-16 單位）
     * @param int    $reserve 要分段時每則留給頁碼的長度
     * @return array<string>
     */
    private function split($content, $max, $reserve)
    {
        $content = trim($content);

        // 短路：整份放得下就一則，連頁碼都不會掛上去，所以用完整的 $max 判斷
        if ($max < 1 || $this->utf16Length($content) <= $max) {
            return [$content];
        }

        // 到這裡確定要分段 → 每一則都會掛頁碼，可用長度要先扣掉
        $size = $max - $reserve;

        if ($size < 1) {
            return [$content];
        }

        $chunks = [];

        while ($this->utf16Length($content) > $size) {
            $slice = $this->sliceToUtf16Limit($content, $size);
            $sliceLength = mb_strlen($slice);
            $breakAt = mb_strrpos($slice, "\n");

            // 整段都沒有換行（或換行太前面）就硬切，不然會切出一則很短的
            $cut = ($breakAt !== false && $breakAt > (int) ($sliceLength / 2)) ? $breakAt : $sliceLength;

            $chunks[] = trim(mb_substr($content, 0, $cut));
            $content = ltrim(mb_substr($content, $cut));
        }

        if (filled($content)) {
            $chunks[] = $content;
        }

        return $chunks;
    }

    /**
     * 取「UTF-16 長度不超過 $limit」的最長前綴
     *
     * ⚠ 不能直接 `mb_substr($content, 0, $limit)`：那是 $limit 個 **code
     * point**，UTF-16 長度會 ≥ $limit（emoji 一個算兩單位），有可能超過。
     *
     * ⚠ **用二分搜尋找最長的那個前綴，不要「量出超出量就往回砍」**：
     * 那種回退法在 emoji 很多時會砍過頭 —— 4,000 個 emoji（8,000 單位）
     * 第一刀會從 4,000 退到 80 個字，結果一份規章被切成一堆一兩百單位的
     * 碎訊息（實測 6 則、長度 166／326／646⋯⋯）。每則都沒超過上限、也沒掉字，
     * 但群組裡會被刷一排短訊息。
     *
     * ⚠ 上界取 `min($limit, 字數)`：超過 $limit 個 code point 的前綴，
     * UTF-16 長度一定超過 $limit（每個 code point 至少一單位），不必試。
     *
     * ⚠ `mb_substr` 以 code point 為單位，**不會把 surrogate pair 切壞**。
     * 但由多個 code point 組成的組合字（例如 👨🏻‍💻 是 ZWJ 序列）硬切時
     * 可能被拆成好幾個圖案 —— 優先從換行切就是為了讓這種情況少發生。
     *
     * @param string $content
     * @param int    $limit UTF-16 單位
     * @return string
     */
    private function sliceToUtf16Limit($content, $limit)
    {
        $low = 0;
        $high = min($limit, mb_strlen($content));

        while ($low < $high) {
            // ceil：取中點時要往上取，不然 $low 停在 $high - 1 會無限迴圈
            $mid = (int) ceil(($low + $high) / 2);

            if ($this->utf16Length(mb_substr($content, 0, $mid)) <= $limit) {
                $low = $mid;

                continue;
            }

            $high = $mid - 1;
        }

        return mb_substr($content, 0, $low);
    }

    /**
     * 字串的 UTF-16 code unit 長度（Telegram 算長度與 entity offset 的單位）
     *
     * ⚠ `TelegramChatService` 也有一支同名的私有方法，用途是算 entity
     * offset。兩邊都只是同一個換算式（UTF-16LE 的位元組數除以 2），
     * 沒有任何判斷，所以各自留一份不會分岔。
     *
     * @param string $text
     * @return int
     */
    private function utf16Length($text)
    {
        return (int) (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2);
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
