<x-email.layouts.base
    :subject="'Welcome to ' . ($tenant->name ?? config('app.name'))"
    :tenant="$tenant"
    :faviconUrl="$faviconUrl"
    :tenantLogoUrl="$tenantLogoUrl"
>

    <h1 style="color:#ed1b2e; margin:0 0 16px; font-size:22px;">
        Welcome {{ $user->firstname }}!
    </h1>

    <p style="margin:0 0 20px; font-size:16px; color:#333;">
        @if($is_full_access)
            Your account has been successfully created and is ready to use at <strong>{{ $tenant->name }}</strong>.<br><br>
            You can now log in using the credentials below:
        @else
            Your account has been created successfully!<br><br>
            However, it requires <strong>tenant assignment</strong> before you can log in and apply for jobs.
        @endif
    </p>

    <table style="width:100%; background:#f8f9fc; border-radius:8px; padding:20px; margin:24px 0; font-size:15px;">
        <tr><td style="padding:8px 0;"><strong>Full Name:</strong></td><td>{{ $user->firstname }} {{ $user->lastname }}</td></tr>
        <tr><td style="padding:8px 0;"><strong>Email:</strong></td><td>{{ $user->email }}</td></tr>
        @if($is_full_access)
            <tr><td style="padding:8px 0;"><strong>Temporary Password:</strong></td>
                <td><code style="background:#eee; padding:4px 8px; border-radius:4px; font-size:15px;">{{ $plain_password }}</code></td>
            </tr>
        @endif
    </table>

    @if($is_full_access)
        <div style="text-align:center; margin:32px 0;">
            <a href="{{ $login_url ?? url('/login') }}" class="btn" style="background:#ed1b2e;">
                Log In Now
            </a>
        </div>

        <div style="background:#fff3cd; border-left:4px solid #ed1b2e; padding:16px; margin:24px 0; border-radius:0 8px 8px 0;">
            <p style="margin:0; font-size:14px; color:#856404;">
                <strong>Important Security Notice</strong><br>
                For your security, please <strong>change your password immediately</strong> after logging in.
            </p>
        </div>
    @else
        <div style="background:#e3f2fd; border-left:4px solid #1976d2; padding:16px; margin:24px 0; border-radius:0 8px 8px 0;">
            <p style="margin:0; font-size:15px; color:#0d47a1;">
                <strong>Next Steps</strong><br>
                A system administrator will assign you to a company shortly. You’ll receive another email with login instructions.
            </p>
        </div>
    @endif

    <p style="margin:32px 0 0; color:#666; font-size:15px;">
        Thank you
    </p>

</x-email.layouts.base>