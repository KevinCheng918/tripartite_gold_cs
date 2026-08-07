<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Telegram 訊息 API 回傳格式
 */
class TelegramMessageResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'direction'   => $this->direction,
            'sender_name' => $this->sender_name,
            'content'     => $this->content,
            'media_type'  => $this->media_type,
            'media_url'   => $this->media_url,
            'replied'     => $this->replied,
            'created_at'  => \Carbon\Carbon::parse($this->created_at)->toDateTimeString(),
        ];
    }
}
