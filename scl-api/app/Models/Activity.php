<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToBranch;

class Activity extends Model
{
    use SoftDeletes, BelongsToBranch;

    protected $fillable = [
        'branch_id', 'created_by', 'assigned_to',
        'subject_type', 'subject_id',
        'type', 'title', 'description',
        'due_at', 'completed_at', 'is_done', 'priority',
    ];

    protected function casts(): array
    {
        return [
            'due_at'       => 'datetime',
            'completed_at' => 'datetime',
            'is_done'      => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo    { return $this->belongsTo(User::class, 'created_by'); }
    public function assignedTo(): BelongsTo   { return $this->belongsTo(User::class, 'assigned_to'); }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
