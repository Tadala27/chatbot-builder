<x-email.layouts.base
    :subject="$emailSubject"
    :tenant="$tenant"
    :faviconUrl="$faviconUrl"
    :tenantLogoUrl="$tenantLogoUrl"
>
    <h1 style="color:#2a2c7e; margin:0 0 16px; font-size:20px;">
        Hello {{ $user->firstname }},
    </h1>
    <p style="font-size:15px; margin:0 0 24px;">
        We received a request to sign in to your account. Use the verification code below:
    </p>

    <div style="text-align:center; margin:32px 0; padding:20px; background:#f8f9fc; border-radius:8px;">
        <div style="font-size:48px; letter-spacing:12px; font-family:'Courier New', monospace; color:#2a2c7e; font-weight:bold;">
            {{ $otp ?? $user->otp_code }}
        </div>
    </div>

    <p style="margin:24px 0;">
        <strong>Security Note:</strong> This code expires at <strong>{{ $expires_at }}</strong>
    </p>

    <div style="text-align:center; margin:40px 0;">
        <a href="{{ $login_url }}" class="btn">
            Go to Login Page
        </a>
    </div>

    <h3 style="color:#2a2c7e; margin:32px 0 12px;">Didn't Request This?</h3>
    <p>If you didn't attempt to log in, you can safely ignore this email.</p>

    <p style="margin-top:32px; color:#666;">
        Thanks
    </p>
</x-email.layouts.base>