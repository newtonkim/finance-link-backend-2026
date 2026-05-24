<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Proposed Repayment Schedule</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 12px;
            color: #333;
            line-height: 1.4;
        }
        .header {
            margin-bottom: 20px;
        }
        .report-title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 20px;
            color: #1a56db;
            border-bottom: 2px solid #1a56db;
            padding-bottom: 10px;
        }
        .info-grid {
            width: 100%;
            margin-bottom: 20px;
        }
        .info-grid td {
            vertical-align: top;
            padding: 5px;
        }
        .label {
            font-weight: bold;
            color: #666;
            width: 150px;
        }
        .value {
            font-weight: bold;
        }
        .summary-box {
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .summary-grid {
            width: 100%;
        }
        .summary-item {
            text-align: center;
        }
        .summary-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 5px;
        }
        .summary-value {
            font-size: 14px;
            font-weight: bold;
            color: #111827;
        }
        table.schedule {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table.schedule th {
            background-color: #f3f4f6;
            color: #374151;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10px;
            padding: 8px;
            border: 1px solid #e5e7eb;
            text-align: left;
        }
        table.schedule td {
            padding: 8px;
            border: 1px solid #e5e7eb;
        }
        .text-right {
            text-align: right;
        }
        .footer {
            margin-top: 30px;
            font-size: 10px;
            color: #9ca3af;
            text-align: center;
        }
        .highlight-blue { color: #1a56db; }
        .highlight-green { color: #059669; }
        .highlight-orange { color: #d97706; }
    </style>
</head>
<body>
    @include('partials.componyDetailsHeader')

    <div class="report-title">Proposed Repayment Schedule</div>

    <table class="info-grid">
        <tr>
            <td width="50%">
                <table>
                    <tr>
                        <td class="label">Member Name:</td>
                        <td class="value">{{ $member->name ?? 'N/A' }}</td>
                    </tr>
                    <tr>
                        <td class="label">Member No:</td>
                        <td class="value">{{ $member->member_number ?? $member->code ?? 'N/A' }}</td>
                    </tr>
                    <tr>
                        <td class="label">Product:</td>
                        <td class="value">{{ $product->name ?? 'N/A' }}</td>
                    </tr>
                </table>
            </td>
            <td width="50%">
                <table>
                    <tr>
                        <td class="label">Start Date:</td>
                        <td class="value">{{ $startDate }}</td>
                    </tr>
                    <tr>
                        <td class="label">Repayment Cycle:</td>
                        <td class="value">{{ ucfirst($repaymentCycle) }}</td>
                    </tr>
                    <tr>
                        <td class="label">Interest Rate:</td>
                        <td class="value">{{ $product->interest_rate ?? 0 }}% {{ ($product->interest_period ?? 'monthly') == 'per_month' ? 'p/m' : 'p/a' }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="summary-box">
        <table class="summary-grid">
            <tr>
                <td class="summary-item">
                    <div class="summary-label">Principal</div>
                    <div class="summary-value">{{ number_format($summary['total_principal'], 2) }}</div>
                </td>
                <td class="summary-item">
                    <div class="summary-label">Interest</div>
                    <div class="summary-value highlight-orange">{{ number_format($summary['total_interest'], 2) }}</div>
                </td>
                <td class="summary-item">
                    <div class="summary-label">Total Payable</div>
                    <div class="summary-value highlight-green">{{ number_format($summary['total_repayment'], 2) }}</div>
                </td>
                <td class="summary-item">
                    <div class="summary-label">Installment</div>
                    <div class="summary-value highlight-blue">{{ number_format($summary['monthly_installment'], 2) }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="schedule">
        <thead>
            <tr>
                <th>#</th>
                <th>Due Date</th>
                <th class="text-right">Principal</th>
                <th class="text-right">Interest</th>
                <th class="text-right">Total</th>
                <th class="text-right">Balance</th>
            </tr>
        </thead>
        <tbody>
            @foreach($installments as $inst)
                <tr>
                    <td>{{ $inst['number'] }}</td>
                    <td>{{ \Carbon\Carbon::parse($inst['due_date'])->format('d M, Y') }}</td>
                    <td class="text-right">{{ number_format($inst['principal'], 2) }}</td>
                    <td class="text-right">{{ number_format($inst['interest'], 2) }}</td>
                    <td class="text-right"><strong>{{ number_format($inst['total'], 2) }}</strong></td>
                    <td class="text-right">{{ number_format($inst['balance'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Generated by Mfuko Pro System on {{ now()->format('d M, Y H:i:s') }}<br>
        Note: This is a proposed schedule and is subject to change upon final disbursement.
    </div>

    @if(request()->has('print'))
        <script>
            window.onload = function() {
                window.print();
            }
        </script>
    @endif
</body>
</html>
