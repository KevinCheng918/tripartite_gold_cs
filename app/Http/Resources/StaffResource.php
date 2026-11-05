<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 內部管理員工 Resource
 *
 * @mixin \App\Models\User
 */
class StaffResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        $tenure = null;
        if (filled($this->hired_at)) {
            if ($this->hired_at->isFuture()) {
                $tenure = '尚未到職';
            } else {
                $diff = $this->hired_at->diff(now());
                $parts = [];
                if ($diff->y > 0) { $parts[] = "{$diff->y} 年"; }
                if ($diff->m > 0) { $parts[] = "{$diff->m} 個月"; }
                if ($diff->d > 0) { $parts[] = "{$diff->d} 天"; }
                $tenure = !empty($parts) ? implode(' ', $parts) : '今天到職';
            }
        }

        return [
            'id'         => $this->id,
            'nickname'   => $this->nickname,
            'account'    => $this->account,
            'level'      => $this->level,
            'status'     => $this->status,
            'hired_at'    => $this->hired_at ? $this->hired_at->format('Y-m-d') : null,
            'resigned_at' => $this->resigned_at ? $this->resigned_at->format('Y-m-d') : null,
            'tenure'     => $tenure,
            /*
             * 本年度特休（需求方 2026-11-05：人員管理要按年資算特休天數）。
             *
             * ⚠ **在 Resource 裡算，不是在列表查詢裡** —— 每一列都要跑一次
             * `LeaveQuotaService`，而那一支要查該員的假單。人員列表的量級很小
             * （內勤幾十人），但日後如果這張表變大，要改成先一次撈完再分配。
             *
             * ⚠ 回「小時」不回「日」：請假是用小時計的，顯示成日會讓同仁
             * 算不出「還能請幾個下午」。日數另外給，兩個都要。
             */
            'annual_leave' => $this->annualLeave(),
            'equipments' => $this->equipments ?? [],
        ];
    }

    /**
     * 本年度特休：額度、已用、剩餘
     *
     * ⚠ 還沒滿六個月的人 `entitled_days` 是 0、`period_start` 是 null ——
     * 前端靠那個 null 分辨「還沒有特休」與「有特休但用完了」，
     * 不要在這裡夾成 0 把兩者混成同一種。
     *
     * @return array
     */
    private function annualLeave()
    {
        $summary = app(\App\Services\LeaveQuotaService::class)->summaryFor($this->resource);

        return [
            'entitled_days'  => $summary['entitled_days'],
            'period_start'   => $summary['period_start'],
            'period_end'     => $summary['period_end'],
            'available_hours' => round($summary['available_minutes'] / 60, 1),
            'used_hours'      => round($summary['used_minutes'] / 60, 1),
            'remaining_hours' => round($summary['remaining_minutes'] / 60, 1),
        ];
    }
}
