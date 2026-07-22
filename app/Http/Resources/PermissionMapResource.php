<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PermissionMapResource extends JsonResource
{
    public function toArray($request)
    {
        return $this->resource;
    }
}
