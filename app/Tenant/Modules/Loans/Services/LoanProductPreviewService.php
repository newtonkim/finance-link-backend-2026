<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Contracts\ScheduleGeneratorServiceInterface;
use App\Tenant\Support\TenantMoney;

class LoanProductPreviewService
{
    public function __construct(
        protected ScheduleGeneratorServiceInterface $generator,
    ) {}

    public function preview(array $data): array
    {
        $principal = round((float) ($data['preview_amount'] ?? $data['min_amount'] ?? 0), 2);
        $term = (int) ($data['preview_term'] ?? $data['loan_duration'] ?? 0);

        if ($principal <= 0 || $term <= 0) {
            return [
                'installment_amount' => 0,
                'installment_amount_formatted' => TenantMoney::format(0),
                'total_interest' => 0,
                'total_interest_formatted' => TenantMoney::format(0),
                'total_repayment' => 0,
                'total_repayment_formatted' => TenantMoney::format(0),
                'schedule_preview' => [],
                'messages' => ['Provide a preview amount and term to generate a sample repayment plan.'],
                'assumptions' => [],
                'currency_code' => TenantMoney::code(),
            ];
        }

        $result = $this->generator->generate(
            $principal,
            $term,
            (string) ($data['interest_method'] ?? 'flat'),
            (string) ($data['repayment_structure'] ?? 'equal_installment'),
            (float) ($data['interest_rate'] ?? 0),
            (string) ($data['interest_period'] ?? 'monthly'),
            (string) ($data['repayment_cycle'] ?? 'monthly'),
        );

        return [
            'installment_amount' => $result['installment_amount'],
            'installment_amount_formatted' => TenantMoney::format($result['installment_amount']),
            'total_interest' => $result['total_interest'],
            'total_interest_formatted' => TenantMoney::format($result['total_interest']),
            'total_repayment' => round($principal + $result['total_interest'], 2),
            'total_repayment_formatted' => TenantMoney::format(round($principal + $result['total_interest'], 2)),
            'schedule_preview' => $this->withFormattedRows($result['rows']),
            'messages' => $result['messages'],
            'assumptions' => $result['assumptions'],
            'currency_code' => TenantMoney::code(),
        ];
    }

    private function withFormattedRows(array $rows): array
    {
        return array_map(fn (array $row): array => array_merge($row, [
            'principal_formatted' => TenantMoney::format($row['principal'] ?? null),
            'interest_formatted' => TenantMoney::format($row['interest'] ?? null),
            'installment_formatted' => TenantMoney::format($row['installment'] ?? null),
            'balance_formatted' => TenantMoney::format($row['balance'] ?? null),
        ]), $rows);
    }
}
