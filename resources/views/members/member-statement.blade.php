<div class="max-w-4xl mx-auto p-8 bg-gray-100 min-h-screen font-sans text-gray-800">
    
    <div class="bg-white rounded-xl shadow-sm p-8 mb-6 flex justify-between items-start">
        <div>
            <img src="https://upload.wikimedia.org/wikipedia/commons/3/3a/Airtel_logo-01.png" alt="Airtel Logo" class="h-12 mb-6">
            <p class="text-gray-500 text-sm">Balance statement for the period</p>
            <p class="font-bold text-lg">{{ $data['period'] }}</p>
        </div>
        <div class="text-right">
            <h1 class="text-xl font-bold text-blue-900 mb-6">{{ $data['name'] }}</h1>
            <p class="text-gray-600">{{ $data['phone'] }}</p>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6">
        <div class="bg-gray-50 px-6 py-3 border-b border-gray-100">
            <h2 class="font-bold text-gray-700">Summary</h2>
        </div>
        <table class="w-full text-left">
            <thead>
                <tr class="text-sm text-gray-500 border-b border-gray-100">
                    <th class="px-6 py-3 font-medium">Transaction Type</th>
                    <th class="px-6 py-3 font-medium text-right">Amount(UGX)</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <tr>
                    <td class="px-6 py-3">Total Money Debited</td>
                    <td class="px-6 py-3 text-right font-semibold">{{ $data['summary']['debited'] }}</td>
                </tr>
                <tr>
                    <td class="px-6 py-3">Total Money Credited</td>
                    <td class="px-6 py-3 text-right font-semibold">{{ $data['summary']['credited'] }}</td>
                </tr>
                <tr>
                    <td class="px-6 py-3">Opening Balance</td>
                    <td class="px-6 py-3 text-right font-semibold">{{ $data['summary']['opening'] }}</td>
                </tr>
                <tr>
                    <td class="px-6 py-3 text-blue-900 font-bold">Closing Balance</td>
                    <td class="px-6 py-3 text-right text-blue-900 font-bold">{{ $data['summary']['closing'] }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="bg-gray-50 px-6 py-3 border-b border-gray-100">
            <h2 class="font-bold text-gray-700">Detailed Statement</h2>
        </div>
        <table class="w-full text-left text-sm">
            <thead class="border-b-2 border-gray-200">
                <tr class="text-gray-500">
                    <th class="px-6 py-4 font-medium">Date & Time Details</th>
                    <th class="px-2 py-4 font-medium text-right">Credited</th>
                    <th class="px-2 py-4 font-medium text-right">Debited</th>
                    <th class="px-6 py-4 font-medium text-right">Toe-bola</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($data['transactions'] as $tx)
                <tr>
                    <td class="px-6 py-4">
                        <div class="font-bold text-gray-900">{{ $tx['date'] }}</div>
                        <div class="text-xs text-gray-400 mb-1">{{ $tx['time'] }}</div>
                        <div class="text-blue-900 font-semibold">
                            {{ $tx['type'] }} <span class="text-gray-600 font-normal">to {{ $tx['recipient'] }}</span>
                        </div>
                        <div class="text-xs text-gray-500">({{ $tx['id'] }})</div>
                    </td>
                    <td class="px-2 py-4 text-right align-top">{{ $tx['credited'] ?? '--' }}</td>
                    <td class="px-2 py-4 text-right align-top">{{ $tx['debited'] ?? '--' }}</td>
                    <td class="px-6 py-4 text-right align-top font-semibold">{{ $tx['balance'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

</div>