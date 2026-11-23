<?php

namespace App\Http\Requests\Subject;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    public function rules(): array
    {
        $subjectId = $this->route('subject')?->id;
        $courseId = $this->input('course_id');

        return [
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'name'      => [
                'required',
                'string',
                'max:191',
                Rule::unique('subjects', 'name')
                    ->ignore($subjectId)
                    ->where(fn ($q) => $q->where('course_id', $courseId)->whereNull('deleted_at')),
            ],
            'code'      => [
                'required',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('subjects', 'code')
                    ->ignore($subjectId)
                    ->where(fn ($q) => $q->where('course_id', $courseId)->whereNull('deleted_at')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'course_id.required' => 'Select a course.',
            'name.unique'        => 'This subject name is already used in the selected course.',
            'code.unique'        => 'This subject code is already used in the selected course.',
        ];
    }
}
