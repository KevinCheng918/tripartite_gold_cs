<?php

namespace App\Services;

use App\Repositories\VmRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 虛擬機管理 Service
 */
class VmService
{
    private $vmRepository;

    public function __construct(VmRepository $vmRepository)
    {
        $this->vmRepository = $vmRepository;
    }

    // ---------------------------------------------------------------
    //  VM Server
    // ---------------------------------------------------------------

    /**
     * 查詢 VM 列表（分頁）
     *
     * @param array $params
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function listServers($params)
    {
        $criteria = [
            'keyword'      => $params['keyword'] ?? null,
            'station_id'   => $params['station_id'] ?? null,
            'system_id'    => $params['system_id'] ?? null,
            'hostname'     => $params['hostname'] ?? null,
            'internal_ip'  => $params['internal_ip'] ?? null,
            'external_ip'  => $params['external_ip'] ?? null,
            'status'       => $params['status'] ?? null,
            'power_status' => $params['power_status'] ?? null,
        ];

        return $this->vmRepository->paginateServers($criteria, (int) ($params['per_page'] ?? 20));
    }

    /**
     * 新增 VM
     *
     * @param array $params
     * @return \App\Models\VmServer
     */
    public function createServer($params)
    {
        return $this->vmRepository->createServer($params);
    }

    /**
     * 更新 VM
     *
     * @param \App\Models\VmServer $server
     * @param array $params
     * @return \App\Models\VmServer
     */
    public function updateServer($server, $params)
    {
        return $this->vmRepository->updateServer($server, $params);
    }

    /**
     * 切換開關機狀態
     *
     * @param \App\Models\VmServer $server
     * @return \App\Models\VmServer
     */
    public function togglePower($server)
    {
        $newStatus = $server->power_status === 1 ? 0 : 1;
        $attrs = ['power_status' => $newStatus];

        if ($newStatus === 0) {
            $attrs['powered_off_at'] = now()->toDateString();
        } else {
            $attrs['powered_off_at'] = null;
        }

        return $this->vmRepository->updateServer($server, $attrs);
    }

    // ---------------------------------------------------------------
    //  VM Billing
    // ---------------------------------------------------------------

    /**
     * 查詢帳單列表（分頁）
     *
     * @param array $params
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function listBillings($params)
    {
        $criteria = [
            'billing_month' => $params['billing_month'] ?? null,
            'paid'          => $params['paid'] ?? null,
            'overdue'       => $params['overdue'] ?? null,
            'vm_server_id'  => $params['vm_server_id'] ?? null,
            'system_id'     => $params['system_id'] ?? null,
        ];

        return $this->vmRepository->paginateBillings($criteria, (int) ($params['per_page'] ?? 20));
    }

    /**
     * 上傳繳款證明（客服用）
     *
     * @param \App\Models\VmBilling $billing
     * @param \Illuminate\Http\UploadedFile $file
     * @return \App\Models\VmBilling
     */
    public function uploadProof($billing, $file)
    {
        $path = $file->store('vm-proof', 'public');

        return $this->vmRepository->updateBilling($billing, [
            'proof_image' => $path,
            'paid'        => 2, // 待審核
        ]);
    }

    /**
     * 審核繳款證明 — 標記已收款（管理者用）
     *
     * @param \App\Models\VmBilling $billing
     * @return \App\Models\VmBilling
     */
    public function approvePaid($billing)
    {
        return $this->vmRepository->updateBilling($billing, [
            'paid'    => 1,
            'paid_at' => now(),
        ]);
    }

    /**
     * 直接標記帳單已收款（管理者用，無需證明）
     *
     * @param \App\Models\VmBilling $billing
     * @return \App\Models\VmBilling
     */
    public function markPaid($billing)
    {
        return $this->vmRepository->updateBilling($billing, [
            'paid'    => 1,
            'paid_at' => now(),
        ]);
    }

    /**
     * 產生指定月份的帳單（所有啟用中的 VM）
     * 如果帳單已存在且金額不同，會列入 mismatches 供前端確認
     *
     * @param string|null $month        YYYY-MM，預設當月
     * @param bool        $forceUpdate  是否強制更新金額不同的帳單
     * @return array { generated: int, skipped: int, updated: int, mismatches: array }
     */
    public function generateMonthlyBillings($month = null, $forceUpdate = false)
    {
        $billingMonth = $month ?: now()->format('Y-m');
        $servers = $this->vmRepository->getActiveServers();

        $generated = 0;
        $skipped = 0;
        $updated = 0;
        $mismatches = [];

        foreach ($servers as $server) {
            // 關機的 VM 不產生帳單
            if ($server->power_status === 0) {
                continue;
            }
            $totalFee = (float) $server->monthly_fee + (float) $server->vpn_fee + (float) $server->google_fee;

            $existing = $this->vmRepository->findBillingByMonth($server->id, $billingMonth);

            if ($existing) {
                $oldAmount = (float) $existing->amount;
                // 金額不同且未收款
                if (abs($oldAmount - $totalFee) > 0.001 && (int) $existing->paid === 0) {
                    if ($forceUpdate) {
                        $this->vmRepository->updateBilling($existing, ['amount' => $totalFee]);
                        $updated++;
                    } else {
                        $stationName = $server->station ? $server->station->name : '-';
                        $mismatches[] = [
                            'billing_id'  => $existing->id,
                            'station'     => $stationName,
                            'hostname'    => $server->hostname,
                            'old_amount'  => number_format($oldAmount, 2),
                            'new_amount'  => number_format($totalFee, 2),
                        ];
                    }
                }
                $skipped++;
                continue;
            }

            // 新增帳單
            $year = (int) substr($billingMonth, 0, 4);
            $monthNum = (int) substr($billingMonth, 5, 2);
            $day = min($server->billing_day, Carbon::createFromDate($year, $monthNum, 1)->daysInMonth);
            $dueDate = Carbon::createFromDate($year, $monthNum, $day)->toDateString();

            $this->vmRepository->createBilling([
                'vm_server_id'  => $server->id,
                'billing_month' => $billingMonth,
                'amount'        => $totalFee,
                'due_date'      => $dueDate,
            ]);

            $generated++;
        }

        Log::info("VM 帳單產生完成", [
            'month'     => $billingMonth,
            'generated' => $generated,
            'skipped'   => $skipped,
            'updated'   => $updated,
        ]);

        return [
            'generated'  => $generated,
            'skipped'    => $skipped,
            'updated'    => $updated,
            'mismatches' => $mismatches,
        ];
    }
}
