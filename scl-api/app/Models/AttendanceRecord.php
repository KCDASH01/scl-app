<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    use \App\Models\Concerns\HasActiveLeadVisibility;

    use BelongsToBranch;

    public const STATUSES = ['present', 'absent'];

    protected $fillable = [
        'branch_id', 'course_id', 'batch_id', 'admission_id',
        'subject_id', 'subject_key',
        'attendance_date', 'marked_at', 'status', 'marked_by',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'marked_at'       => 'datetime',
        ];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
