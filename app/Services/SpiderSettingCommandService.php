<?php

namespace App\Services;

use App\Repositories\UserRepository;
use App\Services\AutoReply\SpiderSettingCommandParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 用 Telegram 指令做一份 Apollo 的 spider_setting
 *
 * ```
 * @機器人 做一個 Ckpay 的 spider_setting，商戶號 12345、金鑰 abcdef
 *   ↓
 * 🧩 Ckpay 的 spider_setting
 * {
 *     "index": "Ckpay",
 *     "app_id": "12345",
 *     "app_key": "abcdef"
 * }
 * ⚠️ 還缺 3 個必填欄位：api_url、check_url、ping_url
 * ```
 *
 * ⚠ **只產出文字，不碰主系統的資料庫**（需求方 2026-10-19）。客服系統本來
 * 就沒有寫主系統 `payment` 表的管道，而金流設定寫錯會直接影響收款。
 *
 * ⚠ **欄位清單不是模型想的，是查 `config('apollo_fields')`** —— 那是從主專案
 * `app/Apollos/*Apollo.php` 掃出來的快照。讓模型自己想欄位名，它會編出
 * Apollo 根本沒讀的鍵，而這份 JSON 是要貼進金流設定的。
 *
 * ⚠ **`index` 一定要有。** `Payment.php` 與每一支回調都是靠
 * `spider_setting.index` 找回對應的 Apollo —— 少了它整個通道接不起來。
 */
class SpiderSettingCommandService
{
    /** @var string 做這件事需要的權限 */
    const PERMISSION = 'telegram_chat.spider_setting';

    /** @var string Apollo 靠這個 key 找回自己，產出一定要帶 */
    const INDEX_KEY = 'index';

    private $parser;
    private $userRepository;
    private $supportGroup;

    public function __construct(
        SpiderSettingCommandParser $parser,
        UserRepository $userRepository,
        SupportGroupService $supportGroup
    ) {
        $this->parser = $parser;
        $this->userRepository = $userRepository;
        $this->supportGroup = $supportGroup;
    }

    /**
     * 處理一句指令
     *
     * @param string   $text     已經去掉 @機器人
     * @param array    $from     Telegram 的 from（對到系統帳號驗權限用）
     * @param int|null $threadId
     * @return bool 有處理就回 true；false 代表「這不是在講 spider_setting」
     */
    public function handle($text, array $from, $threadId)
    {
        $config = (array) config('constants.SPIDER_SETTING_COMMAND');
        $detected = $this->parser->detect($text);

        if (blank($detected)) {
            $this->reply((string) Arr::get($config, 'UNAVAILABLE_TEXT'), $threadId);

            return true;
        }

        $intent = (string) Arr::get($detected, 'intent');

        // 不是在講這件事 —— 讓分派器換下一個
        if ($intent === SpiderSettingCommandParser::INTENT_NOT_SPIDER) {
            return false;
        }

        $denied = $this->permissionError($from, $config);

        if (filled($denied)) {
            $this->reply($denied, $threadId);

            return true;
        }

        if ($intent !== SpiderSettingCommandParser::INTENT_SPIDER) {
            $this->reply((string) Arr::get($config, 'UNKNOWN_TEXT'), $threadId);

            return true;
        }

        $this->replySetting($text, (string) Arr::get($detected, 'channel'), $threadId, $config);

        return true;
    }

    /**
     * 查欄位、對值、組 JSON
     *
     * @param string   $text
     * @param string   $channel
     * @param int|null $threadId
     * @param array    $config
     * @return void
     */
    private function replySetting($text, $channel, $threadId, array $config)
    {
        $spec = $this->channelSpec($channel);

        if (blank($spec)) {
            $this->reply(strtr((string) Arr::get($config, 'NOT_FOUND_TEXT'), [
                '{channel}' => $channel,
            ]), $threadId);

            return;
        }

        $code = (string) Arr::get($spec, 'code');
        $required = (array) Arr::get($spec, 'required', []);
        $optional = (array) Arr::get($spec, 'optional', []);

        $values = $this->parser->extract($text, $code, $required, $optional);

        /*
         * ⚠ 一個欄位都沒對到時**照樣往下走**（空陣列）—— 只回一句
         * 「沒讀到資料」的話，使用者看不到這個通道到底要哪些欄位，
         * 而那正是他最需要的。只是單純問「Ckpay 要填什麼」也走這條路。
         *
         * ⚠ 但模型掛掉（`null`）要**跟「沒給值」分開講**：不說的話，
         * 使用者會以為自己剛才打的商戶號格式不對，然後一直重打。
         */
        $failed = $values === null;

        $reply = $this->render($code, (array) $values, $required, $optional, $config);

        if ($failed) {
            $reply .= (string) Arr::get($config, 'EXTRACT_FAILED_HINT');
        }

        $this->reply($reply, $threadId);

        Log::info('小幫手產出 spider_setting', [
            'channel' => $code,
            'failed'  => $failed,
            'filled'  => array_keys((array) $values),
            'missing' => array_values(array_diff($required, array_keys((array) $values))),
        ]);
    }

    /**
     * 這個通道的欄位規格
     *
     * ⚠ 比對前先**抹掉大小寫與底線、連字號、空白**：同一個通道會被寫成
     * `SelfHold`、`self_hold`、`self-hold`、`self hold`，而對照表上只有一種。
     * 跟站台名的比對（`StationNameMatcher`）同一套想法。
     *
     * @param string $channel
     * @return array{code: string, required: array, optional: array}|null
     */
    private function channelSpec($channel)
    {
        $needle = $this->normalizeCode($channel);

        if (blank($needle)) {
            return null;
        }

        foreach ((array) config('apollo_fields.CHANNELS') as $code => $spec) {
            if ($this->normalizeCode($code) !== $needle) {
                continue;
            }

            return [
                'code'     => (string) $code,
                'required' => (array) Arr::get($spec, 'required', []),
                'optional' => (array) Arr::get($spec, 'optional', []),
            ];
        }

        return null;
    }

    /**
     * 通道代號的比對用寫法
     *
     * @param string $code
     * @return string
     */
    private function normalizeCode($code)
    {
        return str_replace(['_', '-', ' '], '', mb_strtolower(trim((string) $code)));
    }

    /**
     * 組出整則回覆
     *
     * @param string                $code     通道代號（已經是對照表上的寫法）
     * @param array<string, string> $values
     * @param array<string>         $required
     * @param array<string>         $optional
     * @param array                 $config
     * @return string
     */
    private function render($code, array $values, array $required, array $optional, array $config)
    {
        /*
         * ⚠ `index` 擺第一個，而且**要用 snake_case**（`ckpay`、`self_hold`），
         * 不是 Apollo 的類別名（`Ckpay`、`SelfHold`）。
         *
         * 解析 Apollo 時兩種都通（`Apollo::get()` 會先 `Str::studly()`），
         * 但主專案另外有幾處**字串直接比對** `index`：
         * `Payment::isSelfHold()` 比 `=== 'self_hold'`、
         * 轉單費率用 `Str::is('self*', $index)`、
         * `AvatarCallback` 的 USDT 回調有 `case 'chno'`。
         * 寫成 `SelfHold` 的話通道叫得起來，但自家轉單會被收錯費率 ——
         * 那種錯不會報，要等對帳才發現。
         */
        $json = array_merge([self::INDEX_KEY => Str::snake($code)], $values);

        $text = strtr((string) Arr::get($config, 'TITLE'), ['{channel}' => $code]);

        /*
         * ⚠ **不要自己 `e()`** —— `sendToThread()` 底下的 `escapeHtml()`
         * 已經會轉義（`&` → `&amp;`、`<` → `&lt;`），再轉一次會讓
         * api_url 裡的 `&` 在群組裡顯示成 `&amp;`，複製出去就是錯的網址。
         *
         * ⚠ `JSON_UNESCAPED_SLASHES` 是必要的：少了它網址會變成
         * `https:\/\/...`，雖然 JSON 合法，但貼進後台欄位看起來像壞的。
         */
        $text .= strtr((string) Arr::get($config, 'JSON_BLOCK'), [
            '{json}' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]);

        $text .= (string) Arr::get($config, 'INDEX_NOTE');

        $missing = array_values(array_diff($required, array_keys($values)));

        if (filled($missing)) {
            $text .= strtr((string) Arr::get($config, 'MISSING_TITLE'), ['{count}' => count($missing)]);

            foreach ($missing as $field) {
                $text .= strtr((string) Arr::get($config, 'MISSING_LINE'), ['{field}' => $field]);
            }
        }

        // 選填的也列出來：沒講的話，人不知道還有這些可以調
        $restOptional = array_values(array_diff($optional, array_keys($values)));

        if (filled($restOptional)) {
            $text .= (string) Arr::get($config, 'OPTIONAL_TITLE');

            foreach ($restOptional as $field) {
                $text .= strtr((string) Arr::get($config, 'OPTIONAL_LINE'), ['{field}' => $field]);
            }
        }

        /*
         * ⚠ 結語擺**兩張清單都印完之後**。夾在中間的話會變成
         * 「把缺的補上再傳一次」底下又接一段選填欄位，看的人會以為
         * 那些也是要補的。
         */
        return $text . (string) Arr::get(
            $config,
            filled($missing) ? 'MISSING_HINT' : 'COMPLETE_HINT'
        );
    }

    /**
     * 這個人能不能用 —— 不能的話回要講的那句話
     *
     * @param array $from
     * @param array $config
     * @return string 空字串 ＝ 可以
     */
    private function permissionError(array $from, array $config)
    {
        $telegramId = Arr::get($from, 'id');

        // 短路：連 Telegram id 都沒有就不必查資料庫
        if (blank($telegramId)) {
            return (string) Arr::get($config, 'NOT_BOUND_TEXT');
        }

        $user = $this->userRepository->findByTelegramUserId((int) $telegramId);

        if (blank($user)) {
            return (string) Arr::get($config, 'NOT_BOUND_TEXT');
        }

        if (!$user->hasPermission(self::PERMISSION)) {
            Log::info('指令做 spider_setting 被權限擋下', ['user_id' => $user->id]);

            return (string) Arr::get($config, 'NO_PERMISSION_TEXT');
        }

        return '';
    }

    /**
     * @param string   $text
     * @param int|null $threadId
     * @return void
     */
    private function reply($text, $threadId)
    {
        $this->supportGroup->sendToThread($text, $threadId);
    }
}
