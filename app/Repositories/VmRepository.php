<?php

namespace App\Repositories;

use App\Models\VmBilling;
use App\Models\VmServer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * 虛擬機 Repository
 *
 * 負責 vm_server 與 vm_billing 表的所有 DB 操作。
 */
class VmRepository
{
    /** @var array VM 列表欄位 */
    private const SERVER_COLUMNS = [
        'id', 'station_id', 'hostname', 'internal_ip', 'external_ip',
        'model_type', 'spec', 'monthly_fee', 'vpn_fee', 'vpn_renew_date', 'google_fee', 'billing_day',
        // `status`（啟用／停用）2026-10-13 移除：收不收錢只看 power_status
        'power_status', 'powered_off_at', 'note', 'created_at',
    ];

    /** @var array 帳單列表欄位 */
    private const BILLING_COLUMNS = [
        'id', 'vm_server_id', 'billing_month', 'amount',
        'paid', 'paid_at', 'proof_image', 'due_date', 'note', 'created_at',
    ];

    // ---------------------------------------------------------------
    //  VM Server
    // ---------------------------------------------------------------

    /**
     * 分頁查詢 VM 列表
     *
     * @param array $criteria 篩選條件（keyword, station_id, status, power_status）
     * @param int   $perPage
     * @return LengthAwarePaginator
     */
    public function paginateServers($criteria = [], $perPage = 20)
    {
        $query = VmServer::query()
            ->select(self::SERVER_COLUMNS)
            ->with(['station.system'])
            ->orderByDesc('id');

        if (filled($criteria['keyword'] ?? null)) {
            $kw = "%{$criteria['keyword']}%";
            $query->where(function ($q) use ($kw) {
                $q->where('hostname', 'like', $kw)
                  ->orWhere('spec', 'like', $kw)
                  ->orWhere('internal_ip', 'like', $kw)
                  ->orWhere('external_ip', 'like', $kw)
                  ->orWhere('model_type', 'like', $kw);
            });
        }

        if (filled($criteria['station_id'] ?? null)) {
            $query->where('station_id', $criteria['station_id']);
        }

        if (filled($criteria['system_id'] ?? null)) {
            $query->whereHas('station', function ($q) use ($criteria) {
                $q->where('system_id', $criteria['system_id']);
            });
        }

        if (filled($criteria['hostname'] ?? null)) {
            $query->where('hostname', 'like', "%{$criteria['hostname']}%");
        }

        if (filled($criteria['internal_ip'] ?? null)) {
            $query->where('internal_ip', 'like', "%{$criteria['internal_ip']}%");
        }

        if (filled($criteria['external_ip'] ?? null)) {
            $query->where('external_ip', 'like', "%{$criteria['external_ip']}%");
        }

        if (isset($criteria['power_status']) && $criteria['power_status'] !== '' && $criteria['power_status'] !== null) {
            $query->where('power_status', (int) $criteria['power_status']);
        }

        return $query->paginate($perPage);
    }

    /**
     * 依 ID 查詢 VM
     *
     * @param int $id
     * @return VmServer|null
     */
    public function findServer($id)
    {
        return VmServer::query()
            ->select(self::SERVER_COLUMNS)
            ->with(['station'])
            ->find($id);
    }

    /**
     * 新增 VM
     *
     * @param array $attributes
     * @return VmServer
     */
    public function createServer($attributes)
    {
        return VmServer::query()->create($attributes);
    }

    /**
     * 更新 VM
     *
     * @param VmServer $server
     * @param array    $attributes
     * @return VmServer
     */
    public function updateServer(VmServer $server, $attributes)
    {
        $server->update($attributes);

        return $server->refresh();
    }

    /**
     * 某個站台的虛擬機（Telegram 指令查詢用）
     *
     * ⚠ 一併 eager load **指定月份**的帳單，不要在迴圈裡一台一台撈 ——
     * 一個站台可能有好幾台機器，那就是 N+1。
     *
     * ⚠ 不篩電源：關機的機器照樣要回報（使用者問的就是「開機還是關機」）。
     *
     * @param int    $stationId
     * @param string $billingMonth YYYY-MM
     * @return Collection
     */
    public function getServersByStation($stationId, $billingMonth)
    {
        return VmServer::query()
            ->select(self::SERVER_COLUMNS)
            ->with([
                'billings' => function ($query) use ($billingMonth) {
                    $query->select(self::BILLING_COLUMNS)
                        ->where('billing_month', $billingMonth);
                },
            ])
            ->where('station_id', (int) $stationId)
            ->orderBy('id')
            ->get();
    }

    /**
     * 指定月份還沒收到錢的帳單（跨站台，Telegram 指令「誰還沒繳錢」用）
     *
     * ⚠ 「還沒收到」**包含待審核**（`paid = 2`，客戶已上傳證明等我們審）——
     * 錢確實還沒入帳。但呼叫端必須把兩者分開顯示：講成同一種會去催錯人，
     * 待審核那筆卡在我們自己這邊。
     *
     * ⚠ **未收的只列開機中的**（需求方 2026-10-13）。關機不收錢，
     * `getBillingsForNotice()` 本來就不會去催關機機器的款 —— 清單列出來
     * 卻沒人會催，只是讓真正要處理的那幾筆被淹掉。
     *
     * ⚠ **但待審核的不分電源。** 那是客戶已經付錢也上傳證明、等我們審的款：
     * 機器後來關機不代表我們可以不審。濾掉的話，那筆錢會從清單上消失、
     * 永遠沒人處理 —— 客戶付了卻什麼都沒拿到。
     * （`getPendingBillingsForReminder()` 也是刻意不篩電源，同一個理由。）
     *
     * ⚠ 依 `due_date` 排序：逾期最久的最該先處理。
     *
     * @param string $billingMonth YYYY-MM
     * @return Collection
     */
    public function getUnpaidBillingsByMonth($billingMonth)
    {
        $unpaid = config('constants.VM.BILLING.UNPAID');
        $pending = config('constants.VM.BILLING.PENDING');
        $powerOn = config('constants.VM.POWER.ON');

        return VmBilling::query()
            ->select(self::BILLING_COLUMNS)
            ->with(['vmServer.station'])
            ->where('billing_month', $billingMonth)
            ->where(function ($query) use ($unpaid, $pending, $powerOn) {
                // 未收：關機的不列
                $query->where(function ($one) use ($unpaid, $powerOn) {
                    $one->where('paid', $unpaid)
                        ->whereHas('vmServer', function ($server) use ($powerOn) {
                            $server->where('power_status', $powerOn);
                        });
                })
                // 待審核：不論電源都要列
                ->orWhere('paid', $pending);
            })
            ->orderBy('due_date')
            ->get();
    }

    /**
     * 全部虛擬機的電源狀況（跨站台，Telegram 指令「誰是開機還是關機」用）
     *
     * ⚠ 開機的排前面，再依站台 —— 關機的通常是已經不用了的，
     * 要先看到的是正在跑（正在收錢）的那些。
     *
     * @return Collection
     */
    public function getAllServersWithStation()
    {
        return VmServer::query()
            ->select(self::SERVER_COLUMNS)
            ->with(['station'])
            ->orderByDesc('power_status')
            ->orderBy('station_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * 取得所有**開機中**的 VM（產帳單用）
     *
     * ⚠ 2026-10-13 之前這支叫 `getActiveServers()`，條件是 `status = 1`
     * （啟用），開機與否由呼叫端在迴圈裡再判一次。需求方確認
     * **「開機才收錢，不開機就不用收」** —— 「啟用」那個概念整個移除，
     * 收不收錢只看電源。
     *
     * 條件寫在查詢裡而不是呼叫端的迴圈：撈回來再跳過等於把關機的機器
     * 全部載進記憶體，而且「誰該收錢」就有兩個地方在決定。
     *
     * @return Collection
     */
    public function getPoweredOnServers()
    {
        return VmServer::query()
            ->select(self::SERVER_COLUMNS)
            ->with(['station.system'])
            ->where('power_status', config('constants.VM.POWER.ON'))
            ->get();
    }

    /**
     * 該提醒續約 VPN 的機器
     *
     * ⚠ **沒有下界** —— 逾期的會一直撈出來，比照繳款通知
     * （`getBillingsDueForNotice()`）。VPN 斷掉的後果比群組多幾則訊息嚴重，
     * 所以「到期了還沒人處理」要一直講到有人把日期往後改。
     *
     * ⚠ 條件是 `vpn_fee > 0`：有買 VPN 才需要續約。
     * 不另外開一個「啟用 VPN」的開關 —— 理由見 `VmServer::hasVpn()`。
     *
     * ⚠ **關機就不提醒**（需求方 2026-10-13）。跟帳單同一條線：
     * 關機的機器不收錢，也不必為它續約 —— 要用的時候再開機、再續。
     * 提醒一台沒人在用的機器，只會讓真正要處理的那幾台被淹掉。
     *
     * @param int $daysAhead 到期前幾天開始提醒
     * @return Collection
     */
    public function getVpnRenewalsDue($daysAhead)
    {
        return VmServer::query()
            ->select(self::SERVER_COLUMNS)
            ->with(['station.system'])
            ->where('power_status', config('constants.VM.POWER.ON'))
            ->where('vpn_fee', '>', 0)
            ->whereNotNull('vpn_renew_date')
            ->whereDate('vpn_renew_date', '<=', now()->addDays((int) $daysAhead)->toDateString())
            ->orderBy('vpn_renew_date')
            ->get();
    }

    // ---------------------------------------------------------------
    //  VM Billing
    // ---------------------------------------------------------------

    /**
     * 分頁查詢帳單
     *
     * @param array $criteria 篩選條件（billing_month, paid, overdue）
     * @param int   $perPage
     * @return LengthAwarePaginator
     */
    public function paginateBillings($criteria = [], $perPage = 20)
    {
        $query = VmBilling::query()
            ->select(self::BILLING_COLUMNS)
            ->with(['vmServer.station.system'])
            ->orderBy('due_date')
            ->orderBy('id');

        if (filled($criteria['billing_month'] ?? null)) {
            $query->where('billing_month', $criteria['billing_month']);
        }

        if (isset($criteria['paid']) && $criteria['paid'] !== '' && $criteria['paid'] !== null) {
            $query->where('paid', (int) $criteria['paid']);
        }

        // 待審核篩選
        if (filled($criteria['pending'] ?? null) && $criteria['pending'] === 'true') {
            $query->where('paid', 2);
        }

        // 逾期篩選：未收款且超過 due_date + 3 天
        if (filled($criteria['overdue'] ?? null) && $criteria['overdue'] === 'true') {
            $query->where('paid', 0)
                  ->whereDate('due_date', '<', now()->subDays(3)->toDateString());
        }

        // 關機篩選：VM power_status = 0
        if (filled($criteria['shutdown'] ?? null) && $criteria['shutdown'] === 'true') {
            $query->whereHas('vmServer', function ($q) {
                $q->where('power_status', config('constants.VM.POWER.OFF'));
            });
        }

        if (filled($criteria['vm_server_id'] ?? null)) {
            $query->where('vm_server_id', $criteria['vm_server_id']);
        }

        if (filled($criteria['system_id'] ?? null)) {
            $query->whereHas('vmServer.station', function ($q) use ($criteria) {
                $q->where('system_id', $criteria['system_id']);
            });
        }

        // 站台篩選。條件下在 vm_server 而非 station，少一層關聯查詢
        if (filled($criteria['station_id'] ?? null)) {
            $query->whereHas('vmServer', function ($q) use ($criteria) {
                $q->where('station_id', $criteria['station_id']);
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * 新增帳單
     *
     * @param array $attributes
     * @return VmBilling
     */
    public function createBilling($attributes)
    {
        return VmBilling::query()->create($attributes);
    }

    /**
     * 依 ID 查詢帳單
     *
     * @param int $id
     * @return VmBilling|null
     */
    public function findBilling($id)
    {
        return VmBilling::query()
            ->select(self::BILLING_COLUMNS)
            ->with(['vmServer.station'])
            ->find($id);
    }

    /**
     * 每天自動發繳款通知要撈的帳單（未收的）
     *
     * 條件：
     *   1. `paid = UNPAID` —— 已收的不發；待審核的走
     *      `getPendingBillingsForReminder()`，那是催自己人審核
     *   2. **而且沒有上傳過繳款證明**（見下方 ⚠）
     *   3. `due_date <= 今天 + $daysAhead` —— 應收日前幾天開始發。
     *      **沒有下界**，所以逾期的會一直發到客戶繳費為止
     *   4. 主機開機中 —— 關機的不發
     *   5. 主機啟用中 —— 停用通常代表這台已經不服務了，還去收錢會出事
     *
     * ⚠ **「有沒有上傳證明」也要看，不能只信 `paid`**（需求方 2026-10-19 回報）。
     *
     * 照理說上傳證明一定會把 `paid` 寫成 `PENDING`（`VmService::uploadProof()`
     * 是唯一的入口），兩個訊號不會分家。但這條路是**跟客戶催錢** ——
     * 萬一哪天多一條改 `paid` 的程式、或有人直接改資料庫，客戶就會被催
     * 一筆他已經付過、而且證明還躺在我們後台的款。
     *
     * 分家時的正確去向是**催我們自己去審核**，不是催客戶，
     * 所以 `getPendingBillingsForReminder()` 會把那種撿回去。
     *
     * @param int $daysAhead 提前幾天開始發
     * @return Collection
     */
    public function getBillingsForNotice($daysAhead)
    {
        return VmBilling::query()
            ->select(self::BILLING_COLUMNS)
            ->with(['vmServer.station'])
            ->where('paid', config('constants.VM.BILLING.UNPAID'))
            // 已經有證明就不是「沒付錢」，該去審核而不是催客戶
            ->where(function ($query) {
                $query->whereNull('proof_image')->orWhere('proof_image', '');
            })
            ->whereDate('due_date', '<=', now()->addDays((int) $daysAhead)->toDateString())
            // 關機就不收錢，所以也不催款（2026-10-13 起只看電源，不再看「啟用」）
            ->whereHas('vmServer', function ($query) {
                $query->where('power_status', config('constants.VM.POWER.ON'));
            })
            ->orderBy('due_date')
            ->get();
    }

    /**
     * 待審核的帳單（催我方審核用）
     *
     * ⚠ **沒有應收日／電源／啟用的條件。** 客戶已經付款並上傳證明了，
     * 證明擺著沒人審核本身就是問題 —— 跟應收日還剩幾天、主機是不是關機
     * 都無關。
     *
     * ⚠ **`paid = PENDING` 或「還沒收但已經有證明」兩種都算**
     * （需求方 2026-10-19 回報）。
     *
     * 第二種照理說不會存在（上傳證明一定會把 `paid` 寫成 `PENDING`），
     * 但真的出現時**它必須被撿起來**：`getBillingsForNotice()` 已經不收
     * 有證明的帳單了，這裡不接的話那一筆會從兩張清單上同時消失 ——
     * 客戶付了錢、證明躺在後台，而沒有任何人被通知。
     *
     * @return Collection
     */
    public function getPendingBillingsForReminder()
    {
        return VmBilling::query()
            /*
             * 這裡多撈 `updated_at` —— 催審核訊息要顯示「上傳時間」，而
             * `BILLING_COLUMNS` 沒有這一欄。沒 select 的欄位讀出來是 null
             * 且**不會報錯**，訊息就會默默變成「上傳時間：—」。
             *
             * 沒有專門記上傳時刻的欄位，`uploadProof()` 寫入 proof_image 時
             * `updated_at` 會跟著更新，審核前通常不會有別的更新，
             * 所以它是夠準的近似值。
             */
            ->select(array_merge(self::BILLING_COLUMNS, ['updated_at']))
            ->with(['vmServer.station'])
            ->where(function ($query) {
                $query->where('paid', config('constants.VM.BILLING.PENDING'))
                    // 狀態沒跟上，但證明已經在了 —— 一樣要有人去看
                    ->orWhere(function ($one) {
                        $one->where('paid', config('constants.VM.BILLING.UNPAID'))
                            ->whereNotNull('proof_image')
                            ->where('proof_image', '!=', '');
                    });
            })
            ->orderBy('due_date')
            ->get();
    }

    /**
     * 更新帳單
     *
     * @param VmBilling $billing
     * @param array     $attributes
     * @return VmBilling
     */
    public function updateBilling(VmBilling $billing, $attributes)
    {
        $billing->update($attributes);

        return $billing->fresh();
    }

    /**
     * 檢查帳單是否已存在
     *
     * @param int    $vmServerId
     * @param string $billingMonth
     * @return bool
     */
    /**
     * 查詢某 VM 某月的帳單
     *
     * @param int    $vmServerId
     * @param string $billingMonth
     * @return VmBilling|null
     */
    public function findBillingByMonth($vmServerId, $billingMonth)
    {
        return VmBilling::query()
            ->select(self::BILLING_COLUMNS)
            ->where('vm_server_id', $vmServerId)
            ->where('billing_month', $billingMonth)
            ->first();
    }

    public function billingExists($vmServerId, $billingMonth)
    {
        return VmBilling::query()
            ->where('vm_server_id', $vmServerId)
            ->where('billing_month', $billingMonth)
            ->exists();
    }
}
