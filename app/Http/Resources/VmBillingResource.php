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
                    'id'         => $this->vmServer->id,
                    'hostname'   => $this->vmServer->hostname,
                    'station'    => $this->vmServer->station
                        ? ['id' => $this->vmServer->station->id, 'name' => $this->vmServer->station->name]
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
            'note'          => $this->note,
            'created_at'    => Carbon::parse($this->created_at)->toDateTimeString(),
        ];
    }
}
