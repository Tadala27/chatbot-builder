<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>{{ $subject ?? 'Notification' }}</title>
    <style>
        body { margin:0; padding:0; background:#f4f6f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        table { border-collapse: collapse; }
        a { color: #2a2c7e; text-decoration: none; }
        .wrapper { background:#f4f6f9; padding: 20px 0; }
        .main { background:#ffffff; margin: 0 auto; max-width: 600px; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .header { background:#ed1b2e; color:#ffffff; padding: 24px 32px; text-align: center; }
        .header img { width:32px; height:32px; border-radius:8px; vertical-align:middle; margin-right:12px; }
        .header .tenant-name { font-size:20px; font-weight:700; margin:0; display:inline-block; vertical-align:middle; }
        .header .date { font-size:14px; opacity:0.9; margin-top:6px; }
        .content { padding: 40px 32px; color:#333333; line-height:1.6; }
        .footer { background:#f8f9fc; padding:32px; border-top:1px solid #e2e8f0; font-size:13px; color:#666666; line-height:1.7; }
        .footer img { width:28px; height:28px; border-radius:6px; margin-right:12px; vertical-align:middle; }
        .btn {
            display: inline-block;
            background:#2a2c7e;
            color:#ffffff !important;
            padding:14px 32px;
            border-radius:8px;
            font-weight:600;
            font-size:16px;
            text-decoration:none;
            margin: 20px 0;
        }
        @media (prefers-color-scheme: dark) {
            .wrapper { background:#1a1a1a; }
            .main { background:#2d2d2d; box-shadow: 0 4px 20px rgba(0,0,0,0.5); }
            .content { color:#e0e0e0; }
            .footer { background:#262626; border-top-color:#444; color:#aaaaaa; }
        }
        @media only screen and (max-width: 480px) {
            .header td {
                display: block !important;
                width: 100% !important;
                text-align: center !important;
                padding-bottom: 12px !important;
            }
            .header td:last-child {
                padding-bottom: 0 !important;
                padding-top: 8px !important;
            }
            .header img {
                margin-right: 0 !important;
                margin-bottom: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
            <tr>
                <td align="center">
                    <table role="presentation" class="main" width="100%" cellspacing="0" cellpadding="0">

                        <!-- HEADER -->
                        <tr>
                            <td class="header" style="background:#ed1b2e; color:#ffffff; padding:20px 24px;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                    <tr>
                                        <td style="text-align:left;">
                                            <img src="{{ $faviconUrl ?? $tenantLogoUrl ?? asset('images/favicon.png') }}"
                                                width="36" height="36"
                                                style="border-radius:8px; vertical-align:middle; margin-right:12px; display:inline-block;">
                                            <span style="font-size:19px; font-weight:700; vertical-align:middle; color:#ffffff;">
                                                {{ $tenant?->name ?? config('app.name') }}
                                            </span>
                                        </td>
                                        <td style="text-align:right; font-size:14px; white-space:nowrap; color:#ffffff; opacity:0.92;">
                                            {{ now()->format('M j, Y \a\t g:i A') }}
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        <!-- CONTENT -->
                        <tr>
                            <td class="content">
                                {{ $slot }}

                                @if(isset($action_url) && $action_url)
                                    <div style="text-align:center; margin:40px 0;">
                                        <a href="{{ $action_url }}" class="btn">
                                            View Details
                                        </a>
                                    </div>
                                @endif
                            </td>
                        </tr>

                        <!-- FOOTER -->
                        <tr>
                            <td class="footer">
                                <div style="display:flex; align-items:center; margin-bottom:16px; flex-wrap:wrap;">
                                    <img src="{{ $faviconUrl ?? $tenantLogoUrl ?? asset('images/favicon.png') }}"
                                         alt="Logo" width="28" height="28">
                                    <div>
                                        <strong style="color:#444; font-size:15px;">
                                            {{ $tenant?->name ?? config('app.name') }}
                                        </strong><br>
                                        <span style="font-size:13px; color:#777;">Automated Notification System</span>
                                    </div>
                                </div>

                                <div style="font-size:12px; color:#888; line-height:1.6;">
                                    This is an automated message. Please do not reply to this email.<br>
                                    @if(isset($is_super_admin) && $is_super_admin)
                                        <strong style="color:#d97706;">• System Administrator Access •</strong>
                                    @endif
                                </div>

                                @if($tenant?->website ?? false)
                                    <div style="margin-top:16px; font-size:12px; color:#888;">
                                        <a href="{{ $tenant->website }}" style="color:#2a2c7e;">{{ $tenant->website }}</a>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>