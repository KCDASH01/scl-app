<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DealPayment extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'branch_id',
        'deal_id',
        'created_by',
        'amount', 'allocations', 'receipt_snapshot',
        'payment_date',
        'payment_mode',
        'txn_or_utr_number',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'allocations' => 'array',
            'receipt_snapshot' => 'array',
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DealPayment $payment) {
            if (! Schema::hasColumn('deal_payments', 'organization_id') || $payment->getAttribute('organization_id')) {
                return;
            }

            $orgId = DB::table('organizations')->orderBy('id')->value('id');
            if ($orgId) {
                $payment->setAttribute('organization_id', $orgId);
            }
        });
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
