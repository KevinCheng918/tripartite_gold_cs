<?php

namespace App\Services;

use GuzzleHttp\Client;
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
    private $client;

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
     * 打一次 TronGrid
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

        // ⚠ 一定要在真的送出之前等 —— 理由見 `$lastRequestAt`
        $this->throttle();

        try {
            $response = $this->client->get($url, ['query' => $query, 'headers' => $headers]);
            $body = json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('TronGrid 查詢失敗', [
                'address' => $address,
                'error'   => $e->getMessage(),
                /*
                 * ⚠ 429 要給下一步的提示。這個錯最容易被誤判成「某個地址有
                 * 問題」—— 實際上跟地址無關，是連發撞到 `allowed_rps(1)`。
                 */
                'hint'    => strpos($e->getMessage(), '429') === false
                    ? null
                    : '撞到 TronGrid 的速率限制。已有節流（tron.min_interval_ms），'
                        . '還是會撞的話請設 TRON_API_KEY（免費額度低）',
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
        $intervalMs = (int) config('tron.min_interval_ms');

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
