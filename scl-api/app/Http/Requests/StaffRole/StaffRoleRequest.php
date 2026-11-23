<?php

namespace App\Http\Requests\StaffRole;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StaffRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        $roleId = $this->route('staffRole')?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:191',
                Rule::unique('staff_roles', 'name')->ignore($roleId)->whereNull('deleted_at'),
                Rule::notIn(['Employee', 'Manager']),
            ],
        ];
    }
}
