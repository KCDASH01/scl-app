<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'branch_id'      => $this->branch_id,
            'name'           => $this->name,
            'is_active'      => $this->is_active,
            'expenses_count' => (int) ($this->expenses_count ?? 0),
            'branch'         => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id'        => $this->branch->id,
                'name'      => $this->branch->name,
                'code'      => $this->branch->code,
                'is_active' => $this->branch->is_active,
            ] : null),
            'created_at'     => $this->created_at?->toIso8601String(),
            'updated_at'     => $this->updated_at?->toIso8601String(),
        ];
    }
}
