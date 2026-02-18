<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Shareholders Report</title>
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
        }
        .title {
            font-size: 18pt;
            font-weight: bold;
            color: #0b3f74;
            margin-bottom: 8px;
        }
        .subtitle {
            font-size: 10pt;
            color: #666;
            margin-bottom: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        thead {
            background-color: #F5F5F5;
        }
        th {
            padding: 10px 8px;
            text-align: left;
            font-size: 9pt;
            font-weight: bold;
            color: #666;
            border: 1px solid #E0E0E0;
            text-transform: uppercase;
        }
        td {
            padding: 10px 8px;
            border: 1px solid #E0E0E0;
            vertical-align: top;
            font-size: 9pt;
        }
        tbody tr {
            background-color: #FFF;
        }
        .member-cell, .contact-cell {
            line-height: 1.4;
        }
        .small-text {
            font-size: 8pt;
            color: #666;
            display: block;
            margin-top: 2px;
        }
        .contributions-cell {
            font-weight: bold;
            color: #4CAF50;
        }
        .totals-row {
            background-color: #F5F5F5;
            font-weight: bold;
        }
        .totals-row .contributions-cell {
            color: #4CAF50;
        }
        tr {
            page-break-inside: avoid;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Shareholders Report</div>

        @if($period)
            <div class="subtitle">Period: {{ $period }}</div>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 18%;">MEMBER</th>
                <th style="width: 22%;">CONTACT</th>
                <th style="width: 14%;">CONTRIBUTIONS</th>
                <th style="width: 10%;">SHARE UNITS</th>
                <th style="width: 14%;">SHARE VALUE</th>
                <th style="width: 10%;">STATUS</th>
                <th style="width: 12%;">DATE JOINED</th>
            </tr>
        </thead>
        <tbody>
            @foreach($shareholders as $shareholder)
            <tr>
                <!-- Member -->
                <td class="member-cell">
                    <div>{{ $shareholder->member_number }}</div>
                    <span class="small-text">{{ $shareholder->user->first_name }} {{ $shareholder->user->last_name }}</span>
                </td>

                <!-- Contact -->
                <td class="contact-cell">
                    <div>{{ $shareholder->user->email }}</div>
                    <span class="small-text">{{ $shareholder->user->phone ?: 'N/A' }}</span>
                </td>

                <!-- Contributions -->
                <td class="contributions-cell">
                    MWK {{ number_format($shareholder->totals ? $shareholder->totals->total_contributions : 0, 2) }}
                </td>

                <!-- Share Units -->
                <td>
                    {{ $shareholder->share_units ?: 0 }}
                </td>

                <!-- Share Value -->
                <td>
                    MWK {{ number_format($shareholder->share_value ?: 0, 2) }}
                </td>

                <!-- Status -->
                <td>
                    {{ $shareholder->status }}
                </td>

                <!-- Date Joined -->
                <td>
                    {{ date('d M Y', strtotime($shareholder->date_joined)) }}
                </td>
            </tr>
            @endforeach

            <!-- Totals Row -->
            <tr class="totals-row">
                <td><strong>TOTALS</strong></td>
                <td><strong>{{ $totals['count'] }} Shareholders</strong></td>
                <td class="contributions-cell">
                    <strong>MWK {{ number_format($totals['total_contributions'], 2) }}</strong>
                </td>
                <td></td>
                <td>
                    <strong>MWK {{ number_format($totals['total_share_value'], 2) }}</strong>
                </td>
                <td><strong>Active: {{ $totals['active'] }}</strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>
</body>
</html>