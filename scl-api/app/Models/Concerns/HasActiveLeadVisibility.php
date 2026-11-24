<?php

namespace App\Models\Concerns;

use App\Support\ActiveLeadVisibility;
use Illuminate\Database\Eloquent\Builder;

trait HasActiveLeadVisibility
{
    public static function bootHasActiveLeadVisibility(): void
    {
        static::addGlobalScope('active_lead_visibility', fn (Builder $query) => ActiveLeadVisibility::apply($query));
    }
}
