<?php
require 'vendor/autoload.php';

$classes = [
    'Illuminate\Support\Facades\DB',
    'Illuminate\Support\Facades\Config',
    'App\Models\Staff',
    'App\Domain\Tenancy\Entities\Tenant',
    'App\Tenant\Modules\Accounting\Models\ChartOfAccount',
];

foreach ($classes as $class) {
    if (class_exists($class)) {
        echo "OK: $class exists\n";
    } else {
        echo "FAIL: $class DOES NOT exist\n";
    }
}
