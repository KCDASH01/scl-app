<?php

namespace App\Http\Requests\Hostel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HostelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManageTeam();
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['address', 'capacity'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $merge[$field] = null;
            }
        }
        if ($this->has('branch_id') && ! $this->input('branch_id')) {
            $merge['branch_id'] = null;
        }
        if ($merge) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $hostelId = $this->route('hostel')?->id;
        $branchId = $this->input('branch_id')
            ?? $this->route('hostel')?->branch_id
            ?? $this->user()?->branch_id;

        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name'      => ['required', 'string', 'max:191'],
            'code'      => [
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('hostels', 'code')
                    ->ignore($hostelId)
                    ->where(fn ($q) => $q->where('branch_id', $branchId)->whereNull('deleted_at')),
            ],
            'address'   => ['nullable', 'string', 'max:255'],
            'capacity'  => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This hostel code is already used in the selected branch.',
        ];
    }
}
