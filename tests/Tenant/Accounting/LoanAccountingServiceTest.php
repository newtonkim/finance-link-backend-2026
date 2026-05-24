<?php

use App\Tenant\Modules\Accounting\Services\JournalSequenceService;

it('generates unique entry numbers under sequential calls', function () {
    $service = app(JournalSequenceService::class);
    $numbers = [];
    for ($i = 0; $i < 20; $i++) {
        $numbers[] = $service->nextEntryNo('LOAN_REPAY');
    }
    expect(array_unique($numbers))->toHaveCount(20);
});

it('entry number includes the type code', function () {
    $service = app(JournalSequenceService::class);
    $no = $service->nextEntryNo('LOAN_DISB');
    expect($no)->toContain('LOAN_DISB');
});
