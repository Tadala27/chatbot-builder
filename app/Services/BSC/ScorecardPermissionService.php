<?php

namespace App\Services\BSC;

use App\Models\User;
use App\Models\EmployeeScorecard;

class ScorecardPermissionService
{
    /**
     * Check if user can manage the scorecard (view/edit/review)
     */
    public function canManageScorecard(User $user, EmployeeScorecard $scorecard): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->tenant_id !== $scorecard->tenant_id) {
            return false;
        }

        if ($this->isScorecardOwner($user, $scorecard)) {
            return true;
        }

        if ($this->isSupervisor($user, $scorecard)) {
            return true;
        }

        return false;
    }

    /**
     * Check if user is the scorecard owner (employee)
     */
    public function isScorecardOwner(User $user, EmployeeScorecard $scorecard): bool
    {
        $positionHolder = $scorecard->position?->currentHolder;
        return $positionHolder && $positionHolder->user_id === $user->id;
    }

    /**
     * Check if user is the supervisor/manager of the scorecard owner
     */
    public function isSupervisor(User $user, EmployeeScorecard $scorecard): bool
    {
        $managerPosition = $user->currentPosition();
        if (!$managerPosition) {
            return false;
        }

        $subordinatePosition = $scorecard->position;
        return $subordinatePosition &&
            $subordinatePosition->manager_position_id === $managerPosition->id;
    }

    /**
     * Check if user can edit the scorecard
     * NEW LOGIC:
     * - Employee can edit in draft or pending_employee_acceptance
     * - Manager can ALWAYS edit when in manager_review (no status transition on view)
     */
    public function canEditScorecard(User $user, EmployeeScorecard $scorecard): bool
    {
        if (!$this->canManageScorecard($user, $scorecard)) {
            return false;
        }

        // Employee can edit in draft or when reviewing manager changes
        if ($this->isScorecardOwner($user, $scorecard)) {
            return in_array($scorecard->status, ['draft', 'pending_employee_acceptance']);
        }

        // Manager can edit when reviewing (submitted or already in manager_review)
        if ($this->isSupervisor($user, $scorecard)) {
            return in_array($scorecard->status, ['submitted', 'manager_review']);
        }

        return false;
    }

    /**
     * Check if user can review the scorecard (supervisor only)
     * Manager can review when employee has submitted
     */
    public function canReviewScorecard(User $user, EmployeeScorecard $scorecard): bool
    {
        if (!$this->isSupervisor($user, $scorecard)) {
            return false;
        }

        return in_array($scorecard->status, ['submitted', 'manager_review']);
    }

    /**
     * Check if user can sign the scorecard as employee
     * NEW LOGIC:
     * - Can sign in draft (initial submission)
     * - Can sign in pending_employee_acceptance (accepting manager changes)
     */
    public function canSignAsEmployee(User $user, EmployeeScorecard $scorecard): bool
    {
        if (!$this->isScorecardOwner($user, $scorecard)) {
            return false;
        }

        // Employee can sign when:
        // 1. Scorecard is in draft (initial submission), OR
        // 2. Manager has made changes and employee needs to accept/reject them
        return in_array($scorecard->status, ['draft', 'pending_employee_acceptance']);
    }

    /**
     * Check if user can sign/approve the scorecard as manager
     * NEW LOGIC:
     * - Manager signs when in manager_review (after making changes or not)
     * - This is now called "sign" not "approve"
     */
    public function canSignAsManager(User $user, EmployeeScorecard $scorecard): bool
    {
        if (!$this->isSupervisor($user, $scorecard)) {
            return false;
        }

        // Manager can sign when they've reviewed (with or without changes)
        return $scorecard->status === 'manager_review';
    }

    /**
     * Check if manager can view changes comparison
     * Only applicable when there are pending manager changes
     */
    public function canViewManagerChanges(User $user, EmployeeScorecard $scorecard): bool
    {
        if (!$this->isScorecardOwner($user, $scorecard)) {
            return false;
        }

        return $scorecard->status === 'pending_employee_acceptance'
            && $scorecard->has_manager_changes;
    }

    /**
     * Check if employee can accept manager changes
     */
    public function canAcceptManagerChanges(User $user, EmployeeScorecard $scorecard): bool
    {
        if (!$this->isScorecardOwner($user, $scorecard)) {
            return false;
        }

        return $scorecard->status === 'pending_employee_acceptance'
            && $scorecard->has_manager_changes;
    }

    /**
     * Check if employee can reject manager changes
     */
    public function canRejectManagerChanges(User $user, EmployeeScorecard $scorecard): bool
    {
        if (!$this->isScorecardOwner($user, $scorecard)) {
            return false;
        }

        return $scorecard->status === 'pending_employee_acceptance'
            && $scorecard->has_manager_changes;
    }

    /**
     * DEPRECATED: Use canSignAsEmployee instead
     */
    public function canSignContract(User $user, EmployeeScorecard $scorecard): bool
    {
        return $this->canSignAsEmployee($user, $scorecard);
    }

    /**
     * Get all permissions for a user and scorecard
     */
    public function getScorecardPermissions(User $user, EmployeeScorecard $scorecard): array
    {
        return [
            'can_manage' => $this->canManageScorecard($user, $scorecard),
            'can_edit' => $this->canEditScorecard($user, $scorecard),
            'can_review' => $this->canReviewScorecard($user, $scorecard),
            'can_sign_as_employee' => $this->canSignAsEmployee($user, $scorecard),
            'can_sign_as_manager' => $this->canSignAsManager($user, $scorecard),
            'can_view_manager_changes' => $this->canViewManagerChanges($user, $scorecard),
            'can_accept_manager_changes' => $this->canAcceptManagerChanges($user, $scorecard),
            'can_reject_manager_changes' => $this->canRejectManagerChanges($user, $scorecard),
            'is_owner' => $this->isScorecardOwner($user, $scorecard),
            'is_supervisor' => $this->isSupervisor($user, $scorecard),
            'has_pending_changes' => $scorecard->has_manager_changes ?? false,
        ];
    }
}
