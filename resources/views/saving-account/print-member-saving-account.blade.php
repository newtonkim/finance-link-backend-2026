<!DOCTYPE html>
<html>

<head>

    <head>
        @include('partials.styles')
    </head>
</head>

<body>
    @include('partials.componyDetailsHeader')
    
    <div class="report-header">
        <h2>Savings Accounts Report</h2>
        <p class="sub-title">Generated on: {{ now() }}</p>
    </div>
    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Account Code</th>
                    <th>Member Name</th>
                    <th>Product</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th class="text-right">Balance</th>
                    <th>Created At</th>
                </tr>
            </thead>

            <tbody>
                @php $total = 0; @endphp

                @forelse ($data as $index => $acc)
                    @php $total += $acc->blc; @endphp
                    <tr>
                        <td>{{ ++$index }}</td>
                        <td>{{ $acc->account_code }}</td>
                        <td>{{ $acc->member_name }}</td>
                        <td>{{ $acc->product }}</td>
                        <td>{{ ucfirst($acc->type) }}</td>
                        <td>
                            <span class="badge {{ $acc->status == 'active' ? 'active' : 'inactive' }}">
                                {{ ucfirst($acc->status) }}
                            </span>
                        </td>
                        <td class="text-right">{{ number_format($acc->blc, 2) }}</td>
                        <td>{{ $acc->created_at }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="empty">
                            No records found
                        </td>
                    </tr>
                @endforelse
            </tbody>

        </table>
    </div>

</body>

</html>
