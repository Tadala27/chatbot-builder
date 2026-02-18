<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;
    public string $emailSubject;
    public string $template;
    public array $emailData;
    public ?string $companyLogoUrl = null;
    public ?string $faviconUrl = null;

    public function __construct(
        User $user,
        string $subject,
        string $template,
        array $data = []
    ) {
        $this->user = $user;
        $this->emailSubject = $subject;
        $this->template = $template;
        $this->emailData = $data;

        // Load company assets
        $this->loadCompanyAssets($user);
    }

    private function loadCompanyAssets(User $user): void
    {
        $tenant = $user->tenant;

        // Set favicon
        if ($tenant && $tenant->favicon) {
            $faviconPath = "tenants/{$tenant->code}/{$tenant->favicon}";
            if (Storage::disk('public')->exists($faviconPath)) {
                $this->faviconUrl = Storage::disk('public')->url($faviconPath);
            }
        }

        // Fallback favicon
        if (!$this->faviconUrl) {
            $this->faviconUrl = asset('images/favicon.png');
        }

        // Set logo
        if ($tenant && $tenant->email_logo) {
            $logoPath = "tenants/{$tenant->code}/{$tenant->email_logo}";
            if (Storage::disk('public')->exists($logoPath)) {
                $this->companyLogoUrl = Storage::disk('public')->url($logoPath);
            }
        } elseif ($tenant && $tenant->logo) {
            $logoPath = "tenants/{$tenant->code}/{$tenant->logo}";
            if (Storage::disk('public')->exists($logoPath)) {
                $this->companyLogoUrl = Storage::disk('public')->url($logoPath);
            }
        }

        // Fallback logo
        if (!$this->companyLogoUrl) {
            $this->companyLogoUrl = asset('images/logo.png');
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject
        );
    }

    public function content(): Content
    {
        return new Content(
            view: $this->template,
            with: array_merge($this->emailData, [
                'user' => $this->user,
                'tenant' => $this->user->tenant,
                'companyLogoUrl' => $this->companyLogoUrl,
                'faviconUrl' => $this->faviconUrl,
                'companyName' => $this->user->tenant->name ?? config('app.name'),
            ])
        );
    }
}
