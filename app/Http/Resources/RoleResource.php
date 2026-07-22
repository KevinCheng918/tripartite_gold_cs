<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'sort' => $this->sort,
            'permission_keywords' => $this->whenLoaded('permissions', function () {
                return $this->permission_keywords;
            }),
        ];
    }
}
