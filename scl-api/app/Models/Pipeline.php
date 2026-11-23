<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToBranch;

class Pipeline extends Model
{
    use SoftDeletes, BelongsToBranch;

    protected $fillable = [
        'name', 'description', 'is_default', 'sort_order', 'branch_id',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->newQuery()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('sort_order');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
