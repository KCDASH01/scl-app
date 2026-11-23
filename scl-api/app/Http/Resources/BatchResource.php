<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'branch_id'  => $this->branch_id,
            'course_id'  => $this->course_id,
            'name'       => $this->name,
            'code'       => $this->code,
            'start_date' => $this->start_date?->toDateString(),
            'end_date'   => $this->end_date?->toDateString(),
            'capacity'   => $this->capacity,
            'schedule'   => $this->schedule,
            'status'     => $this->status,
            'notes'      => $this->notes,
            'course'     => $this->whenLoaded('course', fn () => $this->course ? [
                'id'        => $this->course->id,
                'name'      => $this->course->name,
                'code'      => $this->course->code,
                'is_active' => $this->course->is_active,
            ] : null),
            'branch'     => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id'        => $this->branch->id,
                'name'      => $this->branch->name,
                'code'      => $this->branch->code,
                'is_active' => $this->branch->is_active,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
