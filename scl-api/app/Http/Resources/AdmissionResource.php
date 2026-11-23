<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'branch_id' => $this->branch_id,
            'lead_id' => $this->lead_id,
            'course_id' => $this->course_id,
            'batch_id' => $this->batch_id,
            'academic_year_id' => $this->academic_year_id,
            'student_year' => $this->student_year,
            'admission_date' => $this->admission_date?->toDateString(),
            'status' => $this->status,
            'fee_schedule' => $this->fee_schedule,
            'hostel_stay' => (bool) $this->hostel_stay,
            'hostel_amount' => $this->hostel_amount !== null ? (float) $this->hostel_amount : null,
            'lead' => $this->whenLoaded('lead', fn () => $this->lead ? [
                'id' => $this->lead->id,
                'full_name' => $this->lead->full_name,
                'first_name' => $this->lead->first_name,
                'last_name' => $this->lead->last_name,
                'email' => $this->lead->email,
                'phone' => $this->lead->phone,
                'city' => $this->lead->city,
                'state' => $this->lead->state,
                'country' => $this->lead->country,
                'pin' => $this->lead->pin,
                'assigned_to' => $this->lead->relationLoaded('assignedTo') && $this->lead->assignedTo ? [
                    'id' => $this->lead->assignedTo->id,
                    'name' => $this->lead->assignedTo->name,
                ] : null,
            ] : null),
            'course' => $this->whenLoaded('course', fn () => $this->course ? [
                'id' => $this->course->id,
                'name' => $this->course->name,
                'code' => $this->course->code,
                'is_active' => $this->course->is_active,
                'branch_id' => $this->course->branch_id,
            ] : null),
            'academic_year' => $this->whenLoaded('academicYear', fn () => $this->academicYear ? [
                'id' => $this->academicYear->id,
                'name' => $this->academicYear->name,
                'is_active' => $this->academicYear->is_active,
            ] : null),
            'batch' => $this->whenLoaded('batch', fn () => $this->batch ? [
                'id' => $this->batch->id,
                'name' => $this->batch->name,
                'code' => $this->batch->code,
                'status' => $this->batch->status,
                'course_id' => $this->batch->course_id,
            ] : null),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
                'is_active' => $this->branch->is_active,
            ] : null),
            'documents' => AdmissionDocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
