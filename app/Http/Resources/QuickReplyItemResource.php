<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 快速回覆問答 Resource
 *
 * @mixin \App\Models\QuickReplyItem
 */
class QuickReplyItemResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'category_id' => $this->category_id,
            'label'       => $this->label,
            'answer'      => $this->answer,
            'sort'        => $this->sort,
            'status'      => $this->status,
            // 客人問過的說法有幾筆。只在管理列表帶出來（whenCounted），
            // 聊天視窗的選單不需要這個數字
            'phrasing_count' => $this->whenCounted('phrasings'),
        ];
    }
}
