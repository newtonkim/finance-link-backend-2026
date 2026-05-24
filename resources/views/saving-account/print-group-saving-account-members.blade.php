<!DOCTYPE html>
<html>
    @include('partials.styles')

    @include('partials.componyDetailsHeader')


<body>

    <!-- Header -->
    <div class="header">
        @if(!empty($logoDataUri))
            <img src="{{ $logoDataUri }}" class="logo">
        @endif

        <div class="title">{{ $app_name ?? 'Organization' }}</div>
        <div class="sub-title">Group Members Report</div>
    </div>

    <!-- Group Info -->
    <div class="meta">
        <div><strong>Group Name:</strong> {{ $group_name ?? '' }}</div>
        <div><strong>Group Code:</strong> {{ $group_code ?? '' }}</div>
        <div><strong>Date Generated:</strong> {{ now()->format('d M Y') }}</div>
    </div>

    <!-- Table -->
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Member Name</th>
                <th>Code</th>
                <th>Phone</th>
                <th>Status</th>
                <th>Group Code</th>
                <th class="text-right">Loan Balance</th>
                <th>Joined</th>
            </tr>
        </thead>
        <tbody>
            @php $totalLoan = 0; @endphp

            @foreach($data as $index => $member)
                @php $totalLoan += (float) $member->loan_balance ?? 0; @endphp
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $member->member_name }}</td>
                    <td>{{ $member->member_code }}</td>
                    <td>{{ $member->phone }}</td>
                    <td>{{ ucfirst($member->member_status) }}</td>
                    <td>{{ $member->member_group_code }}</td>
                    <td class="text-right">
                        {{ number_format($member->loan_balance ?? 0, 2) }}
                    </td>
                    <td>
                        {{ \Carbon\Carbon::parse($member->created_at)->format('d M Y') }}
                    </td>
                </tr>
            @endforeach

            <!-- Total Row -->
            <tr>
                <td colspan="6"><strong>Total</strong></td>
                <td class="text-right"><strong>{{ number_format($totalLoan, 2) }}</strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <!-- Footer -->
    <div class="footer">
        Generated on {{ now()->format('d M Y H:i') }}
    </div>

</body>
</html>