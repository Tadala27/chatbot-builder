<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment History Report</title>
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
        .transaction-cell,
        .shareholder-cell,
        .method-cell {
            line-height: 1.4;
        }
        .small-text {
            font-size: 8pt;
            color: #666;
            display: block;
            margin-top: 2px;
        }
        .amount-cell {
            font-weight: bold;
            color: #4CAF50;
            text-align: left;
        }
        .totals-row {
            background-color: #F5F5F5;
            font-weight: bold;
        }
        .totals-row .amount-cell {
            color: #4CAF50;
            font-weight: bold;
        }
        tr {
            page-break-inside: avoid;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Payment History Report</div>
        
        @if(isset($metadata['financial_year']) && !empty($metadata['financial_year']['name']))
            <div class="subtitle">
                Financial Year: {{ $metadata['financial_year']['name'] }}
                @if(!empty($metadata['financial_year']['start_date']) && !empty($metadata['financial_year']['end_date']))
                    ({{ $metadata['financial_year']['start_date'] }} - {{ $metadata['financial_year']['end_date'] }})
                @endif
            </div>
        @elseif(isset($metadata['date_range']) && $metadata['date_range'])
            <div class="subtitle">Period: {{ $metadata['date_range'] }}</div>
        @endif
        
        @if(isset($metadata['generated_at']))
            <div class="subtitle" style="font-size: 8pt; margin-top: 5px;">
                Generated: {{ $metadata['generated_at'] }} by {{ $metadata['generated_by'] ?? 'System' }}
            </div>
        @endif
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 15%;">TRANSACTION</th>
                <th style="width: 20%;">SHAREHOLDER</th>
                <th style="width: 12%;">AMOUNT</th>
                <th style="width: 18%;">TYPE</th>
                <th style="width: 20%;">METHOD</th>
                <th style="width: 15%;">STATUS</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payments as $payment)
            <tr>
                <!-- Transaction -->
                <td class="transaction-cell">
                    <div>{{ $payment->receipt_number ?: 'N/A' }}</div>
                    <span class="small-text">{{ $payment->transaction_date->format('d M Y') }}</span>
                </td>

                <!-- Shareholder -->
                <td class="shareholder-cell">
                    <div>{{ $payment->shareholder->user->first_name }} {{ $payment->shareholder->user->last_name }}</div>
                    <span class="small-text">{{ $payment->shareholder->member_number }}</span>
                </td>

                <!-- Amount -->
                <td class="amount-cell">
                    MWK {{ number_format($payment->amount, 2) }}
                </td>

                <!-- Payment Type -->
                <td>
                    {{ $payment->payment_type }}
                </td>

                <!-- Payment Method -->
                <td class="method-cell">
                    <div>{{ $payment->payment_method ?: 'N/A' }}</div>
                    @if($payment->bank_reference)
                        <span class="small-text">Ref: {{ $payment->bank_reference }}</span>
                    @endif
                </td>

                <!-- Status -->
                <td>
                    {{ $payment->is_verified ? 'Verified' : 'Pending' }}
                </td>
            </tr>
            @endforeach

            <!-- Totals Row -->
            <tr class="totals-row">
                <td><strong>TOTALS</strong></td>
                <td><strong>{{ $totals['count'] }} Payments</strong></td>
                <td class="amount-cell">
                    <strong>MWK {{ number_format($totals['total_amount'], 2) }}</strong>
                </td>
                <td></td>
                <td></td>
                <td></td>
            </tr>
        </tbody>
    </table>
</body>
</html>