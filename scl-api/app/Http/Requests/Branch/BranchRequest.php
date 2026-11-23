<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        $branchId = $this->route('branch')?->id;

        return [
            'name'      => ['required', 'string', 'max:191'],
            'code'      => [
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('branches', 'code')->ignore($branchId)->whereNull('deleted_at'),
            ],
            'address'   => ['nullable', 'string', 'max:500'],
            'phone'     => ['nullable', 'string', 'max:30'],
            'email'     => ['nullable', 'email', 'max:191'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
