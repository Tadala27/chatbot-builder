<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Changes Requested on Scorecard</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .email-container {
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .email-header {
            background: linear-gradient(135deg, #ff9800 0%, #ffc107 100%);
            color: #ffffff;
            padding: 30px;
            text-align: center;
        }
        .email-header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .email-body {
            padding: 30px;
        }
        .greeting {
            font-size: 18px;
            color: #ff9800;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .warning-box {
            background-color: #fff3cd;
            border-left: 4px solid #ff9800;
            padding: 20px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .comments-box {
            background-color: #f8f9fa;
            border-left: 4px solid #ff9800;
            padding: 20px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .comments-content {
            background-color: #ffffff;
            padding: 15px;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
            font-style: italic;
            color: #555;
        }
        .scorecard-box {
            background-color: #f8f9fa;
            padding: 20px;
            margin: 20px 0;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
        }
        .scorecard-box p {
            margin: 10px 0;
        }
        .info-label {
            font-weight: 600;
            color: #555;
            display: inline-block;
            width: 160px;
        }
        .info-value {
            color: #333;
            font-weight: 500;
        }
        .button {
            display: inline-block;
            background: linear-gradient(135deg, #ff9800 0%, #ffc107 100%);
            color: #ffffff;
            text-decoration: none;
            padding: 14px 30px;
            border-radius: 6px;
            margin: 20px 0;
            font-weight: 600;
            text-align: center;
        }
        .button:hover {
            opacity: 0.9;
        }
        .email-footer {
            background-color: #f8f9fa;
            padding: 20px 30px;
            text-align: center;
            font-size: 12px;
            color: #6c757d;
        }
        .divider {
            border-top: 1px solid #e9ecef;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="email-header">
            <h1>📝 Changes Requested</h1>
        </div>

        <!-- Body -->
        <div class="email-body">
            <p class="greeting">Hello {{ $employee->firstname }},</p>

            <p><strong>{{ $manager->full_name }}</strong> has reviewed your performance scorecard and requested some changes before approval.</p>

            <div class="warning-box">
                <strong>⚠️ Action Required</strong>
                <p style="margin: 8px 0 0 0;">Please review the feedback below and update your scorecard accordingly. Once updated, resubmit for review.</p>
            </div>

            <div class="comments-box">
                <p style="margin-top: 0; font-weight: 600; color: #ff9800;">💬 Manager's Feedback</p>
                <div class="comments-content">
                    {{ $comments }}
                </div>
                <p style="margin-bottom: 0; margin-top: 15px; font-size: 12px; color: #666;">
                    <strong>Reviewed by:</strong> {{ $manager->full_name }}<br>
                    <strong>Date:</strong> {{ now()->format('d M Y, H:i') }}
                </p>
            </div>

            <div class="scorecard-box">
                <p style="margin-top: 0; font-weight: 600; color: #ff9800;">📋 Scorecard Information</p>
                <p>
                    <span class="info-label">Financial Year:</span>
                    <span class="info-value">{{ $financial_year->name }}</span>
                </p>
                <p>
                    <span class="info-label">Performance Period:</span>
                    <span class="info-value">{{ $performance_period->name }}</span>
                </p>
                <p>
                    <span class="info-label">Current Status:</span>
                    <span class="info-value" style="color: #ff9800; font-weight: 600;">Changes Requested</span>
                </p>
            </div>

            <div style="text-align: center;">
                <a href="{{ $action_url }}" class="button">Update Scorecard Now</a>
            </div>

            <div class="divider"></div>

            <h3 style="color: #ff9800; margin-top: 30px;">📌 What to Do Next</h3>
            <ul style="margin: 15px 0; padding-left: 25px;">
                <li>Review the manager's feedback carefully</li>
                <li>Make the necessary changes to your scorecard</li>
                <li>Ensure all objectives are clear and measurable</li>
                <li>Verify weight distributions total 100%</li>
                <li>Resubmit the scorecard for approval</li>
            </ul>

            <div class="divider"></div>

            <p><strong>Need Clarification?</strong></p>
            <p>If you need clarification on the requested changes, please reach out to {{ $manager->full_name }} directly or schedule a meeting to discuss.</p>

            <p style="margin-top: 30px;">Best regards,<br>
            <strong>{{ $companyName }} Performance Management Team</strong></p>
        </div>

        <!-- Footer -->
        <div class="email-footer">
            <p>© {{ date('Y') }} {{ $companyName }}. All rights reserved.</p>
            <p>This is an automated notification. Please do not reply to this email.</p>
            <p style="margin-top: 10px;">
                <a href="{{ $action_url }}" style="color: #ff9800; text-decoration: none;">Update Scorecard</a>
            </p>
        </div>
    </div>
</body>
</html>