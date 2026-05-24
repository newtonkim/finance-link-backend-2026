<?php

namespace Tests\Feature\Tenant;

use App\Http\Requests\Tenant\StoreLoanProductRequest;
use App\Http\Requests\Tenant\UpdateLoanProductRequest;
use App\Tenant\Http\Controllers\Api\V1\LoanProductController;
use App\Tenant\Http\Resources\LoanProductResource;
use App\Tenant\Modules\Loans\Contracts\LoanProductServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Services\LoanProductGuardService;
use App\Tenant\Modules\Loans\Services\LoanProductPreviewService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;
use Tests\TenantTestCase;

class LoanProductControllerTest extends TenantTestCase
{
    private LoanProductServiceInterface $service;

    private LoanProductGuardService $guard;

    private LoanProductPreviewService $preview;

    private LoanProductController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = Mockery::mock(LoanProductServiceInterface::class);
        $this->guard = Mockery::mock(LoanProductGuardService::class);
        $this->preview = Mockery::mock(LoanProductPreviewService::class);
        $this->controller = new LoanProductController($this->service, $this->guard, $this->preview);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_controller_constructor_accepts_interface(): void
    {
        $this->assertInstanceOf(LoanProductController::class, $this->controller);
    }

    public function test_index_returns_resource_collection(): void
    {
        $product = new LoanProduct;
        $product->id = 1;
        $paginator = new LengthAwarePaginator([$product], 1, 15);

        $this->service
            ->shouldReceive('list')
            ->once()
            ->with([])
            ->andReturn($paginator);

        $request = Request::create('/api/v1/tenant/loan-products', 'GET');
        $response = $this->controller->index($request);

        $this->assertEquals(200, $response->toResponse($request)->getStatusCode());
    }

    public function test_index_passes_filters_to_service(): void
    {
        $paginator = new LengthAwarePaginator([], 0, 15);

        $this->service
            ->shouldReceive('list')
            ->once()
            ->with(['search' => 'personal', 'is_active' => '1'])
            ->andReturn($paginator);

        $request = Request::create('/api/v1/tenant/loan-products?search=personal&is_active=1', 'GET');
        $response = $this->controller->index($request);

        $this->assertEquals(200, $response->toResponse($request)->getStatusCode());
    }

    public function test_show_loads_penalty_rules_and_returns_resource(): void
    {
        $product = LoanProduct::create(['name' => 'Show Test', 'is_active' => true]);

        $this->guard->shouldReceive('isInUse')->once()->andReturn(false);
        $this->guard->shouldReceive('canEditCoreFields')->once()->andReturn(true);

        $response = $this->controller->show($product);

        $this->assertInstanceOf(LoanProductResource::class, $response);
        $this->assertTrue($product->relationLoaded('penaltyRules'));
    }

    public function test_store_calls_service_create_and_returns_201(): void
    {
        $product = LoanProduct::create(['name' => 'New Loan', 'is_active' => true]);

        $this->service
            ->shouldReceive('create')
            ->once()
            ->andReturn($product);

        $this->guard->shouldReceive('isInUse')->once()->andReturn(false);
        $this->guard->shouldReceive('canEditCoreFields')->once()->andReturn(true);

        $request = Mockery::mock(StoreLoanProductRequest::class)->makePartial();
        $request->shouldReceive('validated')->andReturn(['name' => 'New Loan', 'is_active' => true]);

        $response = $this->controller->store($request);

        $this->assertEquals(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Loan Product Created Successfully.', $data['message']);
    }

    public function test_update_calls_service_update_and_returns_200(): void
    {
        $product = LoanProduct::create(['name' => 'Update Test', 'is_active' => true]);

        $this->service
            ->shouldReceive('update')
            ->once()
            ->with(Mockery::type(LoanProduct::class), Mockery::type('array'))
            ->andReturn(true);

        $this->guard->shouldReceive('isInUse')->once()->andReturn(false);
        $this->guard->shouldReceive('canEditCoreFields')->once()->andReturn(true);

        $request = Mockery::mock(UpdateLoanProductRequest::class)->makePartial();
        $request->shouldReceive('validated')->andReturn(['name' => 'Updated Loan', 'is_active' => true]);

        $response = $this->controller->update($request, $product);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Loan Product Updated Successfully.', $data['message']);
    }

    public function test_destroy_calls_service_delete_and_returns_200(): void
    {
        $product = LoanProduct::create(['name' => 'Delete Test', 'is_active' => true]);

        $this->service
            ->shouldReceive('delete')
            ->once()
            ->with(Mockery::type(LoanProduct::class));

        $response = $this->controller->destroy($product);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Loan Product Deleted Successfully.', $data['message']);
    }
}
