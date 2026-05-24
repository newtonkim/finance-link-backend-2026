<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Http\Resources\ExpenseCategoryResource;
use App\Tenant\Modules\Accounting\Services\ChartOfAccountService;
use App\Tenant\Modules\Expenses\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class ExpenseCategoryController extends Controller
{
    public function __construct(
        protected ChartOfAccountService $coaService
    ) {}

    /**
     * GET /expenses/categories
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = $request->header('X-Acting-Branch-Id');

        $query = ExpenseCategory::with(['chartOfAccount.parent'])
            ->orderBy('name');

        if ($branchId) {
            $query->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id')
                    ->orWhere('branch_id', (int) $branchId);
            });
        }

        $categories = $query->get();

        return \Illuminate\Support\Facades\Response::json([
            'data' => ExpenseCategoryResource::collection($categories),
            'payload' => ExpenseCategoryResource::collection($categories)->resolve()
        ]);
    }

    /**
     * POST /expenses/categories
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_account_id' => 'required|integer|exists:tenant.chart_of_accounts,id',
        ]);

        $branchId = $request->header('X-Acting-Branch-Id');

        $category = DB::connection('tenant')->transaction(function () use ($validated, $branchId) {
            // 1. Create the GL Account in COA
            $coaData = [
                'name' => $validated['name'],
                'parent_id' => $validated['parent_account_id'],
                'is_postable' => true,
                'is_control' => false,
                'allow_manual' => true,
                'is_active' => true,
                'gl_code' => $this->generateGlCode($validated['parent_account_id']),
            ];

            $coaAccount = $this->coaService->createAccount($coaData);

            // 2. Create the Expense Category
            return ExpenseCategory::create([
                'name' => $validated['name'],
                'description' => $validated['description'],
                'chart_of_account_id' => $coaAccount->id,
                'branch_id' => $branchId,
                'created_by' => Auth::id() ?? 1,
            ]);
        });

        return Response::json([
            'message' => 'Expense category created successfully.',
            'data' => new ExpenseCategoryResource($category),
            'code' => 200
        ]);
    }

    /**
     * Generate a unique GL code based on parent
     */
    private function generateGlCode(int $parentId): string
    {
        $parent = DB::connection('tenant')->table('chart_of_accounts')->find($parentId);
        $lastChild = DB::connection('tenant')->table('chart_of_accounts')
            ->where('parent_id', $parentId)
            ->orderBy('gl_code', 'desc')
            ->first();

        if ($lastChild) {
            return (string) ((int) $lastChild->gl_code + 1);
        }

        return $parent->gl_code . '01';
    }
}
