<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'created_at' => optional($this->created_at)->toDateTimeString(),
        ];
    }
}
