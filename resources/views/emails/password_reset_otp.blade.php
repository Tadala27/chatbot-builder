<x-email.layouts.base
    :subject="'Your Password Reset Code – ' . ($tenant->name ?? config('app.name'))"
    :tenant="$tenant"
    :faviconUrl="$faviconUrl"
    :tenantLogoUrl="$tenantLogoUrl"
>
    <h1 style="color:#ed1b2e; margin:0 0 16px; font-size:22px;">
        Password Reset Verification Code
    </h1>

    <p style="margin:0 0 20px; font-size:16px;">
        Hello {{ $user->firstname }},
    </p>

    <p style="margin:0 0 28px;">
        Use the code below to reset your password:
    </p>

    <div style="text-align:center; margin:36px 0; padding:24px; background:#f8f9fc; border-radius:12px;">
        <div style="font-size:48px; letter-spacing:12px; font-family:'Courier New', monospace; color:#ed1b2e; font-weight:bold;">
            {{ $otp }}
        </div>
    </div>

    <ul style="background:#f0f0f0; padding:16px 20px; border-radius:8px; margin:28px 0; font-size:14px; color:#555;">
        <li>This code expires at <strong>{{ $expires_at }}</strong></li>
    </ul>

    <p style="margin:32px 0 0; color:#666; font-size:15px;">
        Didn’t request this? Ignore this email or contact your administrator<br>
        Thanks
    </p>

</x-email.layouts.base>