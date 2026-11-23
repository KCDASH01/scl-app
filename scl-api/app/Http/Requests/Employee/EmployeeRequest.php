<?php

namespace App\Http\Requests\Employee;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canManageTeam();
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $userId   = $this->route('employee')?->id;
        $auth     = $this->user();
        $isSuper  = $auth?->isSuperAdmin();

        $rules = [
            'name'     => [$isCreate ? 'required' : 'sometimes', 'string', 'max:191'],
            'email'    => [
                $isCreate ? 'required' : 'sometimes',
                'email',
                'max:191',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => [
                $isCreate ? 'required' : 'required_without_all:name,email,phone,role,staff_role_id,avatar,branch_id,manager_id,is_active',
                'nullable',
                'string',
                'min:8',
            ],
            'phone'    => ['nullable', 'string', 'max:30'],
            'avatar'   => ['nullable', 'string', 'max:500'],
            'is_active'=> ['sometimes', 'boolean'],
        ];

        if ($isSuper) {
            $rules['role'] = [$isCreate ? 'required_without:staff_role_id' : 'sometimes', 'in:manager,employee'];
            $rules['staff_role_id'] = ['nullable', 'integer', Rule::exists('staff_roles', 'id')->whereNull('deleted_at')];
            $rules['branch_id'] = [$isCreate ? 'required' : 'sometimes', 'integer', 'exists:branches,id'];
            $rules['manager_id'] = [
                'nullable',
                'integer',
                'exists:users,id',
                Rule::requiredIf(fn () => ($this->input('role') ?? $this->route('employee')?->role) === 'employee'
                    && ($isCreate || $this->has('role') || $this->has('branch_id') || $this->has('manager_id'))),
            ];
        } else {
            $rules['role'] = ['nullable', 'in:employee'];
            $rules['staff_role_id'] = ['nullable', 'integer', Rule::exists('staff_roles', 'id')->whereNull('deleted_at')];
        }

        return $rules;
    }
}
