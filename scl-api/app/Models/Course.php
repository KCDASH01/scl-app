<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use BelongsToBranch, SoftDeletes;

    public const DURATION_UNITS = ['days', 'weeks', 'months', 'years'];

    protected $fillable = [
        'branch_id', 'name', 'code', 'description',
        'duration_value', 'duration_unit', 'price', 'is_active', 'period_prices',
    ];

    protected function casts(): array
    {
        return [
            'period_prices' => 'array',
            'is_active' => 'boolean',
            'duration_value' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function hasBatches(): bool
    {
        return $this->batches()->exists();
    }

    public function hasLinkedRecords(): bool
    {
        return $this->hasBatches() || $this->leads()->exists() || $this->admissions()->exists();
    }
}
