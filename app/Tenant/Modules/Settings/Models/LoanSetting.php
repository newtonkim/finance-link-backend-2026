<?php

namespace App\Tenant\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

class LoanSetting extends Model
{
    protected $connection = 'tenant';

    protected $table = 'loan_settings';

    protected $fillable = [
        'system_type',
        'branch_id',
        'min_approvers',
        'max_approvers',
        'member_id',
        'guarantor_mode',
        'loan_id',
        'holiday_skip_mode',
        'guarantor_id',
        'public_holiday_id',
        'allow_top_up',
        'allow_reschedule',
        'max_reschedule_count',
        'loan_reschedule_id',
        'auto_penalty',
        'penalty_grace_days',
        'notification_channels',
        'loan_cycle_limit',
        'charge_deduction_mode',
        'repayment_allocation_order',
        'push_installments_on_holidays',
        'push_installments_on_holidays_weekdays_only',
        'relative_scheduling',
        'topup_repayment_basis',
        'topup_min_percentage',
        'topup_auto_disbursement',
        'reschedule_fee_income_account_id',
        'reschedule_fee_enabled',
        'reschedule_fee_type',
        'reschedule_fee_amount',
        'reschedule_fee_basis',
        'reschedule_fee_collection',
        'reschedule_product_change_fee_enabled',
        'reschedule_product_change_fee_type',
        'reschedule_product_change_fee_amount',
        'reschedule_product_change_fee_basis',
        'reschedule_product_change_fee_collection',
        'reschedule_same_product_fee_enabled',
        'reschedule_same_product_fee_type',
        'reschedule_same_product_fee_amount',
        'reschedule_same_product_fee_basis',
        'reschedule_same_product_fee_collection',
        'reschedule_other_charges_enabled',
        'reschedule_other_charges_type',
        'reschedule_other_charges_amount',
        'reschedule_other_charges_basis',
        'reschedule_other_charges_collection',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'allow_top_up' => 'boolean',
        'allow_reschedule' => 'boolean',
        'auto_penalty' => 'boolean',
        'push_installments_on_holidays' => 'boolean',
        'push_installments_on_holidays_weekdays_only' => 'boolean',
        'relative_scheduling' => 'boolean',
        'min_approvers' => 'integer',
        'max_approvers' => 'integer',
        'penalty_grace_days' => 'integer',
        'loan_cycle_limit' => 'integer',
        'max_reschedule_count' => 'integer',
        'topup_min_percentage' => 'decimal:2',
        'topup_auto_disbursement' => 'boolean',
        'reschedule_fee_income_account_id' => 'integer',
        'reschedule_fee_enabled' => 'boolean',
        'reschedule_fee_amount' => 'decimal:4',
        'reschedule_product_change_fee_enabled' => 'boolean',
        'reschedule_product_change_fee_amount' => 'decimal:4',
        'reschedule_same_product_fee_enabled' => 'boolean',
        'reschedule_same_product_fee_amount' => 'decimal:4',
        'reschedule_other_charges_enabled' => 'boolean',
        'reschedule_other_charges_amount' => 'decimal:4',
    ];

    /** Always return the single row for a branch, creating it with defaults if it does not exist. */
    public static function currentForBranch(int $branchId): self
    {
        $defaults = [
            'system_type' => 'system',
            'min_approvers' => 1,
            'max_approvers' => 3,
            'allow_top_up' => true,
            'allow_reschedule' => true,
            'auto_penalty' => true,
            'penalty_grace_days' => 0,
            'loan_cycle_limit' => 1,
            'max_reschedule_count' => 3,
            'charge_deduction_mode' => 'deduct_from_principal',
            // Default kept to current behavior for backward compatibility.
            'repayment_allocation_order' => 'penalties_charges_interest_principal',
            'push_installments_on_holidays' => false,
            'push_installments_on_holidays_weekdays_only' => false,
            'relative_scheduling' => false,
            'topup_repayment_basis' => 'principal_interest',
            'topup_min_percentage' => 40.00,
            'topup_auto_disbursement' => false,
            'reschedule_fee_enabled' => false,
            'reschedule_fee_type' => 'flat',
            'reschedule_fee_amount' => 0,
            'reschedule_fee_collection' => 'cash',
            'reschedule_product_change_fee_enabled' => false,
            'reschedule_product_change_fee_type' => 'flat',
            'reschedule_product_change_fee_amount' => 0,
            'reschedule_product_change_fee_collection' => 'cash',
            'reschedule_same_product_fee_enabled' => false,
            'reschedule_same_product_fee_type' => 'flat',
            'reschedule_same_product_fee_amount' => 0,
            'reschedule_same_product_fee_collection' => 'cash',
            'reschedule_other_charges_enabled' => false,
            'reschedule_other_charges_type' => 'flat',
            'reschedule_other_charges_amount' => 0,
            'reschedule_other_charges_collection' => 'cash',
        ];

        try {
            return self::firstOrCreate(
                ['branch_id' => $branchId],
                $defaults,
            );
        } catch (QueryException $e) {
            if (
                ! self::isRepaymentAllocationOrderMissingColumnError($e)
                && ! self::isHolidaySchedulingMissingColumnError($e)
            ) {
                throw $e;
            }

            // Backward compatibility for tenants not yet migrated with newer settings columns.
            unset(
                $defaults['repayment_allocation_order'],
                $defaults['push_installments_on_holidays_weekdays_only'],
                $defaults['relative_scheduling'],
            );

            return self::firstOrCreate(
                ['branch_id' => $branchId],
                $defaults,
            );
        }
    }

    public static function isRepaymentAllocationOrderMissingColumnError(QueryException $e): bool
    {
        return self::isMissingColumnError($e, ['repayment_allocation_order']);
    }

    public static function isHolidaySchedulingMissingColumnError(QueryException $e): bool
    {
        return self::isMissingColumnError($e, [
            'push_installments_on_holidays_weekdays_only',
            'relative_scheduling',
        ]);
    }

    private static function isMissingColumnError(QueryException $e, array $columns): bool
    {
        $message = strtolower($e->getMessage());

        if (! str_contains($message, 'unknown column')) {
            return false;
        }

        foreach ($columns as $column) {
            if (str_contains($message, strtolower($column))) {
                return true;
            }
        }

        return false;
    }
}
