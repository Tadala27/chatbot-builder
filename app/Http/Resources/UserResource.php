<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'position' => $this->position,
            'department' => $this->department,
            'is_active' => $this->is_active,
            'hire_date' => $this->hire_date?->toDateString(),
            
            // Relationships
            'tenant_roles' => UserTenantRoleResource::collection($this->whenLoaded('tenantRoles')),
            'primary_tenant' => $this->when($this->relationLoaded('tenantRoles'), function() {
                return new TenantResource($this->primaryTenant());
            }),
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
