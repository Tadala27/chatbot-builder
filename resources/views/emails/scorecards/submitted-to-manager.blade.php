<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scorecard Submitted for Review</title>
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
            background: linear-gradient(135deg, #1976d2 0%, #42a5f5 100%);
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
            color: #1976d2;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .scorecard-box {
            background-color: #f8f9fa;
            border-left: 4px solid #1976d2;
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
            background-color: #e3f2fd;
            padding: 15px;
            border-radius: 6px;
            text-align: center;
            margin: 20px 0;
            border: 2px solid #1976d2;
        }
        .score-highlight .score {
            font-size: 32px;
            font-weight: bold;
            color: #1976d2;
            display: block;
        }
        .score-highlight .score-label {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }
        .info-box {
            background-color: #e8f5e9;
            border-left: 4px solid #4caf50;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .info-box strong {
            color: #2e7d32;
        }
        .button {
            display: inline-block;
            background: linear-gradient(135deg, #1976d2 0%, #42a5f5 100%);
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
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 20px 0;
        }
        .stat-card {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            text-align: center;
            border: 1px solid #e0e0e0;
        }
        .stat-card .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #1976d2;
        }
        .stat-card .stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
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
            <h1>📊 Scorecard Submitted for Review</h1>
        </div>

        <!-- Body -->
        <div class="email-body">
            <p class="greeting">Hello {{ $manager->firstname }},</p>

            <p><strong>{{ $employee->full_name }}</strong> has submitted their performance scorecard and is awaiting your review.</p>

            <div class="scorecard-box">
                <p style="margin-top: 0; font-weight: 600; color: #1976d2;">📋 Scorecard Details</p>
                <p>
                    <span class="info-label">Employee:</span>
                    <span class="info-value">{{ $employee->full_name }}</span>
                </p>
                <p>
                    <span class="info-label">Position:</span>
                    <span class="info-value">{{ $position->name }}</span>
                </p>
                <p>
                    <span class="info-label">Business Unit:</span>
                    <span class="info-value">{{ $position->businessUnit->name ?? 'N/A' }}</span>
                </p>
                <p>
                    <span class="info-label">Financial Year:</span>
                    <span class="info-value">{{ $financial_year->name }}</span>
                </p>
                <p>
                    <span class="info-label">Performance Period:</span>
                    <span class="info-value">{{ $performance_period->name }}</span>
                </p>
                <p>
                    <span class="info-label">Submitted:</span>
                    <span class="info-value">{{ $scorecard->submitted_at->format('d M Y, H:i') }}</span>
                </p>
            </div>

            <div class="score-highlight">
                <span class="score">{{ $overall_score }}%</span>
                <div class="score-label">Overall Target Score</div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number">{{ $scorecard->perspectives->count() }}</div>
                    <div class="stat-label">Perspectives</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">{{ $scorecard->perspectives->sum(function($p) { return $p->goals->count(); }) }}</div>
                    <div class="stat-label">Goals</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">{{ $scorecard->perspectives->sum(function($p) { return $p->goals->sum(function($g) { return $g->objectives->count(); }); }) }}</div>
                    <div class="stat-label">Objectives</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number">{{ $scorecard->perspectives->sum(function($p) { return $p->goals->sum(function($g) { return $g->objectives->where('requires_proof', true)->count(); }); }) }}</div>
                    <div class="stat-label">Require Proof</div>
                </div>
            </div>

            <div class="info-box">
                <strong>ℹ️ What's Next?</strong>
                <p style="margin: 8px 0 0 0;">Please review the scorecard, provide feedback, and either approve or request changes. The employee will be notified of your decision.</p>
            </div>

            <div style="text-align: center;">
                <a href="{{ $action_url }}" class="button">Review Scorecard Now</a>
            </div>

            <div class="divider"></div>

            <h3 style="color: #1976d2; margin-top: 30px;">Review Checklist</h3>
            <ul style="margin: 15px 0; padding-left: 25px;">
                <li>Verify all objectives are clearly defined and measurable</li>
                <li>Check that targets are realistic and aligned with organizational goals</li>
                <li>Ensure weight distributions are appropriate (total 100%)</li>
                <li>Review proof requirements for key objectives</li>
                <li>Provide constructive feedback and guidance</li>
            </ul>

            <p style="margin-top: 30px;"><strong>Need Assistance?</strong></p>
            <p>If you have questions about the review process, please contact HR or your system administrator.</p>

            <p style="margin-top: 30px;">Best regards,<br>
            <strong>{{ $companyName }} Performance Management Team</strong></p>
        </div>

        <!-- Footer -->
        <div class="email-footer">
            <p>© {{ date('Y') }} {{ $companyName }}. All rights reserved.</p>
            <p>This is an automated notification. Please do not reply to this email.</p>
            <p style="margin-top: 10px;">
                <a href="{{ $action_url }}" style="color: #1976d2; text-decoration: none;">View Scorecard</a>
            </p>
        </div>
    </div>
</body>
</html>