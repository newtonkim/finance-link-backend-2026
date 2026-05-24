<?php

namespace Tests\Unit;

use App\Tenant\Modules\Loans\Contracts\LoanProductServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class LoanProductServiceInterfaceTest extends TestCase
{
    public function test_interface_exists(): void
    {
        $this->assertTrue(interface_exists(LoanProductServiceInterface::class));
    }

    public function test_interface_declares_list_method(): void
    {
        $method = new ReflectionMethod(LoanProductServiceInterface::class, 'list');

        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('filters', $params[0]->getName());
        $this->assertEquals('perPage', $params[1]->getName());
        $this->assertEquals('array', $params[0]->getType()->getName());
        $this->assertEquals('int', $params[1]->getType()->getName());
        $this->assertTrue($params[0]->isDefaultValueAvailable());
        $this->assertEquals([], $params[0]->getDefaultValue());
        $this->assertTrue($params[1]->isDefaultValueAvailable());
        $this->assertEquals(15, $params[1]->getDefaultValue());
    }

    public function test_interface_declares_create_method(): void
    {
        $method = new ReflectionMethod(LoanProductServiceInterface::class, 'create');

        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('data', $params[0]->getName());
        $this->assertEquals('array', $params[0]->getType()->getName());
    }

    public function test_interface_declares_update_method(): void
    {
        $method = new ReflectionMethod(LoanProductServiceInterface::class, 'update');

        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertEquals('product', $params[0]->getName());
        $this->assertEquals('data', $params[1]->getName());
        $this->assertEquals(LoanProduct::class, $params[0]->getType()->getName());
        $this->assertEquals('array', $params[1]->getType()->getName());
    }

    public function test_interface_declares_delete_method(): void
    {
        $method = new ReflectionMethod(LoanProductServiceInterface::class, 'delete');

        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertEquals('product', $params[0]->getName());
        $this->assertEquals(LoanProduct::class, $params[0]->getType()->getName());
    }

    public function test_concrete_class_can_implement_interface(): void
    {
        $stub = new class implements LoanProductServiceInterface
        {
            public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
            {
                return new LengthAwarePaginator([], 0, $perPage);
            }

            public function create(array $data): LoanProduct
            {
                return new LoanProduct;
            }

            public function update(LoanProduct $product, array $data): bool
            {
                return true;
            }

            public function delete(LoanProduct $product): ?bool
            {
                return true;
            }
        };

        $this->assertInstanceOf(LoanProductServiceInterface::class, $stub);
    }
}
