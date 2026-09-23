<?php

namespace Tests\Unit;

use App\Central\Models\Plan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlanFeatureTest extends TestCase
{
    public static function storedFeatures(): array
    {
        return [
            'normal enabled map' => [json_encode(['reports' => true]), true],
            'legacy double encoded enabled map' => [json_encode(json_encode(['reports' => true])), true],
            'normal disabled map' => [json_encode(['reports' => false]), false],
            'legacy disabled map' => [json_encode(json_encode(['reports' => false])), false],
            'missing reports' => [json_encode(['loans' => true]), false],
            'text false does not grant access' => [json_encode(['reports' => 'false']), false],
            'invalid legacy json' => [json_encode('invalid'), false],
            'null features' => [null, false],
        ];
    }

    #[DataProvider('storedFeatures')]
    public function test_plan_feature_access(?string $stored, bool $enabled): void
    {
        $plan = new Plan;
        $plan->setRawAttributes(['features' => $stored]);
        self::assertSame($enabled, $plan->hasFeature('reports'));
    }

    public function test_array_assignment_is_stored_as_a_json_object_and_enables_reports(): void
    {
        $plan = new Plan(['features' => ['reports' => true]]);
        self::assertSame(['reports' => true], json_decode($plan->getAttributes()['features'], true));
        self::assertTrue($plan->hasFeature('reports'));
    }
}
