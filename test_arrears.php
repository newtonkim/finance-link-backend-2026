<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Switch to the first tenant manually
$tenant = Tenant::first();
if ($tenant) {
    app(DatabaseSwitcher::class)->switch($tenant);

    $loan = Loan::where('loan_no', 'LN-20260409-00001')->first();
    if ($loan) {
        $schedules = LoanSchedule::where('loan_id', $loan->id)->get();
        echo "Loan Found!\n";
        echo 'Outstanding Balance: '.$loan->outstanding_balance."\n";

        $tiers = DB::connection('tenant')->table('loan_arrears_tiers')->get();
        echo "\nTiers:\n";
        print_r($tiers->toArray());
    } else {
        echo "Loan not found.\n";
    }
} else {
    echo "No tenant found.\n";
}
