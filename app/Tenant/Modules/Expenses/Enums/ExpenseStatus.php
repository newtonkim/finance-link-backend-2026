<?php

namespace App\Tenant\Modules\Expenses\Enums;

enum ExpenseStatus: string
{
    case Draft = 'Draft';
    case Submitted = 'Submitted';
    case Pending = 'Pending';
    case Queried = 'Queried';
    case Approved = 'Approved';
    case Paid = 'Paid';
    case Reconciled = 'Reconciled';
    case Rejected = 'Rejected';
    case Void = 'Void';

    public function label(): string
    {
        return $this->value;
    }
}
