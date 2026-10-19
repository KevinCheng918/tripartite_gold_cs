<?php

namespace App\Services;

use App\Repositories\UserRepository;
use App\Services\AutoReply\SpiderSettingCommandParser;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 用 Telegram 指令做一份金流通道的排程設定（spider_setting）
 *
 * ```
 * @機器人 做一個 Ckpay 的代收排程，api 網址 https://… 商戶號 12345、金鑰 abcdef
 *   ↓
 * 🧩 Ckpay 的排程設定（代收）
 * {
 *     "index": "ckpay",
 *     "api_url": "https://…",
 *     "app_id": "12345",
 *     "app_key": "abcdef",
 *     "ping_url": "",
 *     "order_group": ""
 * }
 * ⚠️ 還缺 1 個必填欄位：ping_url
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
 *
 * ⚠ **代收與代付是兩份設定**（需求方 2026-10-19）：`payment` 一列只有一種
 * `type`，所以一個通道兩種都做的話是兩列、兩份 `spider_setting`。
 * 兩種都要就回兩段 JSON，不是把欄位混在一份裡。
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
     * @return bool 有處理就回 true；false 代表「這不是在講排程設定」
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

        $this->replySetting(
            $text,
            (string) Arr::get($detected, 'channel'),
            (string) Arr::get($detected, 'scope'),
            $threadId,
            $config
        );

        return true;
    }

    /**
     * 查欄位、對值、組 JSON
     *
     * @param string   $text
     * @param string   $channel
     * @param string   $wanted   使用者要代收／代付／兩種／沒講
     * @param int|null $threadId
     * @param array    $config
     * @return void
     */
    private function replySetting($text, $channel, $wanted, $threadId, array $config)
    {
        $code = $this->channelCode($channel);

        if (blank($code)) {
            $this->reply(strtr((string) Arr::get($config, 'NOT_FOUND_TEXT'), [
                '{channel}' => $channel,
            ]), $threadId);

            return;
        }

        $scopes = $this->resolveScopes($code, $wanted);

        /*
         * 他要的那一種這個通道不做（例如問 Sbs 的代收）。
         *
         * ⚠ 照樣組一份出來的話，他會拿著一份這個通道根本不做的設定去貼 ——
         * 寧可講清楚，不要給一份看起來沒問題的東西。
         */
        if (blank($scopes)) {
            $this->reply($this->unsupportedText($code, $wanted, $config), $threadId);

            return;
        }

        $groups = [];

        foreach ($scopes as $scope) {
            $groups[$scope] = $this->fieldGroups($code, $scope);
        }

        $values = $this->parser->extract($text, $code, $this->allKeys($groups));
        $failed = $values === null;

        /*
         * ⚠ `INDEX_NOTE` 印在**標題底下只印一次**，不要每一段都印 ——
         * 兩種都給的時候會變成同一句話說兩遍。
         */
        $reply = strtr((string) Arr::get($config, 'TITLE'), ['{channel}' => $code])
            . $this->renderNote($code, $config)
            . (string) Arr::get($config, 'INDEX_NOTE');

        // 沒講要哪一種、而且真的給了兩段時，要說明為什麼有兩段
        if ($wanted === SpiderSettingCommandParser::SCOPE_UNSAID && count($scopes) > 1) {
            $reply .= (string) Arr::get($config, 'UNSAID_NOTE');
        }

        foreach ($groups as $scope => $group) {
            $reply .= $this->renderBlock($code, $scope, $group, (array) $values, $config);
        }

        if ($failed) {
            $reply .= (string) Arr::get($config, 'EXTRACT_FAILED_HINT');
        }

        $this->reply($reply, $threadId);

        Log::info('小幫手產出排程設定', [
            'channel' => $code,
            'scopes'  => array_keys($groups),
            'failed'  => $failed,
            'filled'  => array_keys((array) $values),
        ]);
    }

    /**
     * 對照表上的通道代號
     *
     * ⚠ 比對前先**抹掉大小寫與底線、連字號、空白**：同一個通道會被寫成
     * `SelfHold`、`self_hold`、`self-hold`、`self hold`，而對照表上只有一種。
     * 跟站台名的比對（`StationNameMatcher`）同一套想法。
     *
     * @param string $channel
     * @return string 空字串 ＝ 清單裡沒有
     */
    private function channelCode($channel)
    {
        $needle = $this->normalizeCode($channel);

        if (blank($needle)) {
            return '';
        }

        foreach (array_keys((array) config('apollo_fields.CHANNELS')) as $code) {
            if ($this->normalizeCode($code) === $needle) {
                return (string) $code;
            }
        }

        return '';
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
     * 這次要產出哪幾種
     *
     * ⚠ **沒講（`unsaid`）就把這個通道做的都給**：猜一種的話，猜錯就是只給了
     * 他不要的那一份，他得再問一次；兩份都給最多是多看一段。
     *
     * ⚠ 他要的那種這個通道不做就回空陣列（呼叫端會講原因）。
     *
     * @param string $code
     * @param string $wanted
     * @return array<string> deposit／withdraw，照代收先的順序
     */
    private function resolveScopes($code, $wanted)
    {
        $supported = $this->supportedTypes($code);

        if ($wanted === SpiderSettingCommandParser::SCOPE_DEPOSIT) {
            return in_array(SpiderSettingCommandParser::SCOPE_DEPOSIT, $supported, true)
                ? [SpiderSettingCommandParser::SCOPE_DEPOSIT]
                : [];
        }

        if ($wanted === SpiderSettingCommandParser::SCOPE_WITHDRAW) {
            return in_array(SpiderSettingCommandParser::SCOPE_WITHDRAW, $supported, true)
                ? [SpiderSettingCommandParser::SCOPE_WITHDRAW]
                : [];
        }

        // both 與 unsaid 都是「這個通道做的都給」
        return $supported;
    }

    /**
     * 這個通道做代收還是代付
     *
     * @param string $code
     * @return array<string>
     */
    private function supportedTypes($code)
    {
        $types = (array) config('apollo_fields.TYPES');

        if (in_array($code, (array) Arr::get($types, 'both', []), true)) {
            return [SpiderSettingCommandParser::SCOPE_DEPOSIT, SpiderSettingCommandParser::SCOPE_WITHDRAW];
        }

        if (in_array($code, (array) Arr::get($types, 'deposit', []), true)) {
            return [SpiderSettingCommandParser::SCOPE_DEPOSIT];
        }

        if (in_array($code, (array) Arr::get($types, 'withdraw', []), true)) {
            return [SpiderSettingCommandParser::SCOPE_WITHDRAW];
        }

        /*
         * ⚠ 對照表沒分類到（主專案加了新通道、而 TYPES 還沒更新）時
         * **兩種都給**，不要回「不支援」—— 那會變成「新通道永遠做不出排程」，
         * 而使用者看不出是對照表過期了。
         */
        Log::warning('排程設定：通道沒有分類在 TYPES 裡', ['channel' => $code]);

        return [SpiderSettingCommandParser::SCOPE_DEPOSIT, SpiderSettingCommandParser::SCOPE_WITHDRAW];
    }

    /**
     * 某一種的欄位分三組
     *
     * - `required`：一律寫進 JSON（沒給就留空字串），沒填的列進「還缺」
     * - `optional`：**有給才寫進 JSON**
     *
     * ⚠ **選填的沒給就不要寫進 JSON**，兩個理由：
     * 1. `Arr::get($setting, 'currency', 'TWD')` 遇到 `"currency": ""` 回的是
     *    空字串**不是** `TWD` —— 空值會把程式裡的預設值蓋掉
     * 2. 需求方 2026-10-19：「不開的話，就連同 order_group 都不要」——
     *    開關型欄位留一個空值在設定裡，下一個看的人會以為有設過
     *
     * ⚠ `BASE` 一定要併進去（需求方 2026-10-19：「api_url 這些都要填寫
     * 上去」）：`api_url` 是 `Apollo.php` 基底在讀的，每個通道都要。
     *
     * ⚠ 選填再分成兩組：`optional` 是通道給的參數、`optional_page` 是
     * 收銀台／對帳在讀的（`EXTRA`）。混成一張清單的話，看的人會以為那些
     * 頁面欄位也要去問上游。
     *
     * @param string $code
     * @param string $scope
     * @return array{required: array<string>, optional: array<string>, optional_page: array<string>}
     */
    private function fieldGroups($code, $scope)
    {
        // 通道與基底的欄位
        $sources = [
            (array) config('apollo_fields.BASE.shared'),
            (array) config('apollo_fields.BASE.' . $scope),
            (array) config("apollo_fields.CHANNELS.{$code}.shared"),
            (array) config("apollo_fields.CHANNELS.{$code}.{$scope}"),
        ];

        // 不是 Apollo 讀的，是收銀台／對帳在讀的
        $extra = (array) config('apollo_fields.EXTRA.' . $scope);

        $required = (array) Arr::get($extra, 'required', []);
        $optionalPage = (array) Arr::get($extra, 'optional', []);
        $optional = [];

        foreach ($sources as $source) {
            $required = array_merge($required, (array) Arr::get($source, 'required', []));
            $optional = array_merge($optional, (array) Arr::get($source, 'optional', []));
        }

        /*
         * ⚠ 同一個鍵在兩處分類不同時**必填優先**（例如 `cashier_url` 在
         * `BASE` 是選填、在 `Totoken` 是必填）—— 少問一個必填會讓人貼上
         * 一份跑不起來的設定，多問一個選填只是多打一次字。
         */
        $required = array_values(array_unique($required));
        $optional = array_values(array_diff(array_unique($optional), $required));
        $optionalPage = array_values(array_diff(array_unique($optionalPage), $required, $optional));

        sort($required);
        sort($optional);
        sort($optionalPage);

        return [
            'required'      => $required,
            'optional'      => $optional,
            'optional_page' => $optionalPage,
        ];
    }

    /**
     * 這次要問模型的全部欄位（兩種合併、去重）
     *
     * @param array $groups
     * @return array<string>
     */
    private function allKeys(array $groups)
    {
        $keys = [];

        foreach ($groups as $group) {
            $keys = array_merge(
                $keys,
                (array) Arr::get($group, 'required', []),
                (array) Arr::get($group, 'optional', []),
                (array) Arr::get($group, 'optional_page', [])
            );
        }

        return array_values(array_unique($keys));
    }

    /**
     * 一種（代收或代付）的那一段
     *
     * @param string $code
     * @param string $scope
     * @param array  $group
     * @param array  $values
     * @param array  $config
     * @return string
     */
    private function renderBlock($code, $scope, array $group, array $values, array $config)
    {
        $required = (array) Arr::get($group, 'required', []);
        $optional = (array) Arr::get($group, 'optional', []);
        $optionalPage = (array) Arr::get($group, 'optional_page', []);

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
        $json = [self::INDEX_KEY => Str::snake($code)];

        /*
         * ⚠ **必填一律寫進 JSON，沒給值就留空字串**
         * （需求方 2026-10-19：「api_url 這些都要填寫上去」）——
         * 拿到的是一份可以直接填的範本，而不是一份要自己回頭查欄位名的殘缺品。
         */
        foreach ($required as $key) {
            $json[$key] = (string) Arr::get($values, $key, '');
        }

        // 選填只有給了才寫（理由見 fieldGroups()）
        foreach (array_merge($optional, $optionalPage) as $key) {
            if (filled(Arr::get($values, $key))) {
                $json[$key] = (string) Arr::get($values, $key);
            }
        }

        $text = strtr((string) Arr::get($config, 'SCOPE_TITLE'), [
            '{scope}' => (string) Arr::get($config, "SCOPE_LABEL.{$scope}"),
        ]);

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

        $missing = array_values(array_filter($required, function ($key) use ($values) {
            return blank(Arr::get($values, $key));
        }));

        if (filled($missing)) {
            $text .= strtr((string) Arr::get($config, 'MISSING_TITLE'), ['{count}' => count($missing)]);

            foreach ($missing as $field) {
                $text .= strtr((string) Arr::get($config, 'MISSING_LINE'), ['{field}' => $field]);
            }
        }

        // 選填的也列出來：沒講的話，人不知道還有這些可以調
        $text .= $this->renderOptional($optional, $values, 'OPTIONAL_TITLE', $config)
            . $this->renderOptional($optionalPage, $values, 'OPTIONAL_PAGE_TITLE', $config);

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
     * 一組還沒填的選填欄位
     *
     * @param array<string> $keys
     * @param array         $values
     * @param string        $titleKey
     * @param array         $config
     * @return string 全部都填了就回空字串（不印標題）
     */
    private function renderOptional(array $keys, array $values, $titleKey, array $config)
    {
        $rest = array_values(array_filter($keys, function ($key) use ($values) {
            return blank(Arr::get($values, $key));
        }));

        if (blank($rest)) {
            return '';
        }

        $text = (string) Arr::get($config, $titleKey);

        foreach ($rest as $field) {
            $text .= strtr((string) Arr::get($config, 'OPTIONAL_LINE'), ['{field}' => $field]);
        }

        return $text;
    }

    /**
     * 這個代號要不要多解釋一句（self／self_cashier／self_hold 那幾個）
     *
     * @param string $code
     * @param array  $config
     * @return string
     */
    private function renderNote($code, array $config)
    {
        $note = config("apollo_fields.NOTES.{$code}");

        if (blank($note)) {
            return '';
        }

        return strtr((string) Arr::get($config, 'CHANNEL_NOTE'), ['{note}' => $note]);
    }

    /**
     * 他要的那一種這個通道不做
     *
     * @param string $code
     * @param string $wanted
     * @param array  $config
     * @return string
     */
    private function unsupportedText($code, $wanted, array $config)
    {
        $supported = $this->supportedTypes($code);

        return strtr((string) Arr::get($config, 'UNSUPPORTED_TEXT'), [
            '{channel}'   => $code,
            '{wanted}'    => (string) Arr::get($config, "SCOPE_LABEL.{$wanted}"),
            '{supported}' => implode('、', array_map(function ($scope) use ($config) {
                return (string) Arr::get($config, "SCOPE_LABEL.{$scope}");
            }, $supported)),
        ]);
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
            Log::info('指令做排程設定被權限擋下', ['user_id' => $user->id]);

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
