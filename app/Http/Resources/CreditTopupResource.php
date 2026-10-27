<?php

namespace App\Http\Resources;

use App\Presenters\NumberPresenter;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 補點/扣點紀錄 Resource
 *
 * @mixin \App\Models\CreditTopup
 */
class CreditTopupResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'            => $this->id,
            'system'        => $this->station && $this->station->system ? $this->station->system->name : '-',
            'station'       => $this->station ? $this->station->name : '-',
            'station_id'    => $this->station_id,
            'action_type'   => $this->action_type,
            'credit_type'   => $this->credit_type,
            'input_type'    => $this->input_type,
            'usdt_amount'   => NumberPresenter::trimZeros($this->usdt_amount, 4),
            'exchange_rate' => NumberPresenter::trimZeros($this->exchange_rate, 4),
            'credit_amount' => NumberPresenter::trimZeros($this->credit_amount, 2),
            'status'        => $this->status,
            'api_response'  => $this->api_response,
            'requester'     => $this->requester ? $this->requester->nickname : '-',
            'reviewer'      => $this->reviewer ? $this->reviewer->nickname : null,
            'reviewed_at'   => $this->reviewed_at ? $this->reviewed_at->format('Y-m-d H:i') : null,
            /*
             * ⚠ 這兩個是**兩件事**：`notify_issue` 是 `old_format` 時
             * `notified_at` 仍然有值（訊息有送出去，只是格式舊）。
             * 前端要靠這個組合分出「沒送到」與「送到了但該更新主系統」。
             */
            'notified_at'   => $this->notified_at ? $this->notified_at->format('Y-m-d H:i') : null,
            'notify_issue'  => $this->notify_issue,
            'note'          => $this->note,
            'images'        => array_map(function ($path) {
                return asset("storage/{$path}");
            }, $this->images ?? []),
            'created_at'    => $this->created_at->format('Y-m-d H:i'),
        ];
    }
}
