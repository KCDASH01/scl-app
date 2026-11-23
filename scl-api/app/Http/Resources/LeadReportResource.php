<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'branch_id'       => $this->branch_id,
            'branch'          => $this->branch?->name,
            'lead_name'         => $this->full_name,
            'student_name'      => $this->full_name,
            'course'            => $this->admission?->course?->name ?? $this->course?->name,
            'enrollment_id'     => $this->admission?->enrollment_id,
            'admission_status'  => $this->admission?->status,
            'batch'             => $this->admission?->batch?->name,
            'contact_person'    => $this->full_name,
            'phone'           => $this->phone,
            'email'           => $this->email,
            'assigned_member' => $this->assignedTo?->name,
            'source'          => $this->source,
            'status'          => $this->status,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
            'follow_up_date'  => $this->follow_up_date
                ? (is_string($this->follow_up_date) ? $this->follow_up_date : $this->follow_up_date->toIso8601String())
                : null,
        ];
    }
}
