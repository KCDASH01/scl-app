<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffRoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'access'      => $this->access,
            'is_system'   => $this->isSystem(),
            'users_count' => $this->whenCounted('users'),
        ];
    }
}
