<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdmissionReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'enrollment_id'   => $this->enrollment_id,
            'branch'          => $this->branch?->name,
            'student_name'    => $this->lead?->full_name,
            'phone'           => $this->lead?->phone,
            'email'           => $this->lead?->email,
            'city'            => $this->lead?->city,
            'course'          => $this->course?->name,
            'course_code'     => $this->course?->code,
            'batch'           => $this->batch?->name,
            'batch_code'      => $this->batch?->code,
            'admission_date'  => $this->admission_date?->toDateString(),
            'status'          => $this->status,
            'assigned_member' => $this->lead?->assignedTo?->name,
        ];
    }
}
