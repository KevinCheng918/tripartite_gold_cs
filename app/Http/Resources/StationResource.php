<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 站台 API 回傳格式
 */
class StationResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'                => $this->id,
            'system_id'         => $this->system_id,
            'system'            => $this->whenLoaded('system', function () {
                return ['id' => $this->system->id, 'name' => $this->system->name];
            }),
            'name'              => $this->name,
            'domain'            => $this->domain,
            'api_url'           => $this->api_url,
            'api_key'           => $this->api_key,
            'credits'           => $this->credits,
            'settings'          => $this->settings,
            'telegram_group_id' => $this->telegram_group_id,
            'telegram_chat_id'  => $this->whenLoaded('telegramGroup', function () {
                return $this->telegramGroup ? $this->telegramGroup->chat_id : null;
            }),
            'telegram_group'    => $this->whenLoaded('telegramGroup', function () {
                return ['id' => $this->telegramGroup->id, 'title' => $this->telegramGroup->title];
            }),
            'status'            => $this->status,
            'note'              => $this->note,
            'synced_at'         => $this->synced_at
                ? \Carbon\Carbon::parse($this->synced_at)->toDateTimeString() : null,
            'created_at'        => \Carbon\Carbon::parse($this->created_at)->toDateTimeString(),
        ];
    }
}
