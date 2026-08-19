<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 帳號 API 回傳格式
 *
 * @property int    $id
 * @property string $account
 * @property string $nickname
 * @property int    $status
 * @property int    $level
 * @property string $created_at
 */
class AccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'                  => $this->id,
            'account'             => $this->account,
            'nickname'            => $this->nickname,
            'status'              => $this->status,
            'level'               => $this->level,
            'project_ids'         => $this->project_ids ?? [],
            'hired_at'            => $this->hired_at ? $this->hired_at->format('Y-m-d') : null,
            'tenure'              => $this->hired_at ? $this->calcTenure() : null,
            'equipments'          => $this->equipments ?? [],
            'permission_keywords' => $this->whenLoaded('permissions', function () {
                return $this->permissions->pluck('permission_keyword')->all();
            }),
            'created_at'          => \Carbon\Carbon::parse($this->created_at)->toDateTimeString(),
        ];
    }

    /**
     * 計算年資（年/月/日）
     *
     * @return string
     */
    private function calcTenure()
    {
        if ($this->hired_at->isFuture()) {
            return '尚未到職';
        }

        $diff = $this->hired_at->diff(now());
        $parts = [];
        if ($diff->y > 0) { $parts[] = "{$diff->y} 年"; }
        if ($diff->m > 0) { $parts[] = "{$diff->m} 個月"; }
        if ($diff->d > 0) { $parts[] = "{$diff->d} 天"; }

        return !empty($parts) ? implode(' ', $parts) : '今天到職';
    }
}
