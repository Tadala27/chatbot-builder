<x-email.layouts.base
    :subject="'Password Updated – ' . ($tenant->name ?? config('app.name'))"
    :tenant="$tenant"
    :faviconUrl="$faviconUrl"
    :tenantLogoUrl="$tenantLogoUrl"
>

    <h1 style="color:#ed1b2e; margin:0 0 16px; font-size:22px;">
        Your Password Was Updated
    </h1>

    <p style="margin:0 0 20px; font-size:16px;">
        Hi {{ $user->firstname }},
    </p>

    <div style="background:#fff3cd; border-left:4px solid #ed1b2e; padding:20px; margin:28px 0; border-radius:0 8px 8px 0;">
        <p style="margin:0; font-size:15px; color:#856404;">
            <strong>Security Alert</strong><br>
            If you <strong>did not</strong> make this change, please update your password immediately and contact support.
        </p>
    </div>

    <p style="margin:20px 0; font-size:15px;">
        If this was you — no action needed. You’re all set!
    </p>

    <p style="margin:32px 0 0; color:#666;">
        Thanks
    </p>

</x-email.layouts.base>