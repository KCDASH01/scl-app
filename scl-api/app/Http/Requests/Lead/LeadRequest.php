<?php

namespace App\Http\Requests\Lead;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Lead;
use App\Support\PhoneNormalizer;

class LeadRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $leadId   = $this->route('lead')?->id;

        return [
            'first_name'        => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:191'],
            'last_name'         => ['nullable', 'string', 'max:191'],
            'email'             => ['nullable', 'email', 'max:191'],
            'phone'             => [
                'nullable',
                'string',
                'max:30',
                function (string $attribute, mixed $value, \Closure $fail) use ($leadId): void {
                    $normalized = PhoneNormalizer::normalize(is_string($value) ? $value : null);

                    if (! $normalized) {
                        return;
                    }

                    $duplicate = Lead::with('assignedTo:id,name')
                        ->where('phone_normalized', $normalized)
                        ->when($leadId, fn ($query) => $query->where('id', '!=', $leadId))
                        ->when(
                            $this->route('lead')?->branch_id ?? $this->input('branch_id') ?? $this->header('X-Branch-Id'),
                            fn ($query, $branchId) => $query->where('branch_id', $branchId)
                        )
                        ->first();

                    if (! $duplicate) {
                        return;
                    }

                    $assigneeName = $duplicate->assignedTo?->name ?? 'Unassigned';
                    $fail("This lead is already assigned to {$assigneeName}");
                },
            ],
            'status'            => ['nullable', 'in:new,contacted,ringing,important,converted,lost,followup,meeting,visited,not_interested'],
            'schedule_at'       => [
                Rule::requiredIf(function () use ($isUpdate) {
                    if (! in_array($this->input('status'), ['followup', 'meeting'], true)) {
                        return false;
                    }

                    if (! $isUpdate) {
                        return true;
                    }

                    $lead = $this->route('lead');

                    return $lead && $lead->status !== $this->input('status');
                }),
                'nullable',
                'date',
            ],
            'remark'            => ['nullable', 'string', 'max:2000'],
            'source'            => ['nullable', 'in:manual,sso_import,web_form,csv,api,other,meta_ad,email_campaign,google_ads'],
            'course_id'         => [$isUpdate ? 'sometimes' : 'required', 'integer', 'exists:courses,id'],
            'city'              => ['nullable', 'string', 'max:191'],
            'state'             => ['nullable', 'string', 'max:191'],
            'country'           => ['nullable', 'string', 'max:191'],
            'pin'               => ['nullable', 'string', 'max:16'],
            'pipeline_id'       => ['nullable', 'integer', 'exists:pipelines,id'],
            'stage_id'          => ['nullable', 'integer', 'exists:stages,id'],
            'assigned_to'       => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->where('role', 'employee')
                          ->where('is_active', true);
                }),
            ],
            'custom_fields'     => ['nullable', 'array'],
            'external_lead_id'  => ['nullable', 'string', 'max:191'],
            'lost_reason'       => ['nullable', 'string', 'max:191'],
            'branch_id'         => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }
}
