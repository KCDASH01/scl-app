<?php

namespace App\Http\Requests\Batch;

use App\Models\Batch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['end_date', 'capacity', 'schedule', 'notes'] as $field) {
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
        $batch = $this->route('batch');
        $courseId = $this->route('course')?->id ?? $batch?->course_id;

        return [
            'name'       => ['required', 'string', 'max:191'],
            'code'       => [
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('batches', 'code')
                    ->ignore($batch?->id)
                    ->where(fn ($q) => $q->where('course_id', $courseId)->whereNull('deleted_at')),
            ],
            'start_date' => ['required', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
            'capacity'   => ['nullable', 'integer', 'min:1'],
            'schedule'   => ['nullable', 'string', 'max:191'],
            'status'     => ['sometimes', 'in:'.implode(',', Batch::STATUSES)],
            'notes'      => ['nullable', 'string', 'max:2000'],
            'course_id'  => ['sometimes', 'integer', 'exists:courses,id'],
            'branch_id'  => ['sometimes', 'integer', 'exists:branches,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique'              => 'This batch code is already used in this course.',
            'end_date.after_or_equal'  => 'End date cannot be before the start date.',
            'capacity.min'             => 'Capacity must be a positive whole number.',
        ];
    }
}
