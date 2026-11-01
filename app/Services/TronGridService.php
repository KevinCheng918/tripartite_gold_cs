<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 向 TRON 鏈查某個錢包的 TRC20 轉帳紀錄
 *
 * ⚠ **這支只負責「拿資料」**，不決定要不要發通知、也不碰資料庫 ——
 * 那是 `WalletNoticeService` 的事。
 *
 * ⚠ **資料來源是 TronGrid 不是 tronscan.org**，理由寫在 `config/tron.php`。
 * 需求方口中的「Tronscan」是他習慣看的那個畫面，拿的是同一份鏈上資料。
 */
class TronGridService
{
    /**
     * 兩次呼叫之間的預設間隔（毫秒）
     *
     * ⚠ **存在的理由是「config 讀不到也要有保護」**：`tron.min_interval_ms`
     * 是後來才加的 key，正式環境的 config 又是快取的 —— 部署後沒跑
     * `artisan optimize` 時那個 key 會是 null，而節流就靜默失效了
     * （2026-11-01 真的發生過）。
     *
     * ⚠ 這個值要跟 `config/tron.php` 的預設一致；真正要調整請改 config
     * 或 `.env` 的 `TRON_MIN_INTERVAL_MS`，不要改這裡。
     *
     * @var int
     */
    private const DEFAULT_MIN_INTERVAL_MS = 1100;

    /**
     * 撞到 429 時，同一次查詢最多再試幾次
     *
     * ⚠ 預設值與 `config/tron.php` 的 `rate_limit_retries` 要一致，
     * 理由同 `DEFAULT_MIN_INTERVAL_MS`（config 讀不到也要有保護）。
     *
     * @var int
     */
    private const DEFAULT_RATE_LIMIT_RETRIES = 2;

    /**
     * 重試前額外多等的毫秒數（會再乘上第幾次重試，並加上抖動）
     *
     * @var int
     */
    private const DEFAULT_RATE_LIMIT_BACKOFF_MS = 700;

    /**
     * 一輪排程總共最多重試幾次
     *
     * @var int
     */
    private const DEFAULT_RATE_LIMIT_RETRY_BUDGET = 10;

    private $client;

    /**
     * 這一輪還剩幾次重試額度（null = 還沒從 config 初始化）
     *
     * ⚠ **存在的理由是「不要讓重試把一輪拖垮」**：如果速率限制是被
     * 別的東西吃掉的（同一個出口 IP 上還有其他服務），那會是**每一次**
     * 查詢都撞 —— 每個錢包都重試兩次的話，20 個錢包會從 22 秒變成
     * 兩三分鐘，排程被 `withoutOverlapping` 卡著跳過好幾分鐘，
     * 通知反而更慢。額度用完就快速失敗，讓 log 去講真話。
     *
     * ⚠ 跟 `$lastRequestAt` 一樣是 instance 狀態，所以生命週期是
     * 「一次排程」—— `WalletNoticeService` 建構子注入，一輪只有一個實例。
     *
     * @var int|null
     */
    private $rateLimitRetriesLeft = null;

    /**
     * 上一次打 TronGrid 的時刻（microtime，浮點秒）
     *
     * ⚠ 存在的理由是 **429**：沒有 API key 時 TronGrid 的限制是
     * `allowed_rps(1)`，而 `WalletNoticeService::run()` 是背對背跑每個錢包的
     * —— 清單裡**第一個錢包永遠成功、第二個永遠被擋**。
     *
     * ⚠ 節流做在這一層而不是那個迴圈裡，因為**翻頁也會連發**
     * （`max_pages` 預設 5），兩邊都要擋。
     *
     * ⚠ 這是 instance 狀態，所以同一次排程裡的所有呼叫才共用得到。
     * 唯一的呼叫端是 `WalletNoticeService`，而它只有排程在跑
     * （設定頁的「測試」只發私訊、不打 TronGrid），所以在這裡 sleep 不會
     * 卡住任何網頁請求。
     *
     * @var float|null
     */
    private $lastRequestAt = null;

    public function __construct()
    {
        $this->client = new Client(['timeout' => (int) config('tron.timeout')]);
    }

    /**
     * 這個字串是不是合法的 TRON 地址
     *
     * ⚠ 存檔當下就要擋：填錯的話 TronGrid 會每分鐘回一次錯，
     * 而且沒有人會發現 —— 通知就只是安靜地不會來。
     *
     * ⚠ pattern 放 `config/rules.php`，跟 Request 的驗證共用同一份定義。
     *
     * @param string|null $address
     * @return bool
     */
    public function isValidAddress($address)
    {
        // 短路：空字串不必進正則
        if (blank($address)) {
            return false;
        }

        return preg_match((string) config('rules.TRON_ADDRESS_PATTERN'), (string) $address) === 1;
    }

    /**
     * 某個錢包在 `$since` 之後的 TRC20 轉帳（舊到新）
     *
     * ⚠ `only_confirmed=true`：未確認的交易可能因為鏈重組被回捲，
     * 通知出去之後才消失會很難解釋。
     *
     * ⚠ `order_by=block_timestamp,asc` ＋ 以最後一筆的時間當下一輪的游標 ——
     * 用 desc 的話要自己算哪些是新的，而且分頁翻下去會跟新進來的交易打架。
     *
     * ⚠ `$since` 不加型別宣告 —— 這個專案是 PHP 7.4，`Carbon $since = null`
     * 這種隱含可空的寫法在新版 PHP 會噴 deprecated，而加 `?Carbon` 又跟
     * 這個專案多數方法不帶型別的寫法不一致。型別寫在 PHPDoc 就好。
     *
     * @param string      $address
     * @param Carbon|null $since null = 不限時間（第一次建立基準用）
     * @param int|null    $maxPages 不傳就用 config
     * @return array<int, array{tx_hash: string, from: string, to: string,
     *     amount: string, symbol: string, contract_address: string, block_at: Carbon}>|null
     *     查不到資料回 null（跟「這段期間沒有轉帳」的空陣列要分得開）
     */
    public function getTrc20Transfers($address, $since = null, $maxPages = null)
    {
        if (!$this->isValidAddress($address)) {
            Log::warning('錢包地址格式不對，略過查詢', ['address' => $address]);

            return null;
        }

        $maxPages = filled($maxPages) ? (int) $maxPages : (int) config('tron.max_pages');
        $transfers = [];
        $fingerprint = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $body = $this->request($address, $since, $fingerprint);

            // 這一頁就掛了 —— 整輪回 null，讓呼叫端知道「問不到」而不是「沒有」
            if (blank($body)) {
                return null;
            }

            foreach ((array) Arr::get($body, 'data', []) as $row) {
                $parsed = $this->parseTransfer($row);

                if (filled($parsed)) {
                    $transfers[] = $parsed;
                }
            }

            $fingerprint = Arr::get($body, 'meta.fingerprint');

            // 沒有下一頁的游標就是翻完了
            if (blank($fingerprint)) {
                return $transfers;
            }
        }

        /*
         * 翻到上限還有下一頁 —— 剩下的下一輪會接著處理（游標是鏈上時間，
         * 不會漏），但要留個紀錄，不然「通知好像慢了很久」查不出原因。
         */
        Log::warning('TRC20 轉帳翻頁達到上限，剩下的下一輪再處理', [
            'address' => $address,
            'pages'   => $maxPages,
            'count'   => count($transfers),
        ]);

        return $transfers;
    }

    /**
     * 打一次 TronGrid（撞到 429 會退避重試）
     *
     * ⚠ **只有 429 會重試，其他錯誤一律當場放棄。** 逾時重試會讓一次查詢
     * 吃掉兩倍的時間預算（`tron.timeout` 15 秒 × 2），而排程只有一分鐘；
     * 而且逾時多半不是等一下就會好的事。
     *
     * ⚠ **重試前的等待由 `rateLimitBackoff()` 一次等滿**，不是「節流 ＋ 退避」
     * 相加。迴圈下一輪雖然還會跑 `throttle()`，但那時候間隔早就夠了，
     * 它不會再等 —— 兩者是 `max()` 不是 `+`（2026-11-01 實測踩到：
     * 原本以為會相加，結果重試間隔只有 1.33 秒而不是預期的 1.8 秒以上）。
     *
     * @param string      $address
     * @param Carbon|null $since
     * @param string|null $fingerprint
     * @return array|null
     */
    private function request($address, $since = null, $fingerprint = null)
    {
        $query = [
            'only_confirmed' => 'true',
            'order_by'       => 'block_timestamp,asc',
            'limit'          => (int) config('tron.page_size'),
        ];

        if (filled($since)) {
            /*
             * ⚠ TronGrid 的 timestamp 是**毫秒**，而且 `min_timestamp` 是
             * **含等於**的 —— 直接帶最後一筆的時間會把那一筆再拿回來一次。
             * 重複本身有唯一索引擋著，加 1 毫秒只是不要白拿。
             */
            $query['min_timestamp'] = ($since->valueOf() + 1);
        }

        if (filled($fingerprint)) {
            $query['fingerprint'] = $fingerprint;
        }

        $headers = [];
        $apiKey = config('tron.api_key');

        // 沒有 key 也能跑，只是額度低 —— 不要因為沒設就不查
        if (filled($apiKey)) {
            $headers['TRON-PRO-API-KEY'] = $apiKey;
        }

        $base = rtrim((string) config('tron.api_base'), '/');
        $url = "{$base}/v1/accounts/{$address}/transactions/trc20";

        $maxAttempts = $this->configInt('tron.rate_limit_retries', self::DEFAULT_RATE_LIMIT_RETRIES) + 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // ⚠ 一定要在真的送出之前等 —— 理由見 `$lastRequestAt`
            $this->throttle();

            try {
                $response = $this->client->get($url, ['query' => $query, 'headers' => $headers]);
                $body = json_decode($response->getBody()->getContents(), true);
            } catch (\Exception $e) {
                /*
                 * 還有重試機會、而且這次真的是 429 —— 退避之後再送一次。
                 * 成功的話整件事不會留下任何 log（本來就不是異常，
                 * 是跟別人搶同一秒的額度）。
                 */
                if ($attempt < $maxAttempts && $this->canRetryRateLimit($e)) {
                    $this->rateLimitBackoff($attempt);

                    continue;
                }

                $isRateLimited = $this->isRateLimited($e);

                Log::error('TronGrid 查詢失敗', [
                    'address'  => $address,
                    'error'    => $e->getMessage(),
                    'attempts' => $attempt,
                    /*
                     * ⚠ 429 要給下一步的提示。這個錯最容易被誤判成「某個
                     * 地址有問題」—— 實際上跟地址無關，是撞到 `allowed_rps(1)`。
                     */
                    'hint'     => $isRateLimited
                        ? '撞到 TronGrid 的速率限制，重試後仍然失敗。已有節流'
                            . '（tron.min_interval_ms）＋退避重試，還是會撞的話'
                            . '代表額度被這個出口 IP 上的其他東西吃掉了，請設 TRON_API_KEY'
                        : null,
                ]);

                return null;
            }

            // success=false 時 data 會是空的，照回去會被當成「沒有轉帳」
            if (blank($body) || Arr::get($body, 'success') !== true) {
                Log::error('TronGrid 回應不正常', [
                    'address' => $address,
                    'body'    => mb_substr(json_encode($body, JSON_UNESCAPED_UNICODE), 0, 300),
                ]);

                return null;
            }

            return $body;
        }

        /*
         * 到不了這裡：最後一輪的 `$attempt < $maxAttempts` 是 false，
         * 所以一定會走上面那個 `return null`。放一個出口是因為 PHP
         * 看不出迴圈必然返回。
         */
        return null;
    }

    /**
     * 這個錯誤是不是 429，而且這一輪還有重試額度
     *
     * ⚠ 額度在這裡就扣掉（回 true 的那一刻），不要等重試真的送出去才扣 ——
     * 那會讓「判斷」與「扣款」分開，日後多一個呼叫點就會漏扣。
     *
     * @param \Exception $e
     * @return bool
     */
    private function canRetryRateLimit($e)
    {
        // 短路：不是 429 就不必動額度
        if (!$this->isRateLimited($e)) {
            return false;
        }

        if (blank($this->rateLimitRetriesLeft)) {
            $this->rateLimitRetriesLeft = $this->configInt(
                'tron.rate_limit_retry_budget',
                self::DEFAULT_RATE_LIMIT_RETRY_BUDGET
            );
        }

        if ($this->rateLimitRetriesLeft < 1) {
            return false;
        }

        $this->rateLimitRetriesLeft--;

        return true;
    }

    /**
     * 這個例外是不是 TronGrid 的速率限制（HTTP 429）
     *
     * ⚠ **看 HTTP 狀態碼，不要在訊息裡找「429」字串。** Guzzle 的錯誤訊息
     * 會把整個網址包進去，而網址裡有錢包地址 —— TRON 地址是 base58，
     * **數字 1-9 都可能出現**，所以「429」真的有機會出現在地址裡。
     * 用字串比對的話，那種地址只要逾時一次就會被貼上「撞到速率限制」的
     * 提示，把下一個看 log 的人帶往完全錯誤的方向。
     *
     * ⚠ 連線層的錯誤（逾時、DNS）沒有 response，`getResponse()` 會是 null ——
     * 那些不是 429，回 false。
     *
     * @param \Exception $e
     * @return bool
     */
    private function isRateLimited($e)
    {
        if (!$e instanceof RequestException) {
            return false;
        }

        $response = $e->getResponse();

        if (blank($response)) {
            return false;
        }

        return $response->getStatusCode() === 429;
    }

    /**
     * 重試前等一段（正常間隔 ＋ 遞增的退避 ＋ 抖動）
     *
     * ⚠ **這一支要把整個間隔等滿，所以自己也要把 `min_interval_ms` 算進去。**
     *
     * 一開始我以為「退避」會疊在 `throttle()` 的 1100ms 之上，但 `throttle()`
     * 是**從上一次送出的時刻**起算的 —— 退避睡掉的時間它照樣算進 elapsed，
     * 所以兩者是 `max()` 不是 `+`。只睡 700ms 的話，下一輪 `throttle()` 補到
     * 1100ms 就送出去了，退避等於沒做。
     * （2026-11-01 實測：間隔只有 1.33 秒，而不是預期的 1.8 秒以上。）
     *
     * 所以這裡睡的是「整個間隔」。等滿之後下一輪的 `throttle()` 看到時間
     * 已經夠，自然不會再等 —— 不必、也不應該去動 `$lastRequestAt`
     * （那個欄位的語意是「上次真的送出的時刻」，塞一個非送出時間進去，
     * 下一個讀的人會被騙）。
     *
     * ⚠ **一定要有抖動**：撞 429 代表有別人也在用這個額度。固定等一樣久的話
     * 兩邊會維持同樣的相位差 —— 每一次重試都剛好又撞在一起，重試就白做了。
     * 隨機散開才有機會錯開。
     *
     * 預設值下的實際間隔：第一次重試約 1.8～2.5 秒，第二次約 2.5～3.2 秒。
     *
     * @param int $attempt 這是第幾次重試（1 起算）
     * @return void
     */
    private function rateLimitBackoff($attempt)
    {
        $backoffMs = $this->configInt('tron.rate_limit_backoff_ms', self::DEFAULT_RATE_LIMIT_BACKOFF_MS);

        // 退避關掉時仍然要讓下一輪的 throttle 去擋，所以這裡直接走
        if ($backoffMs < 1) {
            return;
        }

        $intervalMs = $this->configInt('tron.min_interval_ms', self::DEFAULT_MIN_INTERVAL_MS);
        $waitMs = $intervalMs + ($backoffMs * $attempt) + random_int(0, $backoffMs);

        usleep($waitMs * 1000);
    }

    /**
     * 讀一個整數設定，而且**讀不到時退回程式裡的預設值**
     *
     * ⚠⚠ **不要直接用 `config($key, $default)` 代替這一支。**
     *
     * 2026-11-01 踩過整整一輪：節流上線之後 429 還是照來。原因是
     * `tron.min_interval_ms` 是**新增的 config key**，而正式環境的 config
     * 是快取的（`config:cache`）—— 部署後沒跑 `artisan optimize` 的話那個 key
     * 讀出來是 `null`，`(int) null` 是 0，保護機制就**靜默失效**了。
     *
     * ⚠ 而 `config($key, $default)` 的第二個參數**救不了這件事**：那個預設值
     * 只在「key 不存在」時生效，**key 存在但值是 null 就不會**
     * （`Arr::get()` 的語意）。第一版修法就是這樣寫錯的。
     *
     * ⚠ **明確設成 0 會被保留**（那是刻意關閉，例如已經設了 API key 想拿回
     * 速度）：`filled(0)` 在 Laravel 是 **true**，數字一律 filled。
     * 「讀不到」與「設成 0」是兩件事。
     *
     * @param string $key
     * @param int    $default
     * @return int
     */
    private function configInt($key, $default)
    {
        $configured = config($key);

        return filled($configured) ? (int) $configured : (int) $default;
    }

    /**
     * 距離上一次呼叫不夠久就等一下
     *
     * ⚠ **第一次呼叫不會等**：`$lastRequestAt` 是 null 就直接走。
     * 所以單一錢包的情況完全沒有額外延遲，只有連發才會被拉開。
     *
     * ⚠ 用 `microtime(true)` 而不是 `time()`：要擋的是「同一秒內的第二次」，
     * 秒級精度根本看不到那個間隔。
     *
     * @return void
     */
    private function throttle()
    {
        /*
         * ⚠ 走 `configInt()` 而不是直接 `config()` —— 那一支的 docblock 寫著
         * 2026-11-01 的事故：這個 key 在正式環境讀出 null，節流靜默失效。
         */
        $intervalMs = $this->configInt('tron.min_interval_ms', self::DEFAULT_MIN_INTERVAL_MS);

        if ($intervalMs < 1) {
            return;
        }

        if (filled($this->lastRequestAt)) {
            $elapsedMs = (microtime(true) - $this->lastRequestAt) * 1000;
            $waitMs = $intervalMs - $elapsedMs;

            if ($waitMs > 0) {
                usleep((int) round($waitMs * 1000));
            }
        }

        /*
         * ⚠ **在這裡就記時間，不要等請求回來才記。** 記在回來之後的話，
         * 間隔會變成「上一次結束到這一次開始」—— 而 TronGrid 算的是
         * 「送達的時間點」，一次查詢本身花掉的那一秒也要算進去，
         * 不然慢的那次回來後又立刻連發。
         */
        $this->lastRequestAt = microtime(true);
    }

    /**
     * 把一列原始資料整理成我們要的樣子
     *
     * @param array $row
     * @return array|null 缺關鍵欄位時回 null（不要讓半筆資料進資料庫）
     */
    private function parseTransfer($row)
    {
        $txHash = Arr::get($row, 'transaction_id');
        $from = Arr::get($row, 'from');
        $to = Arr::get($row, 'to');
        $timestamp = Arr::get($row, 'block_timestamp');

        if (blank($txHash) || blank($from) || blank($to) || blank($timestamp)) {
            Log::warning('TRC20 轉帳缺欄位，略過這一筆', [
                'row' => mb_substr(json_encode($row, JSON_UNESCAPED_UNICODE), 0, 200),
            ]);

            return null;
        }

        return [
            'tx_hash'          => (string) $txHash,
            'from'             => (string) $from,
            'to'               => (string) $to,
            'amount'           => $this->toDecimal(
                Arr::get($row, 'value'),
                (int) Arr::get($row, 'token_info.decimals', 0)
            ),
            'symbol'           => (string) Arr::get($row, 'token_info.symbol', '?'),
            'contract_address' => (string) Arr::get($row, 'token_info.address', ''),
            // TronGrid 給的是毫秒
            'block_at'         => Carbon::createFromTimestampMs((int) $timestamp),
        ];
    }

    /**
     * 把「未除以小數位的整數字串」換算成金額
     *
     * ⚠ **不能用 float 算**。TronGrid 回的 `value` 是 `"1250000000"` 這種字串，
     * USDT 是 6 位小數、有些 TRC20 代幣是 18 位 ——
     * `(float) $value / pow(10, 18)` 在大數字上會丟精度，而這是錢。
     *
     * 這裡用字串插小數點，完全不碰浮點數。
     *
     * @param string|null $raw
     * @param int         $decimals
     * @return string 例如 "1250.000000"
     */
    private function toDecimal($raw, $decimals)
    {
        $digits = preg_replace('/\D/', '', (string) $raw);

        if (blank($digits)) {
            return '0';
        }

        if ($decimals < 1) {
            return ltrim($digits, '0') ?: '0';
        }

        // 位數不足就左補 0：120 搭 6 位小數要變成 0.000120
        $digits = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);

        $integer = substr($digits, 0, -$decimals);
        $fraction = substr($digits, -$decimals);

        return ltrim($integer, '0') === '' ? "0.{$fraction}" : ltrim($integer, '0') . ".{$fraction}";
    }
}
