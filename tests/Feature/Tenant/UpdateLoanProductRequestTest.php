<?php

use App\Http\Requests\Tenant\UpdateLoanProductRequest;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;

it('allows updating a loan product with its existing name and code', function () {
    $product = LoanProduct::create([
        'name' => 'Existing Product',
        'code' => 'EXIST-1',
        'is_active' => true,
    ]);

    $request = UpdateLoanProductRequest::create("/api/v1/tenant/loan-products/{$product->id}", 'PUT', [
        'name' => 'Existing Product',
        'code' => 'EXIST-1',
        'is_active' => false,
    ]);

    $request->setRouteResolver(function () use ($product) {
        $route = new Route('PUT', '/api/v1/tenant/loan-products/{loanProduct}', []);
        
        // Manual parameter injection for the request's route
        $ref = new \ReflectionProperty($route, 'parameters');
        $ref->setAccessible(true);
        $ref->setValue($route, ['loanProduct' => $product]);

        return $route;
    });

    $validator = Validator::make($request->all(), $request->rules());

    expect($validator->fails())->toBeFalse();
});
