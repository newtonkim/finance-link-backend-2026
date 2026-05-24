<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require __DIR__.'/settings.php';

Route::get('/{any}', function (Request $request) {
    $host = $request->getHost();
    $parts = explode('.', $host);
    $subdomain = count($parts) > 1 ? $parts[0] : null;
    $isTenant = $subdomain && ! in_array($subdomain, ['admin', 'www', 'localhost']);

    return $isTenant ? view('tenant-spa') : view('app');
})->where('any', '^(?!settings).*$');

