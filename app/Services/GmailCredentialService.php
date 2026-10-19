<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Gmail 爬蟲憑證驗證
 *
 * 三步：三組憑證都有填 → 換得到 access_token → 連得上 Gmail。
 *
 * ⚠ **這支是主專案 `GmailCredentialService` 的對應版本**
 * （`tripartite_gold/app/Services/GmailCredentialService.php`）。
 * 客服系統叫不到那一支 —— 它的對外入口是後台 session ＋
 * `permission:system:payment-edit` 的 ajax 路由，而且要吃一個既有的
 * `{payment}`（第三步要它的 `bank_code`）。
 *
 * ⚠ **錯誤對照表照抄，不要自己猜**：`client_id` 與 `client_secret` 錯的時候
 * Google 回的 `error` **都是 `invalid_client`**，只有 `error_description`
 * 分得出是哪一組 —— 那份對照表是主專案實測出來的，重新猜一遍只會猜錯，
 * 而猜錯的結果是叫人去改根本沒壞的那一組。
 *
 * ⚠ **搜不到信仍算通過**（照主專案的規則）：那只代表這幾天沒有入帳，
 * 憑證本身是對的。把「沒有入帳」說成「憑證錯」會讓人去改沒壞的設定。
 */
class GmailCredentialService
{
    /** @var string 用 refresh_token 換 access_token */
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** @var string 信箱基本資料（地址、總信數） */
    const PROFILE_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

    /** @var string 搜信 */
    const MESSAGES_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages';

    /**
     * ⚠ 逾時設短：這是人在 Telegram 等著的時候才跑的。
     * 跟主專案後台的 15 秒同一個理由。
     */
    const TIMEOUT = 15;

    const STEP_PARAMS  = 'params';
    const STEP_TOKEN   = 'token';
    const STEP_MAILBOX = 'mailbox';

    const STEPS = [
        self::STEP_PARAMS,
        self::STEP_TOKEN,
        self::STEP_MAILBOX,
    ];

    const STATUS_PASS = 'pass';
    const STATUS_FAIL = 'fail';
    const STATUS_SKIP = 'skip';

    /** @var array<string> 必填的三組憑證 */
    const REQUIRED_FIELDS = [
        'client_id',
        'client_secret',
        'refresh_token',
    ];

    /*
     * 排程設定的基底，跟主專案建立通道時（`PaymentController::resolveSpiderSetting()`）
     * 產生的那組一致。
     */

    /** @var string Gmail 爬蟲掛在代收通道底下，所以是 cashier 不是 gmail */
    const CASHIER_INDEX = 'cashier';

    /** @var string 收銀台不顯示的欄位 */
    const CASHIER_NOT_SHOW = 'overtime_at';

    /** @var string 收銀台給付款人看的提示；固定文案 */
    const CASHIER_NOTE = '1.收款帳戶屬於一次性收款帳號<請勿保存帳號>再次轉帳。2.訂單失效後請勿轉帳，避免資金遺失。';

    const ERROR_INVALID_CLIENT = 'invalid_client';
    const ERROR_INVALID_GRANT  = 'invalid_grant';

    /**
     * Google 的錯誤描述 => 其實是哪一組憑證錯
     *
     * ⚠ key 一律**小寫、不帶結尾句點**，比對前會先正規化 —— Google 同一種
     * 錯誤有時給 `The OAuth client was not found.`、有時不帶句點。
     *
     * @var array<string, string>
     */
    const TOKEN_ERROR_HINTS = [
        'the oauth client was not found'        => 'client_id',
        'unauthorized'                          => 'client_secret',
        'the provided client secret is invalid' => 'client_secret',
        'bad request'                           => 'refresh_token',
        'token has been expired or revoked'     => 'refresh_token',
    ];

    /** @var string 非 Gmail 的信箱會回這個 */
    const ERROR_FAILED_PRECONDITION = 'FAILED_PRECONDITION';

    /** @var Client 一次驗證會打 Google 兩到三次，共用同一個 client */
    private $client;

    public function __construct()
    {
        $this->client = new Client(['timeout' => self::TIMEOUT]);
    }

    /**
     * 驗證整組憑證
     *
     * @param array       $credentials client_id / client_secret / refresh_token
     * @param string|null $bankCode    有給就順手確認收得到該銀行的入帳通知
     * @return array{success: bool, steps: array<int, array{key: string, status: string, detail: array}>}
     */
    public function verify(array $credentials, $bankCode = null)
    {
        $steps = [];

        $missing = $this->missingFields($credentials);

        // 短路：沒填齊就不必白打 Google
        if (filled($missing)) {
            $steps[] = $this->step(self::STEP_PARAMS, self::STATUS_FAIL, [
                'reason' => 'missing',
                'fields' => $missing,
            ]);

            return $this->result(false, $this->fillUnreached($steps));
        }

        $steps[] = $this->step(self::STEP_PARAMS, self::STATUS_PASS, []);

        $token = $this->fetchAccessToken($credentials, $steps);

        if (blank($token)) {
            return $this->result(false, $this->fillUnreached($steps));
        }

        $this->checkMailbox($token, $bankCode, $steps);

        return $this->result(blank($this->firstFailure($steps)), $steps);
    }

    /**
     * 用驗過的憑證組一份排程設定
     *
     * ⚠ **不讀通道現有的 `spider_setting`**，直接照建立通道時的基底重組
     * —— 跟主專案同一個做法，避免把舊的殘留設定帶進來。
     *
     * ⚠ `cashier_url` **刻意留空**（需求方 2026-10-19）：主專案是用那個站台
     * 自己的資料庫名稱與後台皮膚算出來的（`Converter::getCashierUrl()`），
     * 客服系統兩個都沒有。這個欄位錯了收銀台會連到別的站 ——
     * 寧可叫人照後台現有的值補上，也不要猜一個填進去。
     *
     * @param array $credentials 已驗證的三組憑證
     * @return array<string, string> 照順序排好的設定（呼叫端自己轉 JSON）
     */
    public function buildSpiderSetting(array $credentials)
    {
        $setting = [
            'index'       => self::CASHIER_INDEX,
            'cashier_url' => '',
            'not_show'    => self::CASHIER_NOT_SHOW,
        ];

        foreach (self::REQUIRED_FIELDS as $field) {
            $setting[$field] = (string) Arr::get($credentials, $field, '');
        }

        // note 固定放最後，跟主專案的順序一致
        $setting['note'] = self::CASHIER_NOTE;

        return $setting;
    }

    /**
     * 哪幾組沒填
     *
     * @param array $credentials
     * @return array<string>
     */
    private function missingFields(array $credentials)
    {
        $missing = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (blank(Arr::get($credentials, $field))) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * 用 refresh_token 換 access_token
     *
     * 這一步就能判定三組憑證對不對。
     *
     * @param array $credentials
     * @param array $steps
     * @return string 換不到時回空字串
     */
    private function fetchAccessToken(array $credentials, array &$steps)
    {
        try {
            $body = $this->request(self::TOKEN_URL, 'POST', [
                'form_params' => [
                    'client_id'     => Arr::get($credentials, 'client_id'),
                    'client_secret' => Arr::get($credentials, 'client_secret'),
                    'refresh_token' => Arr::get($credentials, 'refresh_token'),
                    'grant_type'    => 'refresh_token',
                ],
            ]);

            $token = (string) Arr::get($body, 'access_token', '');

            /*
             * ⚠ HTTP 200 但沒有 access_token —— 照主專案的規則當失敗處理。
             * Google 正常不會這樣回，但沒有 token 後面兩步也做不下去，
             * 讓它走到「拿 null 當 token 去打 Gmail」只會得到一個看不懂的錯。
             */
            if (blank($token)) {
                $steps[] = $this->step(self::STEP_TOKEN, self::STATUS_FAIL, ['reason' => 'empty']);

                return '';
            }

            $steps[] = $this->step(self::STEP_TOKEN, self::STATUS_PASS, [
                'expires_in' => (int) Arr::get($body, 'expires_in', 0),
            ]);

            return $token;
        } catch (\Exception $e) {
            $this->writeErrorLog(self::STEP_TOKEN, $e);

            $steps[] = $this->step(self::STEP_TOKEN, self::STATUS_FAIL, $this->tokenErrorDetail($e));

            return '';
        }
    }

    /**
     * 把 Google 的錯誤翻成「哪一組憑證有問題」
     *
     * @param \Exception $e
     * @return array{reason: string, field?: string, error?: string}
     */
    private function tokenErrorDetail(\Exception $e)
    {
        $body        = $this->exceptionBody($e);
        $error       = (string) Arr::get($body, 'error', '');
        $description = (string) Arr::get($body, 'error_description', '');

        // 先用描述判 —— 這是唯一能區分 client_id 與 client_secret 的線索
        $field = Arr::get(self::TOKEN_ERROR_HINTS, $this->normalizeDescription($description));

        if (filled($field)) {
            return ['reason' => 'field', 'field' => $field];
        }

        if ($error === self::ERROR_INVALID_GRANT) {
            return ['reason' => 'field', 'field' => 'refresh_token'];
        }

        /*
         * Google 改了描述文字時的退路：至少講得出是 client 那一組
         * （client_id 或 client_secret 其中之一）。
         */
        if ($error === self::ERROR_INVALID_CLIENT) {
            return [
                'reason' => 'client',
                'error'  => filled($description) ? $description : $error,
            ];
        }

        // 連線失敗或非預期回應，把原始訊息帶出來供排查
        return [
            'reason' => 'unknown',
            'error'  => filled($error) ? $error : $e->getMessage(),
        ];
    }

    /**
     * 連 Gmail：先確認是哪個信箱，有給銀行就再搜該銀行的入帳通知
     *
     * ⚠ **信箱地址一定要回**（需求方 2026-10-19 選的）：拿錯帳號的憑證一樣
     * 驗得過，看得到信箱才能當場發現「這不是我要的那個信箱」。
     *
     * @param string      $token
     * @param string|null $bankCode
     * @param array       $steps
     * @return void
     */
    private function checkMailbox($token, $bankCode, array &$steps)
    {
        try {
            $profile = $this->request(self::PROFILE_URL, 'GET', [], $token);
        } catch (\Exception $e) {
            $this->writeErrorLog(self::STEP_MAILBOX, $e);

            $steps[] = $this->step(self::STEP_MAILBOX, self::STATUS_FAIL, $this->mailboxErrorDetail($e));

            return;
        }

        $detail = [
            'email'          => (string) Arr::get($profile, 'emailAddress', ''),
            'messages_total' => (int) Arr::get($profile, 'messagesTotal', 0),
        ];

        $steps[] = $this->step(self::STEP_MAILBOX, self::STATUS_PASS, $detail + $this->searchBankMail($token, $bankCode));
    }

    /**
     * 順手搜該銀行最近幾天的入帳通知
     *
     * ⚠ **搜不到信不算失敗**，也不會讓整次驗證不通過 —— 那只代表這幾天沒有
     * 入帳。這裡回的是附加資訊，不是判定依據。
     *
     * ⚠ 搜信本身出錯（例如 Google 限流）也只是少一段附加資訊：信箱那一步
     * 已經過了，憑證確定是對的，不該因為附加資訊失敗就說憑證壞了。
     *
     * @param string      $token
     * @param string|null $bankCode
     * @return array{bank?: array}
     */
    private function searchBankMail($token, $bankCode)
    {
        // 短路：沒講銀行就不打這一次
        if (blank($bankCode)) {
            return [];
        }

        $bank = config("gmail_bank_queries.BANKS.{$bankCode}");

        // 這家銀行沒有對應的爬蟲（例如純第三方通道），無從搜起
        if (blank($bank)) {
            return ['bank' => ['code' => $bankCode, 'supported' => false]];
        }

        $days = (int) config('gmail_bank_queries.DAYS', 2);
        $query = Arr::get($bank, 'query') . " newer_than:{$days}d";

        try {
            $body = $this->request(
                self::MESSAGES_URL . '?' . http_build_query(['q' => $query]),
                'GET',
                [],
                $token
            );

            return ['bank' => [
                'code'      => $bankCode,
                'name'      => (string) Arr::get($bank, 'name'),
                'supported' => true,
                'days'      => $days,
                /*
                 * ⚠ 沒有結果時 Google 連 `messages` 欄位都不會有
                 * （只回 `resultSizeEstimate: 0`），所以要 `(array)` 再數。
                 */
                'count'     => count((array) Arr::get($body, 'messages', [])),
            ]];
        } catch (\Exception $e) {
            $this->writeErrorLog(self::STEP_MAILBOX, $e, ['bank_code' => $bankCode]);

            return ['bank' => [
                'code'      => $bankCode,
                'name'      => (string) Arr::get($bank, 'name'),
                'supported' => true,
                'failed'    => true,
            ]];
        }
    }

    /**
     * 連 Gmail 失敗的原因
     *
     * 用了非 Gmail 的信箱會回 `FAILED_PRECONDITION` / `Mail service not enabled`。
     *
     * @param \Exception $e
     * @return array{reason: string, error?: string}
     */
    private function mailboxErrorDetail(\Exception $e)
    {
        $body = $this->exceptionBody($e);
        $status = (string) Arr::get($body, 'error.status', '');
        $message = (string) Arr::get($body, 'error.message', '');

        if ($status === self::ERROR_FAILED_PRECONDITION) {
            return ['reason' => 'not_gmail'];
        }

        return [
            'reason' => 'unknown',
            'error'  => filled($message) ? $message : $e->getMessage(),
        ];
    }

    /**
     * 正規化 error_description 以便查表
     *
     * 去掉大小寫、頭尾空白與結尾句點的差異 —— Google 同一種錯誤的描述偶爾會
     * 帶或不帶句點，硬比字串會漏掉。
     *
     * @param string $description
     * @return string
     */
    private function normalizeDescription($description)
    {
        return rtrim(strtolower(trim((string) $description)), '.');
    }

    /**
     * 記錄對 Google 的請求異常
     *
     * ⚠ **不記憑證內容**（照主專案的規則）：只記錯誤碼與描述。
     * 憑證填錯是預期內的驗證結果，不是什麼需要留證的異常。
     *
     * @param string     $step
     * @param \Exception $e
     * @param array      $extra
     * @return void
     */
    private function writeErrorLog($step, \Exception $e, array $extra = [])
    {
        $body = $this->exceptionBody($e);

        Log::warning('Gmail 憑證驗證失敗', [
            'step'        => $step,
            'error'       => Arr::get($body, 'error', Arr::get($body, 'error.status', '')),
            'description' => Arr::get($body, 'error_description', Arr::get($body, 'error.message', '')),
            'exception'   => $e->getMessage(),
        ] + $extra);
    }

    /**
     * @param string $url
     * @param string $method
     * @param array  $options 額外的 Guzzle 選項，例如 form_params
     * @param string $token   帶了就加上 Authorization header
     * @return array 解析後的回應
     */
    private function request($url, $method, array $options = [], $token = '')
    {
        $headers = ['Accept' => 'application/json'];

        if (filled($token)) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        $response = $this->client->request($method, $url, $options + ['headers' => $headers]);

        return (array) json_decode($response->getBody()->getContents(), true);
    }

    /**
     * 取出例外裡的回應內容
     *
     * ⚠ **每次都要先 `rewind()`**：PSR-7 的 stream 讀過之後指標會停在結尾，
     * 第二次 `getContents()` 只會拿到空字串。這支會被呼叫兩次（寫 log 一次、
     * 組錯誤訊息一次），不倒回開頭的話後面那次解析不到 `error_description`，
     * 錯誤訊息就會退成原始的例外字串 —— 也就失去「指出是哪一組憑證錯」
     * 的能力（主專案踩過，註解照搬）。
     *
     * @param \Exception $e
     * @return array
     */
    private function exceptionBody(\Exception $e)
    {
        if (!method_exists($e, 'getResponse')) {
            return [];
        }

        $response = $e->getResponse();

        if (blank($response)) {
            return [];
        }

        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return (array) json_decode($body->getContents(), true);
    }

    /**
     * @param array $steps
     * @return array|null
     */
    private function firstFailure(array $steps)
    {
        foreach ($steps as $step) {
            if (Arr::get($step, 'status') === self::STATUS_FAIL) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @param string $key
     * @param string $status
     * @param array  $detail
     * @return array{key: string, status: string, detail: array}
     */
    private function step($key, $status, array $detail)
    {
        return [
            'key'    => $key,
            'status' => $status,
            'detail' => $detail,
        ];
    }

    /**
     * 前面卡住時，後面沒跑到的步驟補成「未檢查」
     *
     * ⚠ 不補的話，回覆裡只會看到前兩步 —— 看的人會以為第三步過了。
     *
     * @param array $steps
     * @return array
     */
    private function fillUnreached(array $steps)
    {
        $done = array_column($steps, 'key');

        foreach (self::STEPS as $key) {
            if (in_array($key, $done, true)) {
                continue;
            }

            $steps[] = $this->step($key, self::STATUS_SKIP, ['reason' => 'unreached']);
        }

        return $steps;
    }

    /**
     * @param bool  $success
     * @param array $steps
     * @return array{success: bool, steps: array}
     */
    private function result($success, array $steps)
    {
        return [
            'success' => (bool) $success,
            'steps'   => $steps,
        ];
    }
}
