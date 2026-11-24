<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deal extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('active_lead_visibility', function (\Illuminate\Database\Eloquent\Builder $query) {
            if (! app()->bound('request') || ! request()->attributes->get('filter_deleted_lead_records', false)) {
                return;
            }
            $query->whereNull('deals.deleted_at')->where(function ($q) {
                $q->whereNull('deals.lead_id')->orWhereExists(fn ($lead) => $lead->selectRaw('1')->from('leads')
                    ->whereColumn('leads.id', 'deals.lead_id')->whereNull('leads.deleted_at'));
            });
        });
    }

    protected $table = 'deals';

    protected $fillable = [
        'organization_id',
        'assigned_to',
        'status',
        'value',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'value'     => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
