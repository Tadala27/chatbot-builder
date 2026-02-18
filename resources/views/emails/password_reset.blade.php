<x-email.layouts.base
    :subject="'Reset Your Password – ' . ($tenant->name ?? config('app.name'))"
    :tenant="$tenant"
    :faviconUrl="$faviconUrl"
    :tenantLogoUrl="$tenantLogoUrl"
>

    <h1 style="color:#ed1b2e; margin:0 0 16px; font-size:22px;">
        Reset Your Password
    </h1>

    <p style="margin:0 0 20px; font-size:16px;">
        Hello {{ $user->firstname }},
    </p>

    <p style="margin:0 0 28px; font-size:15px;">
        We received a request to reset your password. Click the button below to create a new one:
    </p>

    <div style="text-align:center; margin:36px 0;">
        <a href="{{ $reset_url }}"
           class="btn"
           style="background:#ed1b2e; color:white; padding:16px 36px; font-size:17px; font-weight:bold; border-radius:8px; text-decoration:none;">
            Reset Password Now
        </a>
    </div>

    <div style="background:#f0f0f0; padding:18px 20px; border-radius:8px; margin:32px 0; font-size:14px; color:#555;">
        <ul style="margin:12px 0; padding-left:20px;">
            <li>This link expires in <strong>60 minutes</strong> for security</li>
            <li>Didn't request this? You can safely ignore this email</li>
        </ul>
    </div>

    <div style="background:#fff3cd; border-left:4px solid #ed1b2e; padding:16px; margin:32px 0; border-radius:0 8px 8px 0;">
        <p style="margin:0; font-size:14px; color:#856404;">
            <strong>Security Tip:</strong> Never share this link with anyone. We will never ask you for your password via email.
        </p>
    </div>

    <p style="margin:36px 0 0; color:#666; font-size:15px;">
        Need help? Contact us support immediately.<br><br>
        Stay safe
    </p>

</x-email.layouts.base>