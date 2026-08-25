<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 班別 API 回傳格式
 *
 * @property int    $id
 * @property string $name
 * @property string $display_name
 * @property string $start_time
 * @property string $end_time
 * @property bool   $is_active
 * @property int    $sort
 */
class ShiftResource extends JsonResource
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
            'id'           => $this->id,
            'name'         => $this->name,
            'display_name' => $this->display_name,
            'start_time'       => $this->start_time,
            'end_time'         => $this->end_time,
            'reply_start_time' => $this->reply_start_time,
            'reply_end_time'   => $this->reply_end_time,
            'is_active'    => $this->is_active,
            'sort'         => $this->sort,
        ];
    }
}
