<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 任務 Resource
 *
 * @mixin \App\Models\Task
 */
class TaskResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'          => $this->id,
            'project_id'  => $this->project_id,
            'project'     => $this->project ? $this->project->name : '-',
            'station_id'  => $this->station_id,
            'station'     => $this->station ? $this->station->name : null,
            'system'      => $this->station && $this->station->system ? $this->station->system->name : null,
            'title'       => $this->title,
            'description' => $this->description,
            'status'      => $this->status,
            'priority'    => $this->priority,
            'assignee_id' => $this->assignee_id,
            'assignee'    => $this->assignee ? $this->assignee->nickname : null,
            'creator'     => $this->creator ? $this->creator->nickname : '-',
            'due_date'    => $this->due_date ? $this->due_date->format('Y-m-d') : null,
            'sort_order'  => $this->sort_order,
            'created_at'  => $this->created_at->format('Y-m-d H:i'),
            'updated_at'  => $this->updated_at ? $this->updated_at->format('Y-m-d H:i') : null,
        ];
    }
}
