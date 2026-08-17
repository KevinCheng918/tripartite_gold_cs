<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 任務留言 Resource
 *
 * @mixin \App\Models\TaskComment
 */
class TaskCommentResource extends JsonResource
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
            'content'    => $this->content,
            'created_at' => $this->created_at->format('Y-m-d H:i'),
        ];
    }
}
