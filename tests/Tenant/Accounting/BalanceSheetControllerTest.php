<?php

use App\Tenant\Http\Controllers\Api\V1\BalanceSheetController;
use App\Tenant\Modules\Accounting\Contracts\BalanceSheetServiceInterface;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

function bsCall(array $query): array
{
    $response = app(BalanceSheetController::class)->index(Request::create('/reports/balance-sheet', 'GET', $query));

    return $response->getData(true);
}

it('rejects a comparison date after the as-at date', function () {
    bsCall(['as_at' => '2026-01-31', 'compare_to' => '2026-02-01']);
})->throws(ValidationException::class);

it('rejects an invalid date', function () {
    bsCall(['as_at' => 'not-a-date']);
})->throws(ValidationException::class);

it('accepts compare_to without as_at by defaulting as_at to today', function () {
    $data = bsCall(['compare_to' => Carbon::today()->subYear()->toDateString()]);

    expect($data['as_at'])->toBe(Carbon::today()->toDateString());
});

it('passes parsed params to the service and rounds line amounts', function () {
    $fake = Mockery::mock(BalanceSheetServiceInterface::class);
    $fake->shouldReceive('generate')
        ->withArgs(fn (Carbon $asAt, ?Carbon $compareTo, bool $hideZero) => $asAt->toDateString() === '2026-09-30'
            && $compareTo?->toDateString() === '2025-12-31'
            && $hideZero === false)
        ->once()
        ->andReturn([
            'as_at' => '2026-09-30', 'compare_to' => '2025-12-31', 'financial_year' => null,
            'generated_at' => 'x', 'totals' => ['current' => [], 'compare' => []],
            'sections' => [[
                'key' => 'assets', 'label' => 'Assets', 'total' => 1.0, 'compare_total' => 0.0,
                'lines' => [[
                    'id' => 1, 'gl_code' => '11000', 'name' => 'Current Assets', 'level' => 2,
                    'is_postable' => false, 'is_computed' => false,
                    'amount' => 1.004999, 'compare_amount' => 0.0,
                    'children' => [[
                        'id' => 2, 'gl_code' => '11101', 'name' => 'Cash', 'level' => 4,
                        'is_postable' => true, 'is_computed' => false,
                        'amount' => 1.004999, 'compare_amount' => 0.0, 'children' => [],
                    ]],
                ]],
            ]],
        ]);
    app()->instance(BalanceSheetServiceInterface::class, $fake);

    $data = bsCall(['as_at' => '2026-09-30', 'compare_to' => '2025-12-31', 'hide_zero' => '0']);

    expect($data['sections'][0]['lines'][0]['amount'])->toBe(1);
    expect($data['sections'][0]['lines'][0]['children'][0]['amount'])->toBe(1);
    expect($data['sections'][0]['lines'][0]['children'][0]['gl_code'])->toBe('11101');
});
