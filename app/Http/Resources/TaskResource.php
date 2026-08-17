<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 任務 Resource
 *
 * @mixin \App\Models\Task
 */
class TaskResource extends JsonResource
{
    /** @var array 使用者快取（避免 N+1） */
    private static $userCache = [];

    /**
     * 預載使用者（在 collection 之前呼叫）
     *
     * @param array $userIds
     * @return void
     */
    public static function preloadUsers(array $userIds)
    {
        $missing = array_diff($userIds, array_keys(self::$userCache));
        if (empty($missing)) {
            return;
        }
        $users = User::query()
            ->select(['id', 'nickname'])
            ->whereIn('id', $missing)
            ->get();
        foreach ($users as $u) {
            self::$userCache[$u->id] = $u->nickname;
        }
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        $assigneeIds = $this->assignee_ids ?? [];
        $assignees = [];
        foreach ($assigneeIds as $id) {
            if (isset(self::$userCache[$id])) {
                $assignees[] = ['id' => $id, 'nickname' => self::$userCache[$id]];
            }
        }

        return [
            'id'           => $this->id,
            'project_id'   => $this->project_id,
            'project'      => $this->project ? $this->project->name : '-',
            'station_id'   => $this->station_id,
            'station'      => $this->station ? $this->station->name : null,
            'system'       => $this->station && $this->station->system ? $this->station->system->name : null,
            'title'        => $this->title,
            'description'  => $this->description,
            'images'       => array_map(function ($path) {
                return asset("storage/{$path}");
            }, $this->images ?? []),
            'status'       => $this->status,
            'priority'     => $this->priority,
            'assignee_ids' => $assigneeIds,
            'assignees'    => $assignees,
            'creator'      => $this->creator ? $this->creator->nickname : '-',
            'due_date'     => $this->due_date ? $this->due_date->format('Y-m-d') : null,
            'sort_order'   => $this->sort_order,
            'created_at'   => $this->created_at->format('Y-m-d H:i'),
            'updated_at'   => $this->updated_at ? $this->updated_at->format('Y-m-d H:i') : null,
        ];
    }
}
