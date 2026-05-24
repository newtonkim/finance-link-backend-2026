<?php

use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Simulate a request
$request = Request::create('http://wazalendosacco.localhost:8000/api/v1/tenant/staff/1', 'GET');
$request->headers->set('Accept', 'application/json');

$response = $kernel->handle($request);

echo "Status: " . $response->getStatusCode() . "\n";
echo "Content: " . substr($response->getContent(), 0, 500) . "...\n";

if ($response->getStatusCode() == 500) {
    // If it's 500, let's see if we can find the error in the app container if it was caught
    // Or just check logs again.
}
