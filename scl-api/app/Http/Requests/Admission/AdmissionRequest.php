<?php

namespace App\Http\Requests\Admission;

use App\Models\Admission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $patch = [];
        foreach (['course_id', 'batch_id', 'academic_year_id', 'student_year', 'admission_date', 'hostel_amount'] as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $patch[$field] = null;
            }
        }
        if ($this->has('hostel_stay') && ! $this->boolean('hostel_stay')) {
            $patch['hostel_amount'] = null;
        }
        if ($patch) {
            $this->merge($patch);
        }
    }

    public function rules(): array
    {
        return [
            'fee_periods' => ['sometimes', 'array', 'min:1'],
            'fee_periods.*.period' => ['required', 'integer', 'min:1', 'distinct'],
            'fee_periods.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'fee_periods.*.hostel_stay' => ['required', 'boolean'],
            'fee_periods.*.hostel_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'fee_change_reason' => ['nullable', 'string', 'max:1000'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'batch_id' => ['nullable', 'integer', 'exists:batches,id'],
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
            'student_year' => ['nullable', Rule::in(Admission::STUDENT_YEARS)],
            'admission_date' => ['nullable', 'date'],
            'hostel_stay' => ['sometimes', 'boolean'],
            'hostel_amount' => [
                Rule::requiredIf(fn () => ! $this->has('fee_periods') && $this->boolean('hostel_stay')),
                'nullable',
                'numeric',
                'min:0.01',
            ],
        ];
    }
}
