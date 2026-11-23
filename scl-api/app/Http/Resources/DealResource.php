<?php

namespace App\Http\Resources;

use App\Services\FeeScheduleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $periods = app(FeeScheduleService::class)->summary($this->resource);

        return [
            'fee_periods' => $periods,
            'fee_unit' => $this->fee_unit,
            'fee_duration' => $this->fee_duration,
            'current_period' => collect($periods)->firstWhere('state', 'current') ?? collect($periods)->reverse()->firstWhere('state', 'paid'),
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'title' => $this->title,
            'fee_kind' => $this->fee_kind ?: 'course',
            'value' => $this->value,
            'currency' => $this->currency ?: 'INR',
            'status' => $this->status,
            'lost_reason' => $this->lost_reason,
            'original_value' => $this->original_value,
            'counter_offer_value' => $this->counter_offer_value,
            'negotiation_notes' => $this->negotiation_notes,
            'probability' => $this->probability,
            'description' => $this->description,
            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'closed_at' => $this->closed_at?->toDateString(),
            'handed_off_at' => $this->handed_off_at?->toIso8601String(),
            'total_received' => $this->payments_sum_amount !== null ? (float) $this->payments_sum_amount : null,
            'payments_count' => (int) ($this->payments_count ?? 0),
            'custom_fields' => $this->custom_fields,
            'pipeline_id' => $this->pipeline_id,
            'stage_id' => $this->stage_id,
            'lead_id' => $this->lead_id,
            'lead' => new LeadResource($this->whenLoaded('lead')),
            'stage' => new StageResource($this->whenLoaded('stage')),
            'pipeline' => new PipelineResource($this->whenLoaded('pipeline')),
            'assigned_to' => new UserResource($this->whenLoaded('assignedTo')),
            'created_by' => new UserResource($this->whenLoaded('createdBy')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
