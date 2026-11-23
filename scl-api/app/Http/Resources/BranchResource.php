<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'code'             => $this->code,
            'address'          => $this->address,
            'phone'            => $this->phone,
            'email'            => $this->email,
            'is_active'        => $this->is_active,
            'managers_count'   => (int) ($this->managers_count ?? $this->managers()->count()),
            'employees_count'  => (int) ($this->employees_count ?? $this->employees()->count()),
            'managers'         => UserResource::collection($this->whenLoaded('managers')),
            'employees'        => UserResource::collection($this->whenLoaded('employees')),
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
