<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// We need to set a tenant context. Let's find the first tenant.
$tenant = \App\Models\Tenant::first();
if ($tenant) {
    tenancy()->initialize($tenant);
    $accounts = \App\Tenant\Modules\Accounting\Models\ChartOfAccount::all();
    echo "Total: " . $accounts->count() . "\n";
    $postable = $accounts->where('is_postable', true)->count();
    $active = $accounts->where('is_active', true)->count();
    $manual = $accounts->where('allow_manual', true)->count();
    
    $filtered = $accounts->filter(function($a) {
        return $a->is_active && $a->is_postable && $a->allow_manual;
    });
    
    echo "Postable: $postable\n";
    echo "Active: $active\n";
    echo "Allow Manual: $manual\n";
    echo "Fully Filtered (Active + Postable + Manual): " . $filtered->count() . "\n";
} else {
    echo "No tenant found.\n";
}
