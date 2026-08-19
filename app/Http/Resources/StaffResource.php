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
            'equipments' => $this->equipments ?? [],
        ];
    }
}
