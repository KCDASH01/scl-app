<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToBranch;

class SalesTarget extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'branch_id',
        'user_id',
        'target_amount',
        'receivable_amount',
        'received_amount',
        'notes',
        'period_start',
    ];

    protected function casts(): array
    {
        return [
            'target_amount'     => 'decimal:2',
            'receivable_amount' => 'decimal:2',
            'received_amount'   => 'decimal:2',
            'period_start'      => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
