<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Batch extends Model
{
    use SoftDeletes, BelongsToBranch;

    public const STATUSES = ['upcoming', 'ongoing', 'completed', 'cancelled'];

    protected $fillable = [
        'branch_id', 'course_id', 'name', 'code',
        'start_date', 'end_date', 'capacity', 'schedule',
        'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date'   => 'date',
            'capacity'   => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Batch $batch) {
            if (! $batch->course_id) {
                return;
            }

            $courseBranchId = Course::query()->whereKey($batch->course_id)->value('branch_id');
            if ($courseBranchId && (int) $batch->branch_id !== (int) $courseBranchId) {
                $batch->branch_id = (int) $courseBranchId;
            }
        });
    }

    public function hasLinkedRecords(): bool
    {
        return $this->admissions()->exists();
    }
}
