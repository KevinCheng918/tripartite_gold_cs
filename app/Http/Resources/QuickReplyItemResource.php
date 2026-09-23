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
            /*
             * 客人問過的說法有幾筆。只在管理列表帶出來（那邊才有 withCount），
             * 聊天視窗的選單不需要這個數字。
             *
             * ⚠ 不能用 whenCounted() —— 那是 Laravel 9 才有的，本專案是 8。
             *
             * 這裡 filled() 剛好是對的判斷：沒 withCount 時取到 null（不輸出），
             * 真的是 0 筆時 filled(0) 為 true 會輸出 0。
             */
            'phrasing_count' => $this->when(filled($this->phrasings_count), $this->phrasings_count),
        ];
    }
}
