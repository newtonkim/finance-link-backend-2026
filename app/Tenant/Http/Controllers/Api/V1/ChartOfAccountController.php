<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Requests\Tenant\StoreChartOfAccountRequest;
use App\Http\Requests\Tenant\UpdateChartOfAccountRequest;
use App\Tenant\Http\Resources\ChartOfAccountsResource;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Services\ChartOfAccountService;
use App\Tenant\Services\ChartOfAccountService as ServicesChartOfAccountService;
use Illuminate\Http\Request;

class ChartOfAccountController extends ServicesChartOfAccountService
{
    public function __construct(
        protected ChartOfAccountService $coaService
    ) {}

    public function chart_of_accounts_drop_down_list(Request $request)
    {
        return $this->Response(['data' => self::chartOfAccountDropDownList()]);

    }

    public function index(Request $request)
    {
        $query = ChartOfAccount::orderBy('gl_code');

        // Handle filtering
        $type = $request->input('type') ?? $request->input('account_type');
        if ($type) {
            $query->where('account_type', strtoupper($type));
        }

        if ($request->has('account_subtype')) {
            $query->where('account_subtype', $request->input('account_subtype'));
        }

        if ($request->has('is_parent')) {
            $query->where('is_postable', !$request->boolean('is_parent'));
        } elseif ($request->boolean('is_postable')) {
            $query->where('is_postable', true);
        }

        if ($request->boolean('is_active', true)) {
            $query->where('is_active', true);
        }

        $search = $request->input('search');
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('gl_code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('list')) {
            $data = $query->get();
            $resource = ChartOfAccountsResource::collection($data);
            return response()->json([
                'data' => $resource,
                'payload' => $resource->resolve()
            ]);
        }

        $perPage = (int) $request->input('per_page', 15);
        $accounts = $query->paginate($perPage);
        $resource = ChartOfAccountsResource::collection($accounts->getCollection());

        return response()->json([
            'data' => $resource,
            'payload' => [
                'data' => $resource->resolve(),
                'links' => $accounts->linkCollection(),
                'from' => $accounts->firstItem(),
                'to' => $accounts->lastItem(),
                'total' => $accounts->total(),
            ]
        ]);
    }

    public function store(StoreChartOfAccountRequest $request)
    {
        try {
            $account = $this->coaService->createAccount($request->validated());

            return response()->json([
                'message' => 'Account created successfully.',
                'data' => new ChartOfAccountsResource($account),
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(UpdateChartOfAccountRequest $request, $id)
    {
        try {
            $account = $this->coaService->updateAccount($id, $request->validated());

            return response()->json([
                'message' => 'Account updated successfully.',
                'data' => new ChartOfAccountsResource($account),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy($id)
    {
        try {
            $this->coaService->deleteAccount($id);

            return response()->json(['message' => 'Account deleted successfully.']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
