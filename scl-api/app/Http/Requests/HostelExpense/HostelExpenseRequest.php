<?php

namespace App\Http\Requests\HostelExpense;

use App\Models\HostelExpense;
use Illuminate\Foundation\Http\FormRequest;

class HostelExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManageTeam();
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['notes', 'payment_mode'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $merge[$field] = null;
            }
        }
        if ($merge) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        return [
            'branch_id'            => ['nullable', 'integer', 'exists:branches,id'],
            'hostel_id'            => ['required', 'integer', 'exists:hostels,id'],
            'expense_category_id'  => ['required', 'integer', 'exists:expense_categories,id'],
            'amount'               => ['required', 'numeric', 'min:0.01'],
            'expense_date'         => ['required', 'date'],
            'payment_mode'         => ['nullable', 'in:'.implode(',', HostelExpense::PAYMENT_MODES)],
            'notes'                => ['nullable', 'string', 'max:2000'],
        ];
    }
}
