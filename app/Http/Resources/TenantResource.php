<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
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
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'settings' => $this->settings,
            'parent_tenant_id' => $this->parent_tenant_id,
            'is_parent' => $this->isParent(),
            'is_child' => $this->isChild(),
            
            // Relationships
            'parent' => new TenantResource($this->whenLoaded('parent')),
            'children' => TenantResource::collection($this->whenLoaded('children')),
            'configuration' => new TenantConfigurationResource($this->whenLoaded('activeConfiguration')),
            'users_count' => $this->when($this->users_count !== null, $this->users_count),
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
