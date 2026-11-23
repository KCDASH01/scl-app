<?php

namespace App\Http\Requests\Department;

use Illuminate\Foundation\Http\FormRequest;

class DepartmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'branch_id'   => ['nullable', 'integer', 'exists:branches,id'],
            'name'        => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],
            'member_ids'  => ['nullable', 'array'],
            'member_ids.*'=> ['integer', 'exists:users,id'],
        ];
    }
}
