<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\SavingsProductFormRequest;
use App\Tenant\Http\Resources\SavingsProductResource;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Services\SavingsProductService;
use Illuminate\Http\Request;

class SavingsProductController extends Controller
{
    public function __construct(
        protected SavingsProductService $service
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $products = $this->service->list($request->only('search', 'status'));

        return SavingsProductResource::collection($products);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(SavingsProductFormRequest $request)
    {
        $product = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Savings product created successfully.',
            'data' => new SavingsProductResource($product->load('charges')),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(SavingsProduct $savingsProduct)
    {
        return new SavingsProductResource($savingsProduct->load('charges'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(SavingsProductFormRequest $request, SavingsProduct $savingsProduct)
    {
        $this->service->update($savingsProduct, $request->validated());

        return response()->json([
            'message' => 'Savings product updated successfully.',
            'data' => new SavingsProductResource($savingsProduct->fresh('charges')),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SavingsProduct $savingsProduct)
    {
        $this->service->delete($savingsProduct);

        return response()->json([
            'message' => 'Savings product deleted successfully.',
        ]);
    }
}
