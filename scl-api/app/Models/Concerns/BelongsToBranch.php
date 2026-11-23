<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Services\BranchScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

trait BelongsToBranch
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $query = $this->newQuery()->where($field ?? $this->getRouteKeyName(), $value);

        $user = request()->user();
        if ($user && Schema::hasColumn($this->getTable(), 'branch_id')) {
            app(BranchScope::class)->constrainVisible($query, $user);
        }

        return $query->first();
    }
}
