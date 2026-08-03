<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Telegram 群組 API 回傳格式
 */
class TelegramGroupResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'              => $this->id,
            'chat_id'         => $this->chat_id,
            'title'           => $this->title,
            'status'          => $this->status,
            'assigned_user'   => new AccountResource($this->whenLoaded('assignedUser')),
            'last_message_at' => $this->last_message_at
                ? \Carbon\Carbon::parse($this->last_message_at)->toDateTimeString() : null,
        ];
    }
}
