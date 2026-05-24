<?php

namespace App\Tenant\Modules\Accounting\Models;

use App\Models\Concerns\BelongsToAuthenticatedBranch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use BelongsToAuthenticatedBranch, HasFactory;

    protected $table = 'journal_entries';

    protected $guarded = ['id'];

    protected $appends = ['total_amount', 'entry_date', 'voucher_number', 'description'];

    public function getTotalAmountAttribute()
    {
        return $this->lines()->sum('debit');
    }

    public function getEntryDateAttribute()
    {
        return $this->date;
    }

    public function getVoucherNumberAttribute()
    {
        return $this->entry_no;
    }

    public function getDescriptionAttribute()
    {
        return $this->narration;
    }

    public function lines()
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
