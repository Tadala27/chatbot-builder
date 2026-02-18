<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scorecard Approved</title>
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
            background: linear-gradient(135deg, #4caf50 0%, #81c784 100%);
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
            color: #4caf50;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .success-box {
            background-color: #e8f5e9;
            border-left: 4px solid #4caf50;
            padding: 20px;
            margin: 20px 0;
            border-radius: 4px;
            text-align: center;
        }
        .success-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
        .scorecard-box {
            background-color: #f8f9fa;
            border-left: 4px solid #4caf50;
            padding: 20px;
            margin: 20px 0;
            border-radius: 4px;
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
        .score-highlight {
            background-color: #e8f5e9;
            padding: 15px;
            border-radius: 6px;
            text-align: center;
            margin: 20px 0;
            border: 2px solid #4caf50;
        }
        .score-highlight .score {
            font-size: 32px;
            font-weight: bold;
            color: #4caf50;
            display: block;
        }
        .score-highlight .score-label {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }
        .button {
            display: inline-block;
            background: linear-gradient(135deg, #4caf50 0%, #81c784 100%);
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
        .info-box {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
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
            <h1>✅ Scorecard Approved!</h1>
        </div>

        <!-- Body -->
        <div class="email-body">
            <p class="greeting">Hello {{ $employee->firstname }},</p>

            <div class="success-box">
                <div class="success-icon">🎉</div>
                <h2 style="color: #4caf50; margin: 10px 0;">Congratulations!</h2>
                <p style="margin: 10px 0;">Your performance scorecard has been approved by <strong>{{ $manager->full_name }}</strong>.</p>
            </div>

            <p>Your scorecard is now officially active for the performance period. All objectives and targets are confirmed.</p>

            <div class="scorecard-box">
                <p style="margin-top: 0; font-weight: 600; color: #4caf50;">📋 Approved Scorecard Details</p>
                <p>
                    <span class="info-label">Financial Year:</span>
                    <span class="info-value">{{ $financial_year->name }}</span>
                </p>
                <p>
                    <span class="info-label">Performance Period:</span>
                    <span class="info-value">{{ $performance_period->name }}</span>
                </p>
                <p>
                    <span class="info-label">Approved By:</span>
                    <span class="info-value">{{ $manager->full_name }}</span>
                </p>
                <p>
                    <span class="info-label">Approval Date:</span>
                    <span class="info-value">{{ $scorecard->approved_at->format('d M Y, H:i') }}</span>
                </p>
                <p>
                    <span class="info-label">Status:</span>
                    <span class="info-value" style="color: #4caf50; font-weight: 600;">✓ Approved</span>
                </p>
            </div>

            <div class="score-highlight">
                <span class="score">{{ $overall_score }}%</span>
                <div class="score-label">Overall Target Score</div>
            </div>

            <div style="text-align: center;">
                <a href="{{ $action_url }}" class="button">View Your Scorecard</a>
            </div>

            <div class="divider"></div>

            <h3 style="color: #4caf50; margin-top: 30px;">📌 Next Steps</h3>
            <ul style="margin: 15px 0; padding-left: 25px;">
                <li><strong>Track Your Progress:</strong> Regularly update your objectives throughout the performance period</li>
                <li><strong>Document Evidence:</strong> Keep proof of achievements for objectives that require documentation</li>
                <li><strong>Mid-Year Review:</strong> Schedule check-ins with your manager to discuss progress</li>
                <li><strong>Self-Assessment:</strong> You'll be prompted to complete a self-review at the end of the period</li>
            </ul>

            <div class="info-box">
                <strong>💡 Pro Tip:</strong>
                <p style="margin: 8px 0 0 0;">Set regular reminders to update your objective progress. Consistent tracking leads to better performance evaluations!</p>
            </div>

            <div class="divider"></div>

            <p><strong>Questions or Need Support?</strong></p>
            <p>If you have any questions about your scorecard or performance management process, don't hesitate to reach out to your manager or HR team.</p>

            <p style="margin-top: 30px;">Best regards,<br>
            <strong>{{ $companyName }} Performance Management Team</strong></p>
        </div>

        <!-- Footer -->
        <div class="email-footer">
            <p>© {{ date('Y') }} {{ $companyName }}. All rights reserved.</p>
            <p>This is an automated notification. Please do not reply to this email.</p>
            <p style="margin-top: 10px;">
                <a href="{{ $action_url }}" style="color: #4caf50; text-decoration: none;">View Scorecard</a>
            </p>
        </div>
    </div>
</body>
</html>