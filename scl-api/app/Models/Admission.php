<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Admission extends Model
{
    use BelongsToBranch, SoftDeletes;

    public const STATUSES = ['pending', 'admitted'];

    public const STUDENT_YEARS = ['1st', '2nd', '3rd', '4th'];

    protected $fillable = [
        'lead_id', 'branch_id', 'course_id', 'batch_id',
        'academic_year_id', 'student_year',
        'admission_date', 'status', 'enrollment_id',
        'hostel_stay', 'hostel_amount', 'fee_schedule',
    ];

    protected function casts(): array
    {
        return [
            'admission_date' => 'date',
            'fee_schedule' => 'array',
            'hostel_stay' => 'boolean',
            'hostel_amount' => 'decimal:2',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AdmissionDocument::class)->latest();
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
