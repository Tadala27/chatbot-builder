<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Base notification service with core functionality
 * All specific notification services extend this
 */
abstract class BaseNotificationService
{
    /**
     * Send email notification
     */
    protected function sendEmail(
        User $user,
        string $subject,
        string $template,
        array $data = []
    ): void {
        try {
            Mail::to($user->email)->send(
                new NotificationMail(
                    user: $user,
                    subject: $subject,
                    template: $template,
                    data: $data
                )
            );

            Log::info("Email sent successfully", [
                'recipient' => $user->email,
                'subject' => $subject,
                'template' => $template,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to send email", [
                'recipient' => $user->email,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send email to multiple users
     */
    protected function sendBulkEmail(
        array $users,
        string $subject,
        string $template,
        array $data = []
    ): void {
        foreach ($users as $user) {
            $this->sendEmail($user, $subject, $template, $data);
        }
    }
}
