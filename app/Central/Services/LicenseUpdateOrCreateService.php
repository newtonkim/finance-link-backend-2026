<?php

namespace App\Central\Services;

use App\Http\Globals\GlobalHelpers;
use Carbon\Carbon;
use Illuminate\Support\Str;

class LicenseUpdateOrCreateService extends GlobalHelpers
{
    protected function licenseUOrCFields($req)
    {
        $start = trim($req['date'][0] ?? '', '"');
        $end = trim($req['date'][1] ?? '', '"');

        return $this->removeAllNullValues(
            [
                'tenant_id' => $req['tenant_id'] ?? null,
                'plan' => $req['plan'] ?? null,
                'starts_at' => $start ? Carbon::parse($start)->toDateTimeString() : null,
                'expires_at' => $end ? Carbon::parse($end)->toDateTimeString() : null,
                'status' => $req['status'] ?? null,
            ]
        );
    }

    public function licensesDelete()
    {
        $this->DeleteRecord('licenses', request());
        $licenseService = app(LicenseService::class);

        return $licenseService->licensesListCollection();
    }

    public function createLicense()
    {
        request()->validate([
            'tenant_id' => 'required|string',
            'plan' => 'required|string',
            'date' => 'required|array|min:2',
            'status' => 'required|string|in:active,inactive,suspended,trial,expired,trial',
        ]);

        $data = $this->licenseUOrCFields(request()->all());
        $data['id'] = Str::uuid()->toString();
        $data['created_at'] = Carbon::now();
        $this->UpdateOrCreateRecord('licenses', $data);
        $licenseService = app(LicenseService::class);

        return $licenseService->licensesListCollection();
    }
}
