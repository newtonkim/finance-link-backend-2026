<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Settings\Models\SaccoBranding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SaccoBrandingController extends Controller
{
    /** GET /sacco-branding */
    public function show()
    {
        $branding = SaccoBranding::current();

        if ($branding->logo_path) {
            $branding->logo_url = Storage::disk('public')->url($branding->logo_path);
        } else {
            $branding->logo_url = null;
        }

        return response()->json(['data' => $branding]);
    }

    /** POST /sacco-branding */
    public function update(Request $request)
    {
        $request->validate([
            'sacco_name' => 'nullable|string|max:255',
            'tagline' => 'nullable|string|max:255',
            'logo' => 'nullable|image|max:2048',
        ]);

        $branding = SaccoBranding::current();

        if ($request->has('sacco_name')) {
            $branding->sacco_name = $request->sacco_name;
        }
        if ($request->has('tagline')) {
            $branding->tagline = $request->tagline;
        }
        if ($request->hasFile('logo')) {
            if ($branding->logo_path) {
                Storage::disk('public')->delete($branding->logo_path);
            }
            $branding->logo_path = $request->file('logo')->store('sacco-logos', 'public');
        }

        $branding->save();

        $branding->logo_url = $branding->logo_path
            ? Storage::disk('public')->url($branding->logo_path)
            : null;

        return response()->json([
            'message' => 'Branding updated successfully.',
            'data' => $branding,
        ]);
    }
}
