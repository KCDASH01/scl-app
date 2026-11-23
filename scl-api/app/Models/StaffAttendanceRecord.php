<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAttendanceRecord extends Model
{
    public const STATUSES = ['present', 'absent'];

    protected $fillable = [
        'user_id', 'branch_id', 'attendance_date', 'status', 'marked_at', 'marked_by',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'marked_at'       => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
