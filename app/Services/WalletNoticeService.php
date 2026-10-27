<?php

namespace App\Services;

use App\Models\WalletTransfer;
use App\Repositories\WalletTransferRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 錢包有轉入或轉出就私訊通知
 *
 * 需求方 2026-10-27：「當我的錢包有轉帳進來或轉出就要通知，**只有通知就好，
 * 不用做任何動作**，通知要包含時間、交易 hash、From、to、金額、幣別。」
 * 後續補充：通知要附一張轉帳紀錄頁的截圖。
 *
 * ⚠ **純通知**：不自動對帳、不碰補點審核、不動任何點數。
 *
 * ⚠ **這支決定「要不要發、發什麼」**，拿資料走 `TronGridService`、
 * 送出走 `StaffDmService`、截圖走 `ScreenshotService` ——
 * 跟 `FinanceNoticeService` 同一個分工。
 */
class WalletNoticeService
{
    /** @var string 這個錢包沒開或設定不全，整個跳過 */
    const SKIP_DISABLED = 'disabled';

    /** @var string 地址格式不對 */
    const SKIP_BAD_ADDRESS = 'bad_address';

    /** @var string 問不到鏈上資料（TronGrid 掛了或額度用完） */
    const SKIP_QUERY_FAILED = 'query_failed';

    /** @var string 首次加入，這一輪只建立基準 */
    const SKIP_BASELINE = 'baseline';

    /** @var string 這一輪沒有新的轉帳 */
    const SKIP_NO_NEW = 'no_new';

    /**
     * 補發只追多久以前的
     *
     * ⚠ 一定要有上限：服務中斷一週後恢復，不該一次倒幾百則出來。
     * 太舊的漏掉就算了，鏈上紀錄本來就查得到。
     *
     * @var int
     */
    const RETRY_HOURS = 24;

    private $appSettingService;
    private $tronGrid;
    private $transferRepository;
    private $staffDm;
    private $screenshotService;

    public function __construct(
        AppSettingService $appSettingService,
        TronGridService $tronGrid,
        WalletTransferRepository $transferRepository,
        StaffDmService $staffDm,
        ScreenshotService $screenshotService
    ) {
        // 錢包清單、收件人、金額門檻都在通知設定頁維護
        $this->appSettingService = $appSettingService;
        $this->tronGrid = $tronGrid;
        $this->transferRepository = $transferRepository;
        $this->staffDm = $staffDm;
        // 截不到圖就只發文字，理由見 captureTransfersPage()
        $this->screenshotService = $screenshotService;
    }

    /**
     * 跑一輪
     *
     * @param array $params dry_run（只印不發）／address（只跑一個錢包）
     * @return array<int, array{address: string, label: string, sent: int,
     *     skipped: int, reason: string|null, previews: array}>
     */
    public function run(array $params = [])
    {
        /*
         * ⚠ **短路擺最前面**：沒開、沒設錢包、沒設收件人就直接結束，
         * 連 TronGrid 都不打。這支每分鐘跑一次，而絕大多數時候是沒事做的。
         */
        if (!$this->appSettingService->getBool(AppSettingService::KEY_WALLET_NOTICE_ENABLED)) {
            return [];
        }

        $wallets = $this->wallets(Arr::get($params, 'address'));

        if (blank($wallets)) {
            return [];
        }

        $results = [];

        foreach ($wallets as $wallet) {
            $results[] = $this->handleWallet($wallet, $params);
        }

        return $results;
    }

    /**
     * 處理一個錢包
     *
     * ⚠ **整段 try/catch**：一個錢包出事不能讓其他錢包跟著停 ——
     * 這支是排程跑的，掛掉就是整輪都沒通知。
     *
     * @param array $wallet address／label
     * @param array $params
     * @return array
     */
    private function handleWallet(array $wallet, array $params)
    {
        $address = (string) Arr::get($wallet, 'address');
        $label = (string) Arr::get($wallet, 'label');

        try {
            return $this->process($wallet, $params);
        } catch (\Exception $e) {
            Log::error('錢包轉帳通知處理失敗', [
                'address' => $address,
                'error'   => $e->getMessage(),
            ]);

            return $this->result($address, $label, 0, 0, self::SKIP_QUERY_FAILED);
        }
    }

    /**
     * 一個錢包的完整流程
     *
     * @param array $wallet
     * @param array $params
     * @return array
     */
    private function process(array $wallet, array $params)
    {
        $address = (string) Arr::get($wallet, 'address');
        $label = (string) Arr::get($wallet, 'label');

        if (!$this->tronGrid->isValidAddress($address)) {
            return $this->result($address, $label, 0, 0, self::SKIP_BAD_ADDRESS);
        }

        $cursor = $this->transferRepository->latestBlockAt($address);
        $isFirstRun = blank($cursor);

        $transfers = $this->tronGrid->getTrc20Transfers($address, $cursor);

        // null ＝ 問不到（跟「沒有新轉帳」的空陣列不一樣，不能當成正常）
        if (!is_array($transfers)) {
            return $this->result($address, $label, 0, 0, self::SKIP_QUERY_FAILED);
        }

        $stored = $this->store($address, $transfers, $isFirstRun);

        /*
         * ⚠ **第一輪只建立基準，一則都不發**（需求方決定不加欄位、不另外設起點）。
         *
         * 新增一個錢包時鏈上可能有好幾千筆歷史轉帳，照發會在存檔那一刻
         * 把收件人炸掉。第二輪起才開始通知。
         */
        if ($isFirstRun) {
            return $this->result($address, $label, 0, count($stored), self::SKIP_BASELINE);
        }

        // 這一輪新進來的，加上先前送失敗、還值得補發的
        $pending = $this->transferRepository->getPending(
            $address,
            now()->subHours(self::RETRY_HOURS)->format('Y-m-d H:i:s')
        );

        if (blank($pending)) {
            return $this->result($address, $label, 0, 0, self::SKIP_NO_NEW);
        }

        return $this->notify($wallet, $pending, $params);
    }

    /**
     * 把這一批寫進資料表
     *
     * ⚠ **先寫資料庫再發送**，不是先發再寫。先發後寫的話，
     * 「發送成功但寫入失敗」會讓下一輪重發同一筆；先寫（唯一索引擋重複）
     * 再發，最壞情況是漏一則 —— 比重複洗版好。
     *
     * @param string $address
     * @param array  $transfers
     * @param bool   $isFirstRun 第一輪的全部標成 baseline，不發通知
     * @return array<int, WalletTransfer> 這次才建的那些
     */
    private function store($address, array $transfers, $isFirstRun)
    {
        $minAmount = $this->minAmount();
        $created = [];

        foreach ($transfers as $transfer) {
            $direction = $this->direction($address, $transfer);

            /*
             * 自己轉給自己（from 與 to 都是這個錢包）時 `direction()` 回 null。
             * 不是錯誤，但也沒有「進來還是出去」可言，記下來就好、不通知。
             */
            $skipReason = $this->skipReasonFor($isFirstRun, $direction, Arr::get($transfer, 'amount'), $minAmount);

            [$record, $isNew] = $this->transferRepository->firstOrCreate([
                'address'          => $address,
                'tx_hash'          => Arr::get($transfer, 'tx_hash'),
                'direction'        => filled($direction) ? $direction : WalletTransfer::DIRECTION_IN,
                'from_address'     => Arr::get($transfer, 'from'),
                'to_address'       => Arr::get($transfer, 'to'),
                'amount'           => Arr::get($transfer, 'amount'),
                'symbol'           => Arr::get($transfer, 'symbol'),
                'contract_address' => Arr::get($transfer, 'contract_address'),
                'block_at'         => Arr::get($transfer, 'block_at'),
                'skip_reason'      => $skipReason,
            ]);

            if ($isNew) {
                $created[] = $record;
            }
        }

        return $created;
    }

    /**
     * 這一筆該不該跳過，跳過的理由是什麼
     *
     * @param bool        $isFirstRun
     * @param string|null $direction
     * @param string|null $amount
     * @param float       $minAmount
     * @return string|null null ＝ 該發
     */
    private function skipReasonFor($isFirstRun, $direction, $amount, $minAmount)
    {
        if ($isFirstRun) {
            return WalletTransfer::SKIP_BASELINE;
        }

        // 自己轉自己：記著就好，沒有方向可言
        if (blank($direction)) {
            return WalletTransfer::SKIP_SELF;
        }

        /*
         * ⚠ TRON 有「地址投毒」攻擊：從長得很像的地址丟 0.000001 USDT 進來，
         * 讓人下次複製到錯的地址。不擋的話通知會被洗版。
         *
         * 用 `(float)` 比大小是安全的 —— 這裡只是跟門檻比，不是拿去做帳。
         * 真正要保精度的是存進資料庫的那個字串。
         */
        if ($minAmount > 0 && (float) $amount < $minAmount) {
            return WalletTransfer::SKIP_BELOW_MIN;
        }

        return null;
    }

    /**
     * 這一筆對這個錢包是轉入還是轉出
     *
     * @param string $address
     * @param array  $transfer
     * @return string|null 自己轉自己時回 null
     */
    private function direction($address, array $transfer)
    {
        $isIn = Arr::get($transfer, 'to') === $address;
        $isOut = Arr::get($transfer, 'from') === $address;

        if ($isIn && !$isOut) {
            return WalletTransfer::DIRECTION_IN;
        }

        if ($isOut && !$isIn) {
            return WalletTransfer::DIRECTION_OUT;
        }

        return null;
    }

    /**
     * 把這幾筆通知出去
     *
     * @param array                                   $wallet
     * @param \Illuminate\Database\Eloquent\Collection $pending
     * @param array                                   $params
     * @return array
     */
    private function notify(array $wallet, $pending, array $params)
    {
        $address = (string) Arr::get($wallet, 'address');
        $label = (string) Arr::get($wallet, 'label');
        $userIds = $this->recipients();

        if (blank($userIds)) {
            return $this->result($address, $label, 0, count($pending), self::SKIP_DISABLED);
        }

        $previews = [];

        foreach ($pending as $transfer) {
            $previews[] = $this->buildText($transfer, $label);
        }

        // 空跑：只把會發什麼印出來，不截圖也不送出
        if (Arr::get($params, 'dry_run')) {
            return $this->result($address, $label, 0, 0, null, $previews);
        }

        /*
         * ⚠ **確定有東西要發才截圖**。截一張要跑一次 headless Chrome（約 10 秒），
         * 而這支每分鐘跑一次、絕大多數輪是沒事做的 —— 不先判斷的話
         * 一天會白跑 1400 次 Chrome。
         */
        $shot = $this->captureTransfersPage($address);
        $sent = 0;

        foreach ($pending as $index => $transfer) {
            /*
             * ⚠ 圖只附在**第一則**。截的是「這個錢包的轉帳紀錄頁」，
             * 是整輪共用的脈絡；每一則都附同一張只是洗版。
             */
            $image = ($index === 0) ? $shot : null;
            $result = $this->staffDm->sendToUserIds($userIds, $this->buildText($transfer, $label), $image);

            if ((int) Arr::get($result, 'sent') < 1) {
                // 沒送出去就不標記 —— 下一輪會從 getPending() 再撈到它
                continue;
            }

            $this->transferRepository->markNotified($transfer);
            $sent++;
        }

        /*
         * 截圖只是為了送出去，送完就沒用了 —— 不刪的話 public 會一直長大。
         * 放在迴圈外：裡面刪掉的話第二則之後就找不到檔案了（雖然用不到，
         * 但 sendPhoto 失敗重試時會）。
         */
        $this->screenshotService->forget($shot);

        Log::info('錢包轉帳通知已送出', [
            'address' => $address,
            'sent'    => $sent,
            'pending' => count($pending),
            'shot'    => filled($shot),
        ]);

        return $this->result($address, $label, $sent, count($pending) - $sent, null, $previews);
    }

    /**
     * 截一張這個錢包的轉帳紀錄頁
     *
     * ⚠ **截不到就回 null，照樣發純文字**（需求方 2026-10-27 選的）。
     * 開發機（arm64 的 Docker）沒有官方 Chrome，本來就截不了；
     * 而「錢動了」這件事比有沒有截圖重要得多。
     *
     * @param string $address
     * @return string|null public disk 的相對路徑
     */
    private function captureTransfersPage($address)
    {
        // 短路：這台機器沒有 Chrome 就不必走進去
        if (!$this->screenshotService->isAvailable()) {
            return null;
        }

        $url = str_replace('{address}', $address, (string) config('tron.transfers_url'));

        return $this->screenshotService->capture($url, [
            'prefix' => 'wallet',
            /*
             * tronscan 是 SPA，表格是後來才用 XHR 撈回來的 ——
             * 不等的話會截到一片空白的骨架。
             */
            'wait_selector' => 'table',
            'settle_ms'     => 2000,
            'width'         => 1440,
            'height'        => 900,
        ]);
    }

    /**
     * 組一則通知
     *
     * @param WalletTransfer $transfer
     * @param string         $label
     * @return string
     */
    private function buildText(WalletTransfer $transfer, $label)
    {
        $key = $transfer->isIncoming() ? 'TRANSFER_IN' : 'TRANSFER_OUT';
        $mark = (string) config('constants.WALLET_NOTICE.OWN_MARK');

        return strtr((string) config("constants.WALLET_NOTICE.{$key}"), [
            '{label}'  => $this->walletLine($label, $transfer->address),
            // 鏈上時間存的是 UTC 轉過來的 Carbon，顯示要用本地時區
            '{time}'   => $transfer->block_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            '{amount}' => $this->amount($transfer->amount),
            '{symbol}' => (string) $transfer->symbol,
            '{from}'   => (string) $transfer->from_address,
            '{to}'     => (string) $transfer->to_address,
            // 轉入時 To 是我們、轉出時 From 是我們 —— 標出來就不必逐字比對
            '{from_mark}' => $transfer->isIncoming() ? '' : $mark,
            '{to_mark}'   => $transfer->isIncoming() ? $mark : '',
            '{hash}'   => (string) $transfer->tx_hash,
            '{url}'    => (string) config('tron.tx_url') . $transfer->tx_hash,
        ]);
    }

    /**
     * 「這是哪一個錢包」那一行
     *
     * ⚠ **一定要印，不能因為沒填備註就整行不見**（需求方 2026-10-27：
     * 「我有多個錢包…通知訊息內容要註明是哪一個錢包」）。
     *
     * 一個人盯好幾個錢包時，光看 From／To 分不出「這筆是我哪一個錢包」——
     * 兩個地址裡有一個是我的，但是哪一個要自己比對 34 碼。
     *
     * 沒填備註就退而印地址：醜一點，但至少答得出問題。
     *
     * @param string|null $label
     * @param string      $address
     * @return string
     */
    private function walletLine($label, $address)
    {
        return strtr((string) config('constants.WALLET_NOTICE.LABEL_LINE'), [
            '{label}' => filled($label) ? $label : $address,
        ]);
    }

    /**
     * 帶千分位、而且把尾巴的 0 砍掉的金額
     *
     * ⚠ 千分位的理由同 `FinanceNoticeService::amount()`：看的人要分得出
     * 1,000 跟 10,000。
     *
     * ⚠ 但這裡**不能先轉 float 再格式化** —— 18 位小數的代幣會爆掉。
     * 先用字串砍掉尾零，只有整數那一段才交給 `number_format`。
     *
     * @param string|null $amount
     * @return string
     */
    private function amount($amount)
    {
        $value = (string) $amount;
        $parts = explode('.', $value);
        $integer = Arr::get($parts, 0, '0');
        $fraction = rtrim((string) Arr::get($parts, 1, ''), '0');

        $grouped = number_format((float) $integer);

        return filled($fraction) ? "{$grouped}.{$fraction}" : $grouped;
    }

    /**
     * 要監聽的錢包清單
     *
     * @param string|null $only 只要這一個（指令的 --address）
     * @return array<int, array{address: string, label: string}>
     */
    private function wallets($only = null)
    {
        $wallets = $this->appSettingService->getJson(AppSettingService::KEY_WALLET_NOTICE_ADDRESSES);
        $clean = [];

        foreach ((array) $wallets as $wallet) {
            $address = (string) Arr::get($wallet, 'address');

            if (blank($address)) {
                continue;
            }

            if (filled($only) && $address !== $only) {
                continue;
            }

            $clean[] = [
                'address' => $address,
                'label'   => (string) Arr::get($wallet, 'label', ''),
            ];
        }

        return $clean;
    }

    /**
     * 收件人（通知設定頁維護的那份名單）
     *
     * @return array<int>
     */
    private function recipients()
    {
        return $this->appSettingService->getIntList(AppSettingService::KEY_WALLET_NOTICE_MANAGER);
    }

    /**
     * 低於這個金額就不通知
     *
     * @return float
     */
    private function minAmount()
    {
        return $this->appSettingService->getFloat(
            AppSettingService::KEY_WALLET_NOTICE_MIN_AMOUNT,
            (float) config('constants.WALLET_NOTICE.DEFAULT_MIN_AMOUNT')
        );
    }

    /**
     * 統一的回傳格式（指令要照它印表）
     *
     * @param string      $address
     * @param string      $label
     * @param int         $sent
     * @param int         $skipped
     * @param string|null $reason
     * @param array       $previews
     * @return array
     */
    private function result($address, $label, $sent, $skipped, $reason, array $previews = [])
    {
        return [
            'address'  => $address,
            'label'    => $label,
            'sent'     => $sent,
            'skipped'  => $skipped,
            'reason'   => $reason,
            'previews' => $previews,
        ];
    }

    /**
     * 測試發送（通知設定頁那顆按鈕）
     *
     * ⚠ 這種通知是**鏈上事件觸發**的，沒辦法「補發昨天那一筆」——
     * 測試發的是一則說明用的訊息，確認的是「這份名單收不收得到」。
     *
     * @return array sent／reason／failed／names
     */
    public function test()
    {
        $userIds = $this->recipients();

        if (blank($userIds)) {
            return ['sent' => 0, 'reason' => 'no_manager', 'failed' => [], 'names' => []];
        }

        return $this->staffDm->sendToUserIds(
            $userIds,
            (string) config('constants.WALLET_NOTICE.TEST_TEXT')
        );
    }
}
