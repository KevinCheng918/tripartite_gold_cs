<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 消耗品品項 API 回傳格式
 *
 * 管理清單與登記用的下拉共用 —— 下拉只會拿到啟用中的那些，
 * 但欄位形狀一樣，不必為此多一個 Resource。
 */
class ConsumableItemResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'unit'       => $this->unit,
            'status'     => (int) $this->status,
            // 前端用它決定要不要在清單上標「已停用」，不用自己比常數
            'is_active'  => $this->isActive(),
            'sort_order' => (int) $this->sort_order,
            'note'       => $this->note,
        ];
    }
}
