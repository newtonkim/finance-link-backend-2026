<?php

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Tenant\Modules\Expenses\Models\Expense;
use App\Tenant\Modules\Expenses\Models\ExpenseCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$switcher = $app->make(DatabaseSwitcher::class);
$tenant = Tenant::where('subdomain', 'wazalendosacco')->first();
$switcher->switch($tenant);

echo "Connected to: " . $tenant->name . "\n";

$category = ExpenseCategory::first();
echo "Using Category: " . $category->name . " (ID: " . $category->id . ")\n";

// Setup Budget
DB::table('expense_budgets')->updateOrInsert(
    [
        'expense_category_id' => $category->id,
        'fiscal_year' => '2026',
        'period_code' => '2026-05',
    ],
    [
        'allocated_amount' => 1000000,
        'spent_amount' => 0,
        'created_at' => now(),
    ]
);
echo "Budget of 1,000,000 set for May 2026.\n";

// Verify Thresholds
$thresholds = DB::table('expense_approval_thresholds')->count();
echo "Thresholds in table: " . $thresholds . "\n";

$switcher->purge();
