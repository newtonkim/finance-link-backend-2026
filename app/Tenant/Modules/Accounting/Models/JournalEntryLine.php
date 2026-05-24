<?php

namespace App\Tenant\Modules\Accounting\Models;

use App\Models\Concerns\BelongsToAuthenticatedBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalEntryLine extends Model
{
    use BelongsToAuthenticatedBranch, HasFactory;

    protected $table = 'journal_entry_lines';

    protected $guarded = ['id'];

    protected $appends = ['debit_amount', 'credit_amount', 'chart_of_account_id'];

    public function getDebitAmountAttribute()
    {
        return $this->debit;
    }

    public function getCreditAmountAttribute()
    {
        return $this->credit;
    }

    public function getChartOfAccountIdAttribute()
    {
        return $this->account_id;
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function chartOfAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
