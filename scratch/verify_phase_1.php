<?php

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Tenant\Modules\Expenses\Models\Expense;
use App\Tenant\Modules\Expenses\Models\ExpenseCategory;
use App\Tenant\Modules\Expenses\Enums\ExpenseStatus;
use App\Tenant\Modules\Expenses\Services\ExpenseGovernanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\Staff;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$switcher = $app->make(DatabaseSwitcher::class);
$tenant = Tenant::where('subdomain', 'wazalendosacco')->first();
$switcher->switch($tenant);

$governanceService = $app->make(ExpenseGovernanceService::class);

echo "--- PHASE 1 VERIFICATION ---\n";

// 1. Create a mock requester
$requester = Staff::first();
Auth::login($requester);
echo "Logged in as Requester: " . $requester->first_name . " (ID: " . $requester->id . ")\n";

// 2. Create an expense that requires Level 2 approval (1,500,000)
$category = ExpenseCategory::first();
$amount = 1500000;

echo "Creating expense for " . number_format($amount) . " in category " . $category->name . "...\n";

$expense = Expense::create([
    'title' => 'Test Large Expense',
    'expense_category_id' => $category->id,
    'amount' => $amount,
    'transaction_date' => '2026-05-04',
    'payment_method' => 'bank_transfer',
    'description' => 'Verifying multi-level approval',
    'status' => ExpenseStatus::Submitted->value,
    'branch_id' => 1,
    'created_by' => $requester->id,
    'type' => 'cash',
]);

echo "Expense Created. Status: " . $expense->status . "\n";

// 3. Check Budget
$budgetCheck = $governanceService->checkBudget($expense);
echo "Budget Check: " . $budgetCheck['message'] . " (Allowed: " . ($budgetCheck['allowed'] ? 'YES' : 'NO') . ")\n";

// 4. Determine Next Approver
$next = $governanceService->determineNextApprover($expense);
echo "Next Required Level: " . ($next ? $next->level . " (" . $next->required_role . ")" : 'None') . "\n";

if ($next && $next->level == 1) {
    echo "SUCCESS: Level 1 (Accountant) correctly identified as first step.\n";
}

// 5. Mock Approval by Level 1
$approver = Staff::where('id', '!=', $requester->id)->first();
if (!$approver) {
    echo "ERROR: No second staff member found for mock approval.\n";
    exit(1);
}

Auth::login($approver);
echo "Mocking approval by: " . $approver->first_name . " (ID: " . $approver->id . ")\n";

// Emulate Controller logic
$nextThreshold = $governanceService->determineNextApprover($expense);
if ($nextThreshold) {
    $expense->update([
        'status' => ExpenseStatus::Pending->value,
        'current_approval_level' => $nextThreshold->level,
    ]);
    $governanceService->recordAction($expense, $approver->id, 'approved', 'Looks good for level 1');
}

echo "Status after Level 1 Approval: " . $expense->status . " (Level: " . $expense->current_approval_level . ")\n";

// 6. Check Next Approver again
$next = $governanceService->determineNextApprover($expense);
echo "Next Required Level: " . ($next ? $next->level . " (" . $next->required_role . ")" : 'None') . "\n";

if ($next && $next->level == 2) {
    echo "SUCCESS: Level 2 (Branch Manager) correctly identified after Level 1.\n";
}

// 7. Verify History
$history = DB::table('expense_approval_history')->where('expense_id', $expense->id)->first();
if ($history) {
    echo "SUCCESS: Approval history record found: " . $history->action . " by " . $history->approver_id . "\n";
} else {
    echo "ERROR: No approval history found.\n";
}

// Cleanup
$expense->delete();
echo "Test Cleanup Done.\n";

$switcher->purge();
