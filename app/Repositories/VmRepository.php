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
        'model_type', 'spec', 'monthly_fee', 'vpn_fee', 'google_fee', 'billing_day',
        'power_status', 'status', 'note', 'created_at',
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

        if (isset($criteria['status']) && $criteria['status'] !== '' && $criteria['status'] !== null) {
            $query->where('status', (int) $criteria['status']);
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
     * 取得所有啟用中的 VM
     *
     * @return Collection
     */
    public function getActiveServers()
    {
        return VmServer::query()
            ->select(self::SERVER_COLUMNS)
            ->with(['station.system'])
            ->where('status', 1)
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
            ->with(['vmServer.station'])
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

        if (filled($criteria['vm_server_id'] ?? null)) {
            $query->where('vm_server_id', $criteria['vm_server_id']);
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
