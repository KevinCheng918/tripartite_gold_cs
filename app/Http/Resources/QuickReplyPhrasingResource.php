<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 題庫問法樣本 Resource
 *
 * @mixin \App\Models\QuickReplyPhrasing
 */
class QuickReplyPhrasingResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'         => $this->id,
            'text'       => $this->text,
            'source'     => $this->source,
            'created_at' => $this->created_at ? $this->created_at->toDateTimeString() : null,
        ];
    }
}
