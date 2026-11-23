<?php

namespace App\Http\Requests\ExpenseCategory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManageTeam();
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('branch_id') && ! $this->input('branch_id')) {
            $this->merge(['branch_id' => null]);
        }
    }

    public function rules(): array
    {
        $categoryId = $this->route('expense_category')?->id;
        $branchId = $this->input('branch_id')
            ?? $this->route('expense_category')?->branch_id
            ?? $this->user()?->branch_id;

        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name'      => [
                'required',
                'string',
                'max:191',
                Rule::unique('expense_categories', 'name')
                    ->ignore($categoryId)
                    ->where(fn ($q) => $q->where('branch_id', $branchId)->whereNull('deleted_at')),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'This expense type already exists in the selected branch.',
        ];
    }
}
