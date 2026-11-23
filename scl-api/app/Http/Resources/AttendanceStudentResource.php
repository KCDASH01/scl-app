<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceStudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $record = $this->attendance_record ?? null;

        return [
            'admission_id'   => $this->id,
            'enrollment_id'  => $this->enrollment_id,
            'student_name'   => $this->lead?->full_name,
            'phone'          => $this->lead?->phone,
            'email'          => $this->lead?->email,
            'attendance_id'  => $record?->id,
            'status'         => $record?->status,
            'marked_at'      => $record?->marked_at?->toIso8601String(),
            'marked_by'      => $record?->markedBy?->name,
        ];
    }
}
