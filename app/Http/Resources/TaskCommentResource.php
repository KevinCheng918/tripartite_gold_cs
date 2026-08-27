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
            'images'     => array_map(function ($path) {
                return asset("storage/{$path}");
            }, $this->images ?? []),
            'created_at' => $this->created_at->format('Y-m-d H:i'),
            // 只有本人能編輯自己的留言，前端據此決定是否顯示編輯鈕
            'is_mine'    => filled($request->user()) && $this->user_id === $request->user()->id,
            'is_edited'  => filled($this->updated_at) && $this->updated_at->gt($this->created_at),
        ];
    }
}
