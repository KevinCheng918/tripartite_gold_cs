<?php

namespace App\Http\Resources;

use App\Presenters\UserPresenter;
use App\Services\LeaveQuotaService;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

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
        /*
         * ⚠ 年資走 `UserPresenter::tenureText()`，**不要在這裡自己 `diff()`**
         * —— `DateTime::diff()` 的年月日拆解在 Asia/Taipei 會跳號
         * （到職日差一天、年資差兩天），理由與實測見那一支的 docblock。
         * 這裡原本就是自己算的那一份，`AccountResource` 還有第二份 ——
         * 兩份已經合併成一支。
         */
        $tenure = UserPresenter::tenureText($this->hired_at);

        return [
            'id'         => $this->id,
            'nickname'   => $this->nickname,
            'account'    => $this->account,
            'level'      => $this->level,
            'status'     => $this->status,
            'hired_at'    => filled($this->hired_at) ? $this->hired_at->format('Y-m-d') : null,
            'resigned_at' => filled($this->resigned_at) ? $this->resigned_at->format('Y-m-d') : null,
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
            // JSON cast 欄位，沒設過是 null —— 前端一律當陣列跑，所以補成 []
            'equipments' => filled($this->equipments) ? $this->equipments : [],
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
        $summary = app(LeaveQuotaService::class)->summaryFor($this->resource);

        return [
            'entitled_days'   => Arr::get($summary, 'entitled_days'),
            'period_start'    => Arr::get($summary, 'period_start'),
            'period_end'      => Arr::get($summary, 'period_end'),
            'available_hours' => round(Arr::get($summary, 'available_minutes') / 60, 1),
            'used_hours'      => round(Arr::get($summary, 'used_minutes') / 60, 1),
            'remaining_hours' => round(Arr::get($summary, 'remaining_minutes') / 60, 1),
        ];
    }
}
