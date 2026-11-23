<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HostelExpense extends Model
{
    use SoftDeletes, BelongsToBranch;

    public const PAYMENT_MODES = [
        'upi', 'bank_transfer', 'cash', 'cheque', 'card', 'aggregator', 'other',
    ];

    protected $fillable = [
        'branch_id', 'hostel_id', 'expense_category_id',
        'amount', 'expense_date', 'payment_mode', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'expense_date' => 'date',
        ];
    }

    public function hostel(): BelongsTo
    {
        return $this->belongsTo(Hostel::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
