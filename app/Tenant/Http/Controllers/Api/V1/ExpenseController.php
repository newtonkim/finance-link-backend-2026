<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Response;
use App\Tenant\Http\Resources\ExpenseResource;
use App\Tenant\Modules\Expenses\Models\Expense;
use App\Tenant\Modules\Expenses\Enums\ExpenseStatus;
use App\Tenant\Modules\Expenses\Models\ExpenseAttachment;
use App\Tenant\Modules\Expenses\Services\ExpenseAccountingService;
use App\Tenant\Modules\Expenses\Services\ExpenseGovernanceService;
use App\Models\Staff;
use App\Support\BranchContext;

class ExpenseController extends Controller
{
    public function __construct(
        protected ExpenseAccountingService $accountingService,
        protected ExpenseGovernanceService $governanceService
    ) {}

    /**
     * GET /expenses
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $branchId = $request->header('X-Acting-Branch-Id');

        $query = Expense::with(['category', 'paymentAccount', 'creator', 'approver', 'payer', 'attachments', 'approvalHistory.approver'])
            ->orderByDesc('created_at');

        if ($status && $status !== 'All' && $status !== 'Expenses') {
            $query->where('status', $status);
        }

        if ($branchId) {
            $query->where('branch_id', (int) $branchId);
        }

        $perPage = min((int) ($request->query('per_page', 10)), 100);
        $paginated = $query->paginate($perPage);

        return Response::json([
            'data' => ExpenseResource::collection($paginated->items()),
            'payload' => [
                'data' => ExpenseResource::collection($paginated->items())->resolve(),
                'links' => $paginated->linkCollection(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
                'total' => $paginated->total(),
            ],
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
            ],
        ]);
    }

    /**
     * GET /expenses/{id}
     */
    public function show(int $id): JsonResponse
    {
        $expense = Expense::with(['category', 'paymentAccount', 'creator', 'approver', 'payer', 'attachments', 'approvalHistory.approver'])
            ->findOrFail($id);

        return Response::json([
            'data' => new ExpenseResource($expense),
            'code' => 200
        ]);
    }

    /**
     * POST /expenses
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->has('amount') && is_string($request->input('amount'))) {
            $request->merge(['amount' => str_replace(',', '', $request->input('amount'))]);
        }

        if ($request->has('is_recurring')) {
            $request->merge(['is_recurring' => filter_var($request->input('is_recurring'), FILTER_VALIDATE_BOOLEAN)]);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'expense_category_id' => 'required|exists:tenant.expense_categories,id',
            'amount' => 'required|numeric|min:0.01',
            'transaction_date' => 'required|date',
            'payment_method' => 'required|string',
            'vendor_name' => 'nullable|string',
            'reference_no' => 'nullable|string',
            'description' => 'required|string',
            'is_recurring' => 'boolean',
            'recurring_frequency' => 'nullable|string|required_if:is_recurring,true,1',
            'next_due_date' => 'nullable|date|required_if:is_recurring,true,1',
            'receipt_attachment' => [
                $request->input('amount') >= 50000 ? 'required' : 'nullable',
                'file',
                'max:2048'
            ],
        ], [
            'receipt_attachment.required' => 'Supporting documentation is mandatory for expenses above UGX 50,000.'
        ]);

        $periodCode = Carbon::parse($validated['transaction_date'])->format('Y-m');
        $isLocked = DB::connection('tenant')->table('accounting_periods')
            ->where('period_code', $periodCode)
            ->where('is_locked', true)
            ->exists();

        if ($isLocked) {
            return Response::json(['message' => "The accounting period {$periodCode} is closed and cannot accept new transactions."], 422);
        }

        $branchId = $request->header('X-Acting-Branch-Id');

        $result = DB::connection('tenant')->transaction(function () use ($validated, $branchId, $request) {
            $expense = Expense::create([
                'title' => $validated['title'],
                'expense_category_id' => $validated['expense_category_id'],
                'amount' => $validated['amount'],
                'transaction_date' => $validated['transaction_date'],
                'payment_method' => $validated['payment_method'],
                'vendor_name' => $validated['vendor_name'] ?? null,
                'reference_no' => $validated['reference_no'] ?? null,
                'description' => $validated['description'],
                'is_recurring' => $validated['is_recurring'] ?? false,
                'recurring_frequency' => $validated['recurring_frequency'] ?? null,
                'next_due_date' => $validated['next_due_date'] ?? null,
                'status' => ExpenseStatus::Submitted->value,
                'branch_id' => $branchId,
                'created_by' => Auth::id(),
                'type' => $request->input('type', 'cash'),
                'is_over_budget' => false, // Will update after check
            ]);

            // Budget Check
            $budgetResult = $this->governanceService->checkBudget($expense);
            if (!$budgetResult['allowed']) {
                $expense->update(['is_over_budget' => true]);
            }

            // Handle attachment
            if ($request->hasFile('receipt_attachment')) {
                $path = $request->file('receipt_attachment')->store('expenses/receipts', 'public');
                ExpenseAttachment::create([
                    'expense_id' => $expense->id,
                    'file_path' => $path,
                    'file_name' => $request->file('receipt_attachment')->getClientOriginalName(),
                    'file_type' => $request->file('receipt_attachment')->getClientMimeType(),
                    'file_size' => $request->file('receipt_attachment')->getSize(),
                    'uploaded_by' => Auth::id(),
                    'created_by' => Auth::id(),
                ]);
            }

            return ['expense' => $expense, 'budgetResult' => $budgetResult];
        });

        return Response::json([
            'message' => 'Expense recorded successfully.',
            'data' => new ExpenseResource($result['expense']),
            'budget_alert' => !$result['budgetResult']['allowed'] ? $result['budgetResult']['message'] : null,
            'code' => 200
        ]);
    }

    /**
     * PUT /expenses/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        if ($expense->status !== ExpenseStatus::Pending->value) {
            return Response::json(['message' => 'Only pending expenses can be edited.'], 422);
        }

        if ($request->has('amount') && is_string($request->input('amount'))) {
            $request->merge(['amount' => str_replace(',', '', $request->input('amount'))]);
        }

        if ($request->has('is_recurring')) {
            $request->merge(['is_recurring' => filter_var($request->input('is_recurring'), FILTER_VALIDATE_BOOLEAN)]);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'expense_category_id' => 'required|exists:tenant.expense_categories,id',
            'amount' => 'required|numeric|min:0.01',
            'transaction_date' => 'required|date',
            'payment_method' => 'required|string',
            'vendor_name' => 'nullable|string',
            'reference_no' => 'nullable|string',
            'description' => 'required|string',
            'is_recurring' => 'boolean',
            'recurring_frequency' => 'nullable|string|required_if:is_recurring,true,1',
            'next_due_date' => 'nullable|date|required_if:is_recurring,true,1',
            'receipt_attachment' => 'nullable|file|max:2048',
        ]);

        DB::connection('tenant')->transaction(function () use ($expense, $validated, $request) {
            $expense->update([
                'title' => $validated['title'],
                'expense_category_id' => $validated['expense_category_id'],
                'amount' => $validated['amount'],
                'transaction_date' => $validated['transaction_date'],
                'payment_method' => $validated['payment_method'],
                'vendor_name' => $validated['vendor_name'] ?? null,
                'reference_no' => $validated['reference_no'] ?? null,
                'description' => $validated['description'],
                'is_recurring' => $validated['is_recurring'] ?? false,
                'recurring_frequency' => $validated['recurring_frequency'] ?? null,
                'next_due_date' => $validated['next_due_date'] ?? null,
            ]);

            // Re-check budget
            $budgetResult = $this->governanceService->checkBudget($expense);
            $expense->update(['is_over_budget' => !$budgetResult['allowed']]);

            if ($request->hasFile('receipt_attachment')) {
                $path = $request->file('receipt_attachment')->store('expenses/receipts', 'public');
                ExpenseAttachment::create([
                    'expense_id' => $expense->id,
                    'file_path' => $path,
                    'file_name' => $request->file('receipt_attachment')->getClientOriginalName(),
                    'file_type' => $request->file('receipt_attachment')->getClientMimeType(),
                    'file_size' => $request->file('receipt_attachment')->getSize(),
                    'uploaded_by' => Auth::id(),
                    'created_by' => Auth::id(),
                ]);
            }
        });

        $expense->refresh()->load(['category', 'paymentAccount', 'creator', 'approver', 'payer', 'attachments']);

        return Response::json([
            'message' => 'Expense updated successfully.',
            'data' => new ExpenseResource($expense),
            'code' => 200,
        ]);
    }

    /**
     * GET /expenses/stats
     */
    public function stats(Request $request): JsonResponse
    {
        $branchId = $request->header('X-Acting-Branch-Id');
        $startOfMonth = Carbon::now()->startOfMonth();

        $query = Expense::query();
        if ($branchId) {
            $query->where('branch_id', (int) $branchId);
        }

        $totalSpent = (float) (clone $query)->where('status', ExpenseStatus::Paid->value)
            ->where('transaction_date', '>=', $startOfMonth)
            ->sum('amount');

        $pendingApprovals = (int) (clone $query)->where('status', ExpenseStatus::Pending->value)->count();

        // Real Budget Data
        $fiscalYear = Carbon::now()->format('Y');
        $periodCode = Carbon::now()->format('Y-m');

        $totalBudget = DB::connection('tenant')->table('expense_budgets')
            ->where('fiscal_year', $fiscalYear)
            ->where(function($q) use ($periodCode, $branchId) {
                $q->where('period_code', $periodCode)
                  ->orWhereNull('period_code');
            })
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->sum('allocated_amount');

        $totalSpentOnBudgets = DB::connection('tenant')->table('expense_budgets')
            ->where('fiscal_year', $fiscalYear)
            ->where(function($q) use ($periodCode) {
                $q->where('period_code', $periodCode)
                  ->orWhereNull('period_code');
            })
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->sum('spent_amount');

        $budgetUtilisationPct = $totalBudget > 0 ? round(($totalSpentOnBudgets / $totalBudget) * 100, 1) : 0;

        return Response::json([
            'payload' => [
                'total_spent' => $totalSpent,
                'pending_approvals' => $pendingApprovals,
                'budget_remaining' => max(0, $totalBudget - $totalSpentOnBudgets),
                'budget_utilisation_pct' => $budgetUtilisationPct,
                'unreconciled_amount' => 0, // Phase 14 feature
            ]
        ]);
    }

    /**
     * PATCH /expenses/{id}/approve
     */
    public function approve(int $id, Request $request): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        if (!in_array($expense->status, [ExpenseStatus::Pending->value, ExpenseStatus::Submitted->value])) {
            return Response::json(['message' => 'Only pending/submitted expenses can be approved.'], 422);
        }

        // Segregation of Duties — admins may approve their own expenses
        $user = Auth::user();
        $isAdmin = $user instanceof Staff && BranchContext::scopeFor($user) === BranchContext::SCOPE_ALL;

        if (!$isAdmin && $expense->created_by === Auth::id()) {
            return Response::json(['message' => 'You cannot approve an expense you created.'], 403);
        }

        $message = DB::connection('tenant')->transaction(function () use ($expense, $request) {
            $nextThreshold = $this->governanceService->determineNextApprover($expense);

            // Record action
            $this->governanceService->recordAction($expense, (int) Auth::id(), 'approved', $request->input('comments'));

            if ($nextThreshold) {
                // Still more levels to go
                $expense->update([
                    'status' => ExpenseStatus::Pending->value,
                    'current_approval_level' => $nextThreshold->level,
                ]);
                return "Expense approved at level {$nextThreshold->level}. Awaiting next approver.";
            } else {
                // Fully approved
                $expense->update([
                    'status' => ExpenseStatus::Approved->value,
                    'approved_by' => Auth::id(),
                    'current_approval_level' => 99, // Marker for final approval
                ]);
                return 'Expense fully approved.';
            }
        });

        return Response::json(['message' => $message, 'data' => new ExpenseResource($expense->fresh(['category', 'paymentAccount', 'creator', 'approver', 'payer', 'attachments', 'approvalHistory.approver']))]);
    }

    /**
     * POST /expenses/{id}/reject
     */
    public function reject(int $id, Request $request): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        if (!in_array($expense->status, [ExpenseStatus::Pending->value, ExpenseStatus::Submitted->value])) {
            return Response::json(['message' => 'Only pending/submitted expenses can be rejected.'], 422);
        }

        $request->validate([
            'comments' => 'required|string|max:500'
        ]);

        DB::connection('tenant')->transaction(function () use ($expense, $request) {
            $this->governanceService->recordAction($expense, (int) Auth::id(), 'rejected', $request->input('comments'));

            $expense->update([
                'status' => ExpenseStatus::Rejected->value,
            ]);
        });

        return Response::json(['message' => 'Expense rejected.', 'data' => new ExpenseResource($expense->fresh())]);
    }

    /**
     * POST /expenses/{id}/query
     */
    public function query(int $id, Request $request): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        if (!in_array($expense->status, [ExpenseStatus::Pending->value, ExpenseStatus::Submitted->value])) {
            return Response::json(['message' => 'Only pending/submitted expenses can be queried.'], 422);
        }

        $request->validate([
            'comments' => 'required|string|max:500'
        ]);

        DB::connection('tenant')->transaction(function () use ($expense, $request) {
            $this->governanceService->recordAction($expense, (int) Auth::id(), 'queried', $request->input('comments'));

            $expense->update([
                'status' => ExpenseStatus::Draft->value,
            ]);
        });

        return Response::json(['message' => 'Expense queried and returned to draft.', 'data' => new ExpenseResource($expense->fresh())]);
    }

    /**
     * PATCH /expenses/{id}/pay
     */
    public function pay(int $id, Request $request): JsonResponse
    {
        $expense = Expense::findOrFail($id);

        if ($expense->status !== ExpenseStatus::Approved->value) {
            return Response::json(['message' => 'Only approved expenses can be paid.'], 422);
        }

        $validated = $request->validate([
            'chart_of_account_id' => 'required|exists:tenant.chart_of_accounts,id',
            'payment_date' => 'required|date',
            'reference_no' => 'nullable|string',
        ]);

        $periodCode = Carbon::parse($validated['payment_date'])->format('Y-m');
        $isLocked = DB::connection('tenant')->table('accounting_periods')
            ->where('period_code', $periodCode)
            ->where('is_locked', true)
            ->exists();

        if ($isLocked) {
            return Response::json(['message' => "Cannot post to closed period {$periodCode}."], 422);
        }

        $result = DB::connection('tenant')->transaction(function () use ($expense, $validated) {
            $expense->update([
                'status' => ExpenseStatus::Paid->value,
                'chart_of_account_id' => $validated['chart_of_account_id'],
                'transaction_date' => $validated['payment_date'],
                'reference_no' => $validated['reference_no'] ?? $expense->reference_no,
                'paid_by' => Auth::id(),
            ]);

            $expense->refresh()->loadMissing(['category', 'paymentAccount']);
            $journalEntry = $this->accountingService->postExpense($expense, (int) Auth::id());
            
            return $journalEntry;
        });

        return Response::json([
            'message' => 'Expense paid successfully.',
            'data' => new ExpenseResource($expense->fresh(['category', 'paymentAccount', 'creator', 'approver', 'payer', 'attachments'])),
            'journal_entry_id' => $result->id,
            'code' => 200,
        ]);
    }
}
