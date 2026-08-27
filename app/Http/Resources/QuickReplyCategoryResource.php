<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 快速回覆類別 Resource（管理頁用，含停用項目）
 *
 * @mixin \App\Models\QuickReplyCategory
 */
class QuickReplyCategoryResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'     => $this->id,
            'label'  => $this->label,
            'sort'   => $this->sort,
            'status' => $this->status,
            'items'  => QuickReplyItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
