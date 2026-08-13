<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * VM Billing API 回傳格式
 */
class VmBillingResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        $overdueDays = 0;
        if ($this->paid === 0 && $this->due_date) {
            $graceDue = Carbon::parse($this->due_date)->addDays(3);
            if (now()->gt($graceDue)) {
                $overdueDays = (int) now()->diffInDays($graceDue);
            }
        }

        return [
            'id'            => $this->id,
            'vm_server_id'  => $this->vm_server_id,
            'vm_server'     => $this->whenLoaded('vmServer', function () {
                return [
                    'id'             => $this->vmServer->id,
                    'hostname'       => $this->vmServer->hostname,
                    'power_status'   => $this->vmServer->power_status,
                    'powered_off_at' => $this->vmServer->powered_off_at
                        ? Carbon::parse($this->vmServer->powered_off_at)->toDateString() : null,
                    'station'    => $this->vmServer->station
                        ? [
                            'id'        => $this->vmServer->station->id,
                            'name'      => $this->vmServer->station->name,
                            'system_id' => $this->vmServer->station->system_id,
                            'system'    => $this->vmServer->station->system ? $this->vmServer->station->system->name : null,
                            'telegram_group_id' => $this->vmServer->station->telegram_group_id,
                        ]
                        : null,
                ];
            }),
            'billing_month' => $this->billing_month,
            'amount'        => $this->amount,
            'paid'          => $this->paid,
            'paid_at'       => $this->paid_at ? Carbon::parse($this->paid_at)->toDateTimeString() : null,
            'proof_image'   => $this->proof_image ? asset("storage/{$this->proof_image}") : null,
            'due_date'      => $this->due_date ? Carbon::parse($this->due_date)->toDateString() : null,
            'overdue_days'  => $overdueDays,
            'prorated_fee'  => $this->calcProratedFee(),
            'note'          => $this->note,
            'created_at'    => Carbon::parse($this->created_at)->toDateTimeString(),
        ];
    }

    /**
     * 計算逾時費用（關機時：帳單日到關機日的日費）
     *
     * @return float|null
     */
    private function calcProratedFee()
    {
        if (!$this->vmServer || $this->vmServer->power_status !== 0 || !$this->vmServer->powered_off_at) {
            return null;
        }

        if (!$this->due_date) {
            return null;
        }

        $dueDate = Carbon::parse($this->due_date);
        $offDate = Carbon::parse($this->vmServer->powered_off_at);

        // 關機日在帳單日之前，不算逾時
        if ($offDate->lte($dueDate)) {
            return null;
        }

        $usedDays = (int) $dueDate->diffInDays($offDate);
        $dailyRate = (float) $this->amount / 30;

        return round($dailyRate * $usedDays, 2);
    }
}
