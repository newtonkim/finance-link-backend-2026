<?php

namespace App\Central\Http\Controllers;

use App\Central\Services\LicenseService as ServicesLicenseService;
use App\Central\Services\TenantsService as ServicesTenantsService;
use App\Domain\Licensing\Entities\License;
use App\Domain\Licensing\Services\LicenseService;
use App\Domain\Tenancy\Entities\Tenant;
use App\Http\Requests\Central\StoreLicenseRequest;
use App\Http\Requests\Central\UpdateLicenseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LicenseController extends ServicesLicenseService
{
    public function __construct(private LicenseService $licenseService) {}

    public function create_licenses_list()
    {
        return $this->Response(['data' => $this->createLicense()]);
    }

    public function get_licenses_list()
    {
        return $this->Response(['data' => $this->licensesListCollection()]);
    }

    public function edit_licenses_details()
    {
        return $this->Response(['data' => $this->licensesEditDetails()]);
    }

    public function licenses_details()
    {
        return $this->Response(['data' => $this->licensesDetailsCollection()]);
    }

    public function delete_licenses()
    {
        return $this->Response(['data' => $this->licensesDelete()]);
    }

    public function get_licenses_drop_down()
    {
        $licenseService = app(ServicesTenantsService::class);

        return $this->Response(['data' => $licenseService->tenantsDropdown()]);
    }

    public function index(Request $request): JsonResponse|View
    {
        $licenses = License::with(['tenant'])
            ->latest()
            ->paginate(10);

        if ($request->wantsJson()) {
            return response()->json($licenses);
        }

        return view('app', [
            'licenses' => $licenses,
        ]);
    }

    /**
     * Show the form for creating a new license (Web only).
     */
    public function create(): View
    {
        $tenants = Tenant::select('id', 'name')->get();

        return view('app', [
            'tenants' => $tenants,
        ]);
    }

    /**
     * Store a newly created license in storage.
     */
    public function store(StoreLicenseRequest $request): JsonResponse|RedirectResponse
    {
        $license = $this->licenseService->createLicense($request->validated());

        if ($request->wantsJson()) {
            return response()->json($license, 201);
        }

        return redirect()->route('central.licenses.index')
            ->with('success', 'License created successfully.');
    }

    /**
     * Display the specified license (API only).
     */
    public function show(string $id): JsonResponse
    {
        $license = License::with(['tenant'])->findOrFail($id);

        return response()->json($license);
    }

    /**
     * Show the form for editing the specified license (Web only).
     */
    public function edit(string $id): View
    {
        $license = License::findOrFail($id);
        $tenants = Tenant::select('id', 'name')->get();

        return view('app', [
            'license' => $license,
            'tenants' => $tenants,
        ]);
    }

    /**
     * Update the specified license in storage.
     */
    public function update(UpdateLicenseRequest $request, string $id): JsonResponse|RedirectResponse
    {
        $license = License::findOrFail($id);

        $this->licenseService->updateLicense($license, $request->validated());

        if ($request->wantsJson()) {
            return response()->json($license->fresh());
        }

        return redirect()->route('central.licenses.index')
            ->with('success', 'License updated successfully.');
    }

    /**
     * Remove the specified license from storage.
     */
    public function destroy(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $license = License::findOrFail($id);
        $this->licenseService->deleteLicense($license);

        if ($request->wantsJson()) {
            return response()->json(null, 204);
        }

        return redirect()->route('central.licenses.index')
            ->with('success', 'License deleted successfully.');
    }
}
