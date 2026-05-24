<!DOCTYPE html>
<html lang="en">

<head>

    <head>
        @include('partials.styles')
    </head>
</head>

<body>

    <div class="report-header">
        <h2>Members List</h2>
        <div class="small">Generated on: {{ now()->format('Y-m-d H:i') }}</div>
        <div class="meta">
            Total Records: {{ count($data) }}
        </div>
    </div>


    <table>
        <thead>
            <tr>
                <th>#</th>
                {{-- <th>Profile</th> --}}
                <th>Member Code</th>
                <th>Name</th>
                <th>Type</th>
                <th>NIN</th>
                <th>Gender</th>
                <th>Contacts</th>
                <th>Email</th>
                <th>Marital Status</th>
                <th>Joined Date</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data as $index => $member)
                <tr>
                    <td>{{ $index + 1 }}</td>

                    {{-- <td>
                        @if (!empty($member->profile))
                            <img src="{{ asset($member->profile) }}" class="profile-img">
                        @else
                            -
                        @endif
                    </td> --}}

                    <td>{{ $member->memeber_code }}</td>

                    <td>
                        {{ $member->salutation_name ?? $member->full_name }}
                    </td>

                    <td>{{ $member->member_type }}</td>

                    <td>{{ $member->NIN }}</td>

                    <td>{{ $member->sex }}</td>

                    <td class="small">
                        {{ $member->primary_contact }} <br>
                        {{ $member->other_contacts }}
                    </td>

                    <td class="small">{{ $member->email }}</td>

                    <td>{{ $member->marital_status }}</td>

                    <td>{{ \Carbon\Carbon::parse($member->joined_date)->format('Y-m-d') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" style="text-align:center;">No data found</td>
                </tr>
            @endforelse
        </tbody>
    </table>



</body>

</html>
