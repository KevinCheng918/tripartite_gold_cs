<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 專案 Resource
 *
 * @mixin \App\Models\Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id'   => $this->id,
            'name' => $this->name,
        ];
    }
}
