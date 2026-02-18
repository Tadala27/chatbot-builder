<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreScorecardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->user()->hasPermissionInTenant(
            $this->tenant_id,
            'manage_scorecards'
        ) || auth()->user()->hasPermissionInTenant(
            $this->tenant_id,
            'manage_tenant_users'
        );
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'performance_period_id' => ['required', 'integer', 'exists:performance_periods,id'],
            'bsc_template_id' => ['required', 'integer', 'exists:bsc_templates,id'],
            'matrix_template_id' => ['required', 'integer', 'exists:performance_matrix_templates,id'],
            'status' => ['nullable', 'string', 'in:draft,active,self_review,submitted,manager_review,approved,completed,archived'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'User is required',
            'user_id.exists' => 'The selected user does not exist',
            'tenant_id.required' => 'Tenant is required',
            'tenant_id.exists' => 'The selected tenant does not exist',
            'performance_period_id.required' => 'Performance period is required',
            'performance_period_id.exists' => 'The selected performance period does not exist',
            'bsc_template_id.required' => 'BSC template is required',
            'bsc_template_id.exists' => 'The selected BSC template does not exist',
            'matrix_template_id.required' => 'Performance matrix template is required',
            'matrix_template_id.exists' => 'The selected matrix template does not exist',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Check if user already has a scorecard for this period
            $exists = \App\Models\EmployeeScorecard::where('user_id', $this->user_id)
                ->where('performance_period_id', $this->performance_period_id)
                ->exists();

            if ($exists) {
                $validator->errors()->add('user_id', 'User already has a scorecard for this performance period');
            }
        });
    }
}
