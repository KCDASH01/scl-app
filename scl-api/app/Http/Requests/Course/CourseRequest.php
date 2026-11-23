<?php

namespace App\Http\Requests\Course;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('duration_value') && $this->input('duration_value') === '') {
            $merge['duration_value'] = null;
        }
        if ($this->has('duration_unit') && $this->input('duration_unit') === '') {
            $merge['duration_unit'] = null;
        }
        if ($this->has('description') && $this->input('description') === '') {
            $merge['description'] = null;
        }
        if ($this->has('price') && $this->input('price') === '') {
            $merge['price'] = null;
        }

        if ($merge) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $courseId = $this->route('course')?->id;
        $branchId = $this->input('branch_id') ?? $this->route('course')?->branch_id;

        return [
            'branch_id' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:191'],
            'code' => [
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('courses', 'code')
                    ->ignore($courseId)
                    ->where(fn ($q) => $q->where('branch_id', $branchId)->whereNull('deleted_at')),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_value' => ['nullable', 'integer', 'min:1', 'required_with:duration_unit,period_prices'],
            'duration_unit' => ['nullable', 'in:'.implode(',', Course::DURATION_UNITS), 'required_with:duration_value,period_prices'],
            'price' => ['required_without:period_prices', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'period_prices' => ['sometimes', 'nullable', 'array', 'min:1', 'max:10000'],
            'period_prices.*.period' => ['required', 'integer', 'min:1', 'distinct'],
            'period_prices.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'This course code is already used in the selected branch.',
            'duration_value.required_with' => 'Enter a duration value when a unit is selected.',
            'duration_unit.required_with' => 'Select a duration unit when a value is entered.',
        ];
    }
}
