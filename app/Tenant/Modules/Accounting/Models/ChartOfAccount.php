<?php

namespace App\Tenant\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChartOfAccount extends Model
{
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'gl_code',
        'name',
        'account_subtype',
        'account_type',
        'normal_balance',
        'level',
        'parent_id',
        'is_control',
        'is_postable',
        'is_active',
        'allow_manual',
        'ifrs_category',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_control' => 'boolean',
        'is_postable' => 'boolean',
        'allow_manual' => 'boolean',
        'level' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Self-referencing parent account.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_id');
    }

    /**
     * Self-referencing child accounts.
     */
    public function children(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_id');
    }

    /**
     * All journal entry lines posted to this account.
     */
    public function journalEntryLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }

    /**
     * All general ledger entries for this account.
     */
    public function generalLedgerEntries(): HasMany
    {
        return $this->hasMany(GeneralLedger::class, 'account_id');
    }

    /**
     * All sub ledger entries for this account.
     */
    public function subLedgerEntries(): HasMany
    {
        return $this->hasMany(SubLedger::class, 'account_id');
    }
}
