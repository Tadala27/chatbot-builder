<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scorecard Completion Reminder</title>
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
            background: linear-gradient(135deg, #f44336 0%, #ef5350 100%);
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
            color: #f44336;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .urgent-box {
            background-color: #ffebee;
            border-left: 4px solid #f44336;
            padding: 20px;
            margin: 20px 0;
            border-radius: 4px;
            text-align: center;
        }
        .countdown {
            font-size: 48px;
            font-weight: bold;
            color: #f44336;
            margin: 10px 0;
        }
        .button {
            display: inline-block;
            background: linear-gradient(135deg, #f44336 0%, #ef5350 100%);
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
            <h1>⏰ Scorecard Completion Reminder</h1>
        </div>

        <!-- Body -->
        <div class="email-body">
            <p class="greeting">Hello {{ $employee->firstname }},</p>

            <p>This is a friendly reminder that your performance scorecard for <strong>{{ $performance_period->name }}</strong> needs to be completed.</p>

            <div class="urgent-box">
                <div style="font-size: 18px; color: #f44336; margin-bottom: 10px;">⚠️ Time Remaining</div>
                <div class="countdown">{{ $days_remaining }}</div>
                <div style="color: #666; font-size: 14px;">day{{ $days_remaining != 1 ? 's' : '' }} left</div>
            </div>

            <p>Don't miss the deadline! Complete and submit your scorecard to ensure timely performance reviews.</p>

            <div style="text-align: center;">
                <a href="{{ $action_url }}" class="button">Complete Scorecard Now</a>
            </div>

            <div class="divider"></div>

            <h3 style="color: #f44336;">Why It's Important</h3>
            <ul style="margin: 15px 0; padding-left: 25px;">
                <li>Ensures your performance objectives are documented</li>
                <li>Enables proper tracking and evaluation throughout the period</li>
                <li>Required for performance-based assessments and bonuses</li>
                <li>Facilitates meaningful conversations with your manager</li>
            </ul>

            <p style="margin-top: 30px;">Best regards,<br>
            <strong>{{ config('app.name') }} Performance Management Team</strong></p>
        </div>

        <!-- Footer -->
        <div class="email-footer">
            <p>© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
            <p>This is an automated reminder. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>