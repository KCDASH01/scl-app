<?php

namespace App\Http\Requests\Pipeline;

use Illuminate\Foundation\Http\FormRequest;

class PipelineRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'branch_id'   => ['nullable', 'integer', 'exists:branches,id'],
            'name'        => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default'  => ['boolean'],
            'sort_order'  => ['nullable', 'integer'],
        ];
    }
}
