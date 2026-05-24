<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$accounts = \App\Tenant\Modules\Accounting\Models\ChartOfAccount::all();
echo "Total Accounts: " . $accounts->count() . "\n";
$filtered = $accounts->filter(function($a) {
    return $a->is_active && $a->is_postable && $a->allow_manual;
});
echo "Filtered Accounts: " . $filtered->count() . "\n";
if ($filtered->count() > 0) {
    echo "Sample: " . $filtered->first()->name . "\n";
} else if ($accounts->count() > 0) {
    echo "Why filtered out?\n";
    $first = $accounts->first();
    echo "is_active: " . $first->is_active . ", is_postable: " . $first->is_postable . ", allow_manual: " . $first->allow_manual . "\n";
}
