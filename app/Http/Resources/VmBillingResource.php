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
            /*
             * 繳款證明是誰在什麼時候上傳的（需求方 2026-11-01）。
             *
             * ⚠ **舊帳單這兩個會是 null**：欄位是 2026-11-01 才加的，
             * 而 migration 刻意沒有回填（「誰上傳的」補不出來，
             * 「什麼時候」只能用 `updated_at` 近似，而把近似值寫進一個讀起來
             * 像事實的欄位，日後沒有人分得出哪一筆是真的）。
             * 前端顯示「—」。
             *
             * ⚠ `proofUploader` 關聯是 `withTrashed()` 的，所以上傳者離職了
             * 名字照樣出得來 —— 這是財務紀錄，「當時是誰上傳的」不該消失。
             */
            'proof_uploader'    => filled($this->proofUploader) ? $this->proofUploader->nickname : null,
            'proof_uploaded_at' => filled($this->proof_uploaded_at)
                ? Carbon::parse($this->proof_uploaded_at)->toDateTimeString() : null,
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
