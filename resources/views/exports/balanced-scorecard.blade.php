<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Balanced Scorecard Report</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 9pt;
            color: #000;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            background-color: #002060;
            color: #fff;
            padding: 20px;
        }
        .title {
            font-size: 28pt;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .metadata-table {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }
        .metadata-table td {
            padding: 8px;
            border: 1px solid #E0E0E0;
        }
        .metadata-label {
            background-color: #002060;
            color: #fff;
            font-weight: bold;
            font-size: 12pt;
            width: 200px;
        }
        .metadata-value {
            background-color: #fff;
        }
        .notice {
            text-align: center;
            background-color: #F4F4F6;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 14pt;
            font-weight: bold;
        }
        table.scorecard {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .scorecard thead {
            background-color: #002060;
            color: #fff;
        }
        .scorecard th {
            padding: 12px 8px;
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
            border: 1px solid #000;
            vertical-align: middle;
        }
        .scorecard td {
            padding: 10px 8px;
            border: 1px solid #E0E0E0;
            vertical-align: middle;
            font-size: 10pt;
        }
        .perspective-cell {
            background-color: #002060;
            color: #fff;
            font-size: 16pt;
            font-weight: bold;
            text-align: center;
            vertical-align: middle;
            writing-mode: vertical-rl;
            text-orientation: mixed;
            min-width: 40px;
        }
        .goal-cell {
            font-weight: 600;
        }
        .weighting-cell {
            background-color: #0070C0;
            color: #fff;
            text-align: center;
            font-weight: bold;
        }
        .objective-cell {
            background-color: #f9f9f9;
        }
        .objective-weight-cell {
            text-align: center;
            background-color: #fff;
        }
        .initiatives-cell {
            background-color: #f5f5f5;
            font-size: 9pt;
        }
        .objective-type-cell {
            text-align: center;
            font-size: 9pt;
        }
        .small-text {
            font-size: 8pt;
            color: #666;
        }
        .section-title {
            font-size: 18pt;
            font-weight: bold;
            color: #002060;
            margin: 30px 0 15px 0;
            padding-bottom: 10px;
            border-bottom: 3px solid #002060;
        }
        .instructions-section {
            background-color: #F4F4F6;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 5px;
        }
        .instructions-section h2 {
            color: #002060;
            font-size: 16pt;
            margin-bottom: 15px;
        }
        .instructions-section ul {
            margin-left: 20px;
        }
        .instructions-section li {
            margin-bottom: 10px;
            line-height: 1.6;
        }
        .two-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media print {
            .page-break {
                page-break-after: always;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="title">
            {{ $metadata['company_name'] ?? 'COMPANY' }}
        </div>
        <div style="font-size: 16pt;">BALANCED SCORECARD</div>
    </div>

    <!-- Metadata Table -->
    <table class="metadata-table">
        <tr>
            <td class="metadata-label">Company Name:</td>
            <td class="metadata-value">{{ $metadata['company_name'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="metadata-label">Employee No.:</td>
            <td class="metadata-value">{{ $metadata['employee_no'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="metadata-label">Employee Name:</td>
            <td class="metadata-value">{{ $metadata['employee_name'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="metadata-label">Position:</td>
            <td class="metadata-value">{{ $metadata['position'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="metadata-label">Appraiser:</td>
            <td class="metadata-value">{{ $metadata['appraiser'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="metadata-label">Business Unit:</td>
            <td class="metadata-value">{{ $metadata['business_unit'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="metadata-label">Financial Year:</td>
            <td class="metadata-value">{{ $metadata['financial_year'] ?? '' }}</td>
        </tr>
    </table>

    <!-- Notice -->
    <div class="notice">
        Please refer to further notes below the table
    </div>

    <!-- Balanced Scorecard Table -->
    <table class="scorecard">
        <thead>
            <tr>
                <th style="width: 8%;">Perspective</th>
                <th style="width: 18%;">Goals<br>(Key Performance Area)</th>
                <th style="width: 8%;">Weighting<br>(%)</th>
                <th style="width: 25%;">Objectives</th>
                <th style="width: 8%;">Weighting<br>(%)</th>
                <th style="width: 18%;">Initiatives<br>(Optional)</th>
                <th style="width: 15%;">Objective Type</th>
            </tr>
        </thead>
        <tbody>
            @foreach($perspectives as $perspective)
            @php
                $goalCount = count($perspective['goals']);
                $firstGoal = true;
            @endphp
            @foreach($perspective['goals'] as $goalIndex => $goal)
                @php
                    $objectiveCount = count($goal['objectives']);
                    $firstObjective = true;
                @endphp
                @foreach($goal['objectives'] as $objIndex => $objective)
                <tr>
                    @if($firstGoal && $firstObjective)
                    <td class="perspective-cell" rowspan="{{ array_sum(array_map(fn($g) => count($g['objectives']), $perspective['goals'])) }}">
                        {{ $perspective['name'] }}
                    </td>
                    @endif
                    
                    @if($firstObjective)
                    <td class="goal-cell" rowspan="{{ $objectiveCount }}">
                        {{ $goal['name'] }}
                    </td>
                    <td class="weighting-cell" rowspan="{{ $objectiveCount }}">
                        {{ $goal['weighting'] }}%
                    </td>
                    @endif
                    
                    <td class="objective-cell">{{ $objective['name'] }}</td>
                    <td class="objective-weight-cell">{{ $objective['weighting'] }}%</td>
                    <td class="initiatives-cell">{{ $objective['initiative'] ?? '' }}</td>
                    <td class="objective-type-cell">{{ $objective['type'] ?? '' }}</td>
                </tr>
                @php
                    $firstObjective = false;
                    $firstGoal = false;
                @endphp
                @endforeach
            @endforeach
            @endforeach
        </tbody>
    </table>

    <div class="page-break"></div>

    <!-- Instructions Section -->
    <div class="section-title" style="width: 8%;">BSC Template: Instructions & Cautions</div>
    
    <div class="instructions-section">
        <div class="two-column">
            <div>
                <h2>Instructions</h2>
                <ul>
                    <li>1. Enter your objectives in column E.</li>
                    <li>2. Enter your measures in column G.</li>
                    <li>3. Remember to adjust your merged cells between column E and G so that your measures align with your objectives.</li>
                    <li>4. Adjust your number format for actual and targets in columns I-W.</li>
                    <li>5. Enter your targets (for your annual target, it may roll up or be independent from your quarterly targets).</li>
                    <li>6. Enter you actuals each quarter (for annual, it may roll up in a sum or average).</li>
                    <li>7. Use the drop down in columns J, M, P, S, and V to show your color status for the quarter and for the year.</li>
                    <li style="margin-top: 15px; font-weight: bold;">Status Legend:</li>
                    <li style="margin-left: 40px;">• On Target</li>
                    <li style="margin-left: 40px;">• Caution</li>
                    <li style="margin-left: 40px;">• Needs Help</li>
                    <li style="margin-left: 40px;">• No Data</li>
                    <li>8. Enter your initiatives in column Y. Please note that some initiatives may repeat.</li>
                </ul>
            </div>
            <div>
                <h2>Cautions</h2>
                <ul>
                    <li>1. This Excel template does not evaluate objectives or initiatives</li>
                    <li>2. This Excel template does not automatically evaluate measures as they are manual without condition</li>
                    <li>3. This Excel template does not support a strategy map.</li>
                    <li>4. This Excel template is only for one level in an organization, and so it does not support alignment.</li>
                    <li>5. This Excel template was not designed to support qualitative assessments.</li>
                    <li>6. If you let more than one person use the template, you may create version control issues.</li>
                    <li style="margin-top: 30px;">
                        <strong>For a comprehensive tool for Balanced Scorecard write to:</strong><br>
                        <a href="mailto:info@fnbusinessconsultants.com">info@fnbusinessconsultants.com</a>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    @if(isset($metadata['generated_at']))
    <div style="text-align: center; margin-top: 30px; font-size: 8pt; color: #666;">
        Generated: {{ $metadata['generated_at'] }} by {{ $metadata['generated_by'] ?? 'System' }}
    </div>
    @endif
</body>
</html>