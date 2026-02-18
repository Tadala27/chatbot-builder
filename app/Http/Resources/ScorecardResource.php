<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScorecardResource extends JsonResource
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
            'status' => $this->status,
            'overall_score' => $this->overall_score,
            'overall_rating' => $this->overall_rating,
            'employee_comments' => $this->employee_comments,
            'manager_comments' => $this->manager_comments,
            
            // State flags
            'is_editable' => $this->isEditable(),
            'can_be_submitted' => $this->canBeSubmitted(),
            
            // Relationships
            'user' => new UserResource($this->whenLoaded('user')),
            'tenant' => new TenantResource($this->whenLoaded('tenant')),
            'performance_period' => new PerformancePeriodResource($this->whenLoaded('performancePeriod')),
            'bsc_template' => new BscTemplateResource($this->whenLoaded('bscTemplate')),
            'matrix_template' => new PerformanceMatrixResource($this->whenLoaded('matrixTemplate')),
            'perspectives' => ScorecardPerspectiveResource::collection($this->whenLoaded('perspectives')),
            'submissions' => AppraisalSubmissionResource::collection($this->whenLoaded('submissions')),
            
            // Timestamps
            'submitted_at' => $this->submitted_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
