<x-email.layouts.base
    :subject="'Password Reset Successful – ' . ($tenant->name ?? config('app.name'))"
    :tenant="$tenant"
    :faviconUrl="$faviconUrl"
    :tenantLogoUrl="$tenantLogoUrl"
>

    <h1 style="color:#ed1b2e; margin:0 0 16px; font-size:22px;">
        Password Reset Successful
    </h1>

    <p style="margin:0 0 20px; font-size:16px;">
        Hello {{ $user->firstname }},
    </p>

    <p style="margin:0 0 28px;">
        Your password has been successfully changed. You can now log in with your new password.
    </p>

    <div style="text-align:center; margin:32px 0;">
        <a href="{{ $login_url ?? url('/login') }}" class="btn" style="background:#ed1b2e;">
            Log In Now
        </a>
    </div>

    <div style="background:#fff3cd; border-left:4px solid #ed1b2e; padding:20px; margin:32px 0; border-radius:0 8px 8px 0;">
        <p style="margin:0; font-size:15px; color:#856404;">
            <strong>Security Alert</strong><br>
            If you didn’t reset your password, contact support immediately
        </p>
    </div>

    <h3 style="color:#333; margin:32px 0 12px;">Security Tips</h3>
    <ul style="margin:16px 0; padding-left:20px; color:#555; font-size:14px;">
        <li>Use a strong, unique password</li>
        <li>Enable two-factor authentication</li>
        <li>Never share your credentials</li>
    </ul>

    <p style="margin:32px 0 0; color:#666;">
        Stay safe
    </p>

</x-email.layouts.base>