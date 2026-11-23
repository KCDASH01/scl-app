<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'full_name' => $this->full_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'source' => $this->source,
            'course_id' => $this->course_id,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'pin' => $this->pin,
            'course' => $this->whenLoaded('course', fn () => $this->course ? [
                'id' => $this->course->id,
                'name' => $this->course->name,
                'code' => $this->course->code,
                'is_active' => $this->course->is_active,
                'branch_id' => $this->course->branch_id,
                'period_prices' => $this->course->period_prices,
                'duration_unit' => $this->course->duration_unit,
                'duration_value' => $this->course->duration_value,
                'price' => $this->course->price !== null ? (float) $this->course->price : null,
            ] : null),
            'pipeline_id' => $this->pipeline_id,
            'stage_id' => $this->stage_id,
            'external_lead_id' => $this->external_lead_id,
            'custom_fields' => $this->custom_fields,
            'assigned_to' => new UserResource($this->whenLoaded('assignedTo')),
            'created_by' => new UserResource($this->whenLoaded('createdBy')),
            'stage' => new StageResource($this->whenLoaded('stage')),
            'pipeline' => new PipelineResource($this->whenLoaded('pipeline')),
            'proposals_count' => (int) ($this->proposals_count ?? 0),
            'lost_reason' => $this->lost_reason,
            'lead_date' => $this->lead_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
