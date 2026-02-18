<?php

namespace App\Notifications;

use App\Models\EmployeeScorecard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ScorecardSubmittedForReview extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public EmployeeScorecard $scorecard,
        public string $employeeName
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = url('/scorecards/' . $this->scorecard->id);

        return (new MailMessage)
            ->subject('Scorecard Submitted for Review - ' . $this->employeeName)
            ->greeting('Hello ' . $notifiable->firstname . ',')
            ->line($this->employeeName . ' has submitted their performance scorecard for your review.')
            ->line('**Financial Year:** ' . $this->scorecard->financialYear->name)
            ->line('**Performance Period:** ' . $this->scorecard->performancePeriod->name)
            ->line('**Overall Score:** ' . number_format($this->scorecard->overall_score, 2) . '%')
            ->action('Review Scorecard', $url)
            ->line('Please review and provide your feedback at your earliest convenience.');
    }

    public function toArray($notifiable): array
    {
        return [
            'scorecard_id' => $this->scorecard->id,
            'employee_name' => $this->employeeName,
            'financial_year' => $this->scorecard->financialYear->name,
            'message' => $this->employeeName . ' has submitted their scorecard for review',
            'url' => '/scorecards/' . $this->scorecard->id
        ];
    }
}
