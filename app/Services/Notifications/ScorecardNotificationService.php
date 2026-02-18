<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Models\EmployeeScorecard;

use function Symfony\Component\String\u;

class ScorecardNotificationService extends BaseNotificationService
{
    /**
     * Notify manager when employee submits scorecard
     */
    public function notifyManagerOfSubmission(
        EmployeeScorecard $scorecard,
        User $employee,
        User $manager
    ): void {
        $this->sendEmail(
            user: $manager,
            subject: "Scorecard Submitted for Review - {$employee->full_name}",
            template: 'emails.scorecards.submitted-to-manager',
            data: [
                'manager' => $manager,
                'employee' => $employee,
                'scorecard' => $scorecard,
                'financial_year' => $scorecard->financialYear,
                'performance_period' => $scorecard->performancePeriod,
                'position' => $scorecard->position,
                'action_url' => url('/scorecards/', $scorecard->id),
                'overall_score' => number_format($scorecard->overall_score, 2),
            ]
        );
    }

    /**
     * Notify employee when manager approves scorecard
     */
    public function notifyEmployeeOfApproval(
        EmployeeScorecard $scorecard,
        User $employee,
        User $manager
    ): void {
        $this->sendEmail(
            user: $employee,
            subject: "Your Scorecard Has Been Approved",
            template: 'emails.scorecards.approved',
            data: [
                'employee' => $employee,
                'manager' => $manager,
                'scorecard' => $scorecard,
                'financial_year' => $scorecard->financialYear,
                'performance_period' => $scorecard->performancePeriod,
                'action_url' => url('/scorecards/', $scorecard->id),
                'overall_score' => number_format($scorecard->overall_score, 2),
            ]
        );
    }

    /**
     * Notify employee when manager requests changes
     */
    public function notifyEmployeeOfChangesRequested(
        EmployeeScorecard $scorecard,
        User $employee,
        User $manager,
        string $comments
    ): void {
        $this->sendEmail(
            user: $employee,
            subject: "Changes Requested on Your Scorecard",
            template: 'emails.scorecards.changes-requested',
            data: [
                'employee' => $employee,
                'manager' => $manager,
                'scorecard' => $scorecard,
                'comments' => $comments,
                'financial_year' => $scorecard->financialYear,
                'performance_period' => $scorecard->performancePeriod,
                'action_url' => route('scorecards.edit', $scorecard->id),
            ]
        );
    }

    /**
     * Send reminder to complete scorecard
     */
    public function sendCompletionReminder(
        User $employee,
        EmployeeScorecard $scorecard,
        int $daysRemaining
    ): void {
        $this->sendEmail(
            user: $employee,
            subject: "Reminder: Complete Your Performance Scorecard ({$daysRemaining} days left)",
            template: 'emails.scorecards.completion-reminder',
            data: [
                'employee' => $employee,
                'scorecard' => $scorecard,
                'days_remaining' => $daysRemaining,
                'performance_period' => $scorecard->performancePeriod,
                'action_url' => route('scorecards.edit', $scorecard->id),
            ]
        );
    }

    /**
     * Notify manager of pending reviews
     */
    public function sendManagerPendingReviewsReminder(
        User $manager,
        array $pendingScorecards
    ): void {
        $this->sendEmail(
            user: $manager,
            subject: "Pending Scorecard Reviews ({count($pendingScorecards)} items)",
            template: 'emails.scorecards.manager-pending-reviews',
            data: [
                'manager' => $manager,
                'scorecards' => $pendingScorecards,
                'count' => count($pendingScorecards),
                'action_url' => route('scorecards.index', ['status' => 'submitted']),
            ]
        );
    }
}
