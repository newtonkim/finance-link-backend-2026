<?php

namespace App\Central\Http\Controllers;

use App\Central\Services\LicenseService as ServicesLicenseService;
use App\Central\Services\TenantsService as ServicesTenantsService;
use App\Domain\Licensing\Entities\License;
use App\Domain\Licensing\Services\LicenseService as DomainLicenseService;
use App\Domain\Tenancy\Entities\Tenant;
use App\Http\Globals\BaseController;
use App\Http\Requests\Central\StoreLicenseRequest;
use App\Http\Requests\Central\UpdateLicenseRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LicenseController extends BaseController
{
    public function __construct(
        private DomainLicenseService $licenseService,
        private ServicesLicenseService $centralLicenseService,
        private ServicesTenantsService $tenantsService
    ) {}

    public function create_licenses_list(Request $request)
    {
        return $this->Response(['data' => $this->centralLicenseService->createLicense($request->all())]);
    }

    public function get_licenses_list(Request $request)
    {
        return $this->Response(['data' => $this->centralLicenseService->licensesListCollection($request)]);
    }

    public function get_license_stats()
    {
        return $this->Response(['data' => $this->centralLicenseService->licenseStats()]);
    }

    public function renewal_preview(Request $request)
    {
        return $this->Response(['data' => $this->centralLicenseService->licenseRenewalPreview($request->all())]);
    }

    public function renew_license(Request $request)
    {
        return $this->Response([
            'data' => $this->centralLicenseService->licenseRenew(
                $request->all(),
                $request->header('Idempotency-Key')
            ),
        ]);
    }

    public function confirm_payment(Request $request)
    {
        return $this->Response(['data' => $this->centralLicenseService->confirmLicensePayment($request->all())]);
    }

    public function invoices(Request $request)
    {
        return $this->Response(['data' => $this->centralLicenseService->licenseInvoices((string) $request->input('id'))]);
    }

    public function download_invoice(Request $request)
    {
        $document = $this->centralLicenseService->licenseInvoiceDocumentData((string) $request->input('invoice_id'));
        $pdf = Pdf::loadView('pdf.license-invoice', $document)->setPaper('a4');
        $filename = $document['invoice']->invoice_number.'.pdf';

        return $request->boolean('print') ? $pdf->stream($filename) : $pdf->download($filename);
    }

    public function email_invoice(Request $request)
    {
        return $this->Response(['data' => $this->centralLicenseService->emailLicenseInvoice($request->all())]);
    }

    public function edit_licenses_details(Request $request)
    {
        return $this->Response(['data' => $this->centralLicenseService->licensesEditDetails((string) $request->input('id'))]);
    }

    public function licenses_details(Request $request)
    {
        return $this->Response(['data' => $this->centralLicenseService->licensesDetailsCollection((string) $request->input('id'))]);
    }

    public function delete_licenses(Request $request)
    {
        return $this->Response(['data' => $this->centralLicenseService->licensesDelete($request->all())]);
    }

    public function get_licenses_drop_down()
    {
        return $this->Response(['data' => $this->tenantsService->tenantsDropdown()]);
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
