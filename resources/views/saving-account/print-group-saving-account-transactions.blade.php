<!DOCTYPE html>
<html>
<head>
    @include('partials.styles')
</head>
<body>

<div>

    @include('partials.componyDetailsHeader')

    <!-- Report Title -->
    <h4 style="text-align:center; margin-top:10px;">
        Group Savings Account Transactions
    </h4>

    <div class="meta" style="text-align:center; margin-bottom:20px;">
        Generated on: {{ now()->format('d M Y H:i') }}
    </div>

    <!-- Table -->
    <table>
        <thead>
            <tr>
                <th style="color: black !important">#</th>
                <th style="color: black !important">Ref</th>
                <th style="color: black !important">Member</th>
                <th style="color: black !important">Phone</th>
                <th style="color: black !important">Product</th>
                <th style="color: black !important" class="text-right">Balance</th>
                <th style="color: black !important" class="text-right">Amount</th>
                <th style="color: black !important" class="text-right">Charge</th>
                <th style="color: black !important">Status</th>
                <th style="color: black !important">Date</th>
                <th style="color: black !important">Narration</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($data as $index => $row)
                <tr>
                    <td>{{ $index + 1 }}</td>

                    <td>{{ mb_convert_encoding($row->code, 'UTF-8', 'UTF-8') }}</td>

                    <td>
                        {{ mb_convert_encoding($row->member_name, 'UTF-8', 'UTF-8') }} <br>
                        <small>{{ mb_convert_encoding($row->member_code, 'UTF-8', 'UTF-8') }}</small>
                    </td>

                    <td>{{ mb_convert_encoding($row->member_phone, 'UTF-8', 'UTF-8') }}</td>

                    <td>{{ mb_convert_encoding($row->product, 'UTF-8', 'UTF-8') }}</td>

                    <td class="text-right">
                        {{ number_format($row->blc, 2) }}
                    </td>

                    <td class="text-right">
                        {{ number_format($row->amount, 2) }}
                    </td>

                    <td class="text-right">
                        {{ number_format($row->charge, 2) }}
                    </td>

                    <td>
                        <span class="status {{ $row->status === 'active' ? 'active' : 'inactive' }}">
                            {{ ucfirst($row->status) }}
                        </span>
                    </td>

                    <td>
                        {{ \Carbon\Carbon::parse($row->transaction_date)->format('d M Y') }}
                    </td>

                    <td>
                        {{ mb_convert_encoding($row->narration, 'UTF-8', 'UTF-8') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" style="text-align:center;">
                        No records found
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Footer -->
    <div class="footer" style="margin-top:15px; text-align:right; font-weight:bold;">
        Total Records: {{ count($data) }}
    </div>

</div>

</body>
</html>