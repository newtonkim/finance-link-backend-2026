<?php

namespace App\Central\Services;

use App\Central\Models\Plan;
use App\Http\Globals\GlobalHelpers;
use Carbon\Carbon;
use Illuminate\Support\Str;

class LicenseUpdateOrCreateService extends GlobalHelpers
{
    protected function licenseUOrCFields($req)
    {
        $start = trim($req['date'][0] ?? '', '"');
        $end = trim($req['date'][1] ?? '', '"');
        $planId = $this->resolveLegacyPlanId($req['plan'] ?? null);

        return $this->removeAllNullValues(
            [
                'tenant_id' => $req['tenant_id'] ?? null,
                'plan_id' => $planId,
                'plan' => $planId ? (string) $planId : ($req['plan'] ?? null),
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

    private function resolveLegacyPlanId(?string $planIdentifier): ?int
    {
        if (! $planIdentifier) {
            return null;
        }

        return Plan::query()
            ->where('id', $planIdentifier)
            ->orWhere('slug', $planIdentifier)
            ->value('id');
    }
}
