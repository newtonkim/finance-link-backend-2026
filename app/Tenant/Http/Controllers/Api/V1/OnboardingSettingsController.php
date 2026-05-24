<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\OnboardingSettingsRequest;
use App\Tenant\Modules\Settings\Models\OnboardingSettings;

class OnboardingSettingsController extends Controller
{
    /** GET /onboarding-settings */
    public function show()
    {
        return response()->json([
            'data' => OnboardingSettings::current(),
        ]);
    }

    /** PUT /onboarding-settings */
    public function update(OnboardingSettingsRequest $request)
    {
        $settings = OnboardingSettings::current();
        $data = $request->validated();
        $data['loyal_member_min_tenure_months'] = $data['loyal_member_min_tenure_months'] ?? 12;
        $settings->fill($data)->save();

        return response()->json([
            'message' => 'Onboarding settings saved successfully.',
            'data' => $settings,
        ]);
    }
}
