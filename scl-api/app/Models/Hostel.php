<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Hostel extends Model
{
    use SoftDeletes, BelongsToBranch;

    protected $fillable = [
        'branch_id', 'name', 'code', 'address', 'capacity', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'capacity'  => 'integer',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(HostelExpense::class);
    }

    public function hasLinkedRecords(): bool
    {
        return $this->expenses()->exists();
    }
}
