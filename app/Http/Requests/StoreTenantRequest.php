<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user has permission to create tenants
        return auth()->user()->hasPermissionInTenant(
            request()->tenant_id ?? 0,
            'manage_tenants'
        ) || auth()->user()->hasRoleInTenant(0, 'super_admin');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', 'unique:tenants,code'],
            'description' => ['nullable', 'string'],
            'parent_tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'is_active' => ['boolean'],
            'settings' => ['nullable', 'array'],
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
            'name.required' => 'Tenant name is required',
            'code.required' => 'Tenant code is required',
            'code.unique' => 'This tenant code is already in use',
            'parent_tenant_id.exists' => 'The selected parent tenant does not exist',
        ];
    }
}
