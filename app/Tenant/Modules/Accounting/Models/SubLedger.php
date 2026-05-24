<?php

namespace App\Tenant\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SubLedger extends Model
{
    protected $connection = 'tenant';

    protected $table = 'sub_ledger';

    protected $fillable = [
        'account_id',
        'entity_id',
        'entity_type',
        'journal_entry_id',
        'date',
        'debit',
        'credit',
        'balance',
        'narration',
    ];

    protected $casts = [
        'date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Get the polymorphic entity (Member, Loan, SavingsAccount, etc.)
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }
}
