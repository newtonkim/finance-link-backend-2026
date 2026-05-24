<?php

namespace App\Central\Http\Controllers;

use App\Central\Models\Plan;
use App\Central\Services\LicenseService;
use App\Central\Services\TenantProvisioningService;
use App\Central\Services\TenantsService as ServicesTenantsService;
use App\Domain\Tenancy\Entities\Tenant;
use App\Domain\Tenancy\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class TenantController extends ServicesTenantsService
{
    public function __construct(
        protected TenantProvisioningService $provisioningService,
        protected LicenseService $licenseService,
        protected TenantService $tenantService
    ) {}

    /**
     * Display a listing of tenants.
     */
    public function index()
    {
        return $this->tenantsListCollection();
    }

    public function get_tenants_list()
    {
        return $this->Response(['data' => $this->tenantsListCollection()]);
    }

    public function get_tenant_details()
    {
        // Log::error('get_tenant_details',[$this->tenantDetails()]);
        return $this->Response(['data' => $this->tenantDetails()]);
    }

    public function delete_tenant()
    {
        return $this->Response(['data' => $this->tenantsDelete()]);
    }

    public function get_tenants_drop_down()
    {
        return $this->Response(['data' => $this->tenantsDropdown()]);
    }

    /**
     * Show the form for creating a new tenant.
     */
    public function create(): View
    {
        $plans = Plan::all();

        return view('app', [
            'plans' => $plans,
        ]);
    }

    /**
     * Store a newly created tenant in storage.
     *
     * Runs the full provisioning pipeline (DB create + migrations + seeders + admin user)
     * synchronously so the admin credentials are guaranteed to exist before the response
     * is returned. Requires nginx fastcgi_read_timeout and PHP-FPM request_terminate_timeout
     * to be at least 180s on the server.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subdomain' => 'required|string|alpha_dash|unique:master.tenants,subdomain|max:100',
            'admin_name' => 'sometimes|string|max:255',
            'admin_email' => 'required|email|max:255',
            'admin_password' => 'required|string|min:8',
            'plan' => 'required',
            'license_months' => 'required',
        ]);

        // Provisioning can take 30–60s on a fresh DB. Raise PHP's limit so it isn't killed
        // mid-flight. The web server's read/terminate timeouts must also be raised to match.
        @set_time_limit(180);

        $tenant = $this->provisioningService->createBaseAccount($validated);

        try {
            $this->provisioningService->provisionResources($tenant, $validated);
        } catch (\Throwable $e) {
            Log::error("Provisioning failed for {$tenant->subdomain}: ".$e->getMessage(), [
                'exception' => $e,
            ]);

            $this->rollbackTenant($tenant);

            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'Failed to provision tenant. Please try again.',
                    'error' => $e->getMessage(),
                ], 500);
            }

            return redirect()->route('central.tenants.index')
                ->with('error', "Tenant provisioning failed: {$e->getMessage()}");
        }

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Tenant created and provisioned successfully.',
                'tenant' => $tenant->fresh(),
            ], 201);
        }

        return redirect()->route('central.tenants.index')
            ->with('success', "Tenant '{$tenant->name}' created successfully.");
    }

    /**
     * Undo a partial tenant creation so the subdomain can be reused on retry.
     * Each step is best-effort and isolated — a rollback failure must not mask the original error.
     */
    protected function rollbackTenant(Tenant $tenant): void
    {
        try {
            $this->tenantService->dropPhysicalDatabase($tenant);
        } catch (\Throwable $e) {
            Log::error("Rollback: failed to drop database for {$tenant->subdomain}: ".$e->getMessage());
        }

        try {
            $tenant->licenses()->delete();
            $tenant->forceDelete();
        } catch (\Throwable $e) {
            Log::error("Rollback: failed to delete tenant record {$tenant->subdomain}: ".$e->getMessage());
        }
    }

    /**
     * Display the specified tenant.
     */
    public function show(Request $request, string $id): JsonResponse|View
    {
        $tenant = Tenant::with(['licenses', 'activeLicense'])->findOrFail($id);

        if ($request->wantsJson()) {
            return response()->json($tenant);
        }

        $plans = Plan::all();

        return view('app', [
            'tenant' => $tenant,
            'plans' => $plans,
        ]);
    }

    /**
     * Update the specified tenant in storage.
     */
    public function update(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $tenant = Tenant::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|in:active,suspended,trial',
            'settings' => 'sometimes|array',
        ]);

        $tenant->update($validated);

        if ($request->wantsJson()) {
            return response()->json($tenant);
        }

        return redirect()->route('central.tenants.show', $tenant->id)
            ->with('success', 'Tenant updated successfully.');
    }

    /**
     * Remove the specified tenant from storage (soft delete).
     */
    public function destroy(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->delete();

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Tenant successfully deleted'], 200);
        }

        return redirect()->route('central.tenants.index')
            ->with('success', 'Tenant deleted successfully.');
    }

    /**
     * Suspend a tenant.
     */
    public function suspend(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['status' => 'suspended']);
        $this->licenseService->suspend($tenant);

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Tenant suspended.']);
        }

        return redirect()->route('central.tenants.show', $tenant->id)
            ->with('success', "Tenant '{$tenant->name}' has been suspended.");
    }

    /**
     * Activate a tenant.
     */
    public function activate(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['status' => 'active']);

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Tenant activated.']);
        }

        return redirect()->route('central.tenants.show', $tenant->id)
            ->with('success', "Tenant '{$tenant->name}' has been activated.");
    }

    /**
     * Renew a tenant's license.
     */
    public function renew(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $tenant = Tenant::findOrFail($id);

        $validated = $request->validate([
            'plan' => 'required|exists:master.plans,slug',
            'days' => 'required|integer|min:1',
        ]);

        $this->licenseService->renew($tenant, $validated['plan'], $validated['days']);
        $tenant->update(['status' => 'active']);

        if ($request->wantsJson()) {
            return response()->json(['message' => 'License renewed.']);
        }

        return redirect()->route('central.tenants.show', $tenant->id)
            ->with('success', 'License renewed successfully.');
    }

    /**
     * Extend a tenant's grace period.
     */
    public function extendGrace(Request $request, string $id): JsonResponse|RedirectResponse
    {
        $tenant = Tenant::findOrFail($id);

        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:90',
        ]);

        $this->licenseService->extendGrace($tenant, $validated['days']);

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Grace period extended.']);
        }

        return redirect()->route('central.tenants.show', $tenant->id)
            ->with('success', "Grace period extended by {$validated['days']} days.");
    }

    /**
     * Get all available plans.
     */
    public function plans(): JsonResponse
    {
        return response()->json(Plan::all());
    }
}
