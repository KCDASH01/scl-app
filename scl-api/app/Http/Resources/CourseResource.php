<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $duration = null;
        if ($this->duration_value && $this->duration_unit) {
            $duration = $this->duration_value.' '.$this->duration_unit;
        }

        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'duration_value' => $this->duration_value,
            'duration_unit' => $this->duration_unit,
            'duration' => $duration,
            'period_prices' => $this->period_prices,
            'price' => $this->price !== null ? (float) $this->price : null,
            'is_active' => $this->is_active,
            'batches_count' => (int) ($this->batches_count ?? 0),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
                'is_active' => $this->branch->is_active,
            ] : null),
            'batches' => BatchResource::collection($this->whenLoaded('batches')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
