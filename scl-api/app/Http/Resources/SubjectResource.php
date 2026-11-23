<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'branch_id' => $this->branch_id,
            'course_id' => $this->course_id,
            'name'      => $this->name,
            'code'      => $this->code,
            'is_active' => $this->is_active,
            'course'    => $this->whenLoaded('course', fn () => $this->course ? [
                'id'   => $this->course->id,
                'name' => $this->course->name,
                'code' => $this->course->code,
            ] : null),
            'branch'    => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id'   => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
            ] : null),
        ];
    }
}
