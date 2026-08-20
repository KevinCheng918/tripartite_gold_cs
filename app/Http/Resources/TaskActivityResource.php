<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 任務活動紀錄 Resource
 *
 * @mixin \App\Models\TaskActivity
 */
class TaskActivityResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'         => $this->id,
            'user'       => $this->user ? $this->user->nickname : '-',
            'action'     => $this->action,
            'changes'    => $this->changes,
            'created_at' => $this->created_at->format('Y-m-d H:i'),
        ];
    }
}
