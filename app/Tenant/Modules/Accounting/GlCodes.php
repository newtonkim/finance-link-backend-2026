<?php

namespace App\Tenant\Modules\Accounting;

final class GlCodes
{
    // Cash & equivalents
    const PETTY_CASH = '11101';

    const BANK_OPERATING = '11102';

    const BANK_LOAN_DISBURSEMENT = '11103';

    const MOBILE_MONEY_MTN = '11104';

    const MOBILE_MONEY_AIRTEL = '11105';

    // Member savings liability
    const SAVINGS_MANDATORY = '21101';

    const SAVINGS_VOLUNTARY = '21102';

    const SAVINGS_FIXED_DEPOSIT = '21103';

    // Equity
    const SHARE_CAPITAL_ORDINARY = '31100';

    const OPENING_BALANCE_CONTROL = '33900';

    // Fee income
    const FEE_LOAN_PROCESSING = '42200';

    const FEE_ACCOUNT_MAINTENANCE = '42300';

    // Expenses
    const EXPENSE_LOAN_WRITE_OFF = '51304';
}
