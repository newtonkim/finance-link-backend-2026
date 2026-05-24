<?php

namespace Tests\Unit;

use App\Tenant\Modules\Accounting\GlCodes;
use PHPUnit\Framework\TestCase;

class GlCodesTest extends TestCase
{
    public function test_all_constants_are_five_digits(): void
    {
        $reflection = new \ReflectionClass(GlCodes::class);
        foreach ($reflection->getConstants() as $name => $value) {
            $this->assertMatchesRegularExpression(
                '/^\d{5}$/',
                $value,
                "GlCodes::{$name} = '{$value}' is not a 5-digit string"
            );
        }
    }

    public function test_constants_are_unique(): void
    {
        $reflection = new \ReflectionClass(GlCodes::class);
        $values = array_values($reflection->getConstants());
        $this->assertCount(count(array_unique($values)), $values, 'Duplicate GL code constant values found');
    }
}
