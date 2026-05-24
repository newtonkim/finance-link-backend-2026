<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class LoanApplicationDocument extends Model
{
    protected $connection = 'tenant';

    protected $table = 'loan_application_documents';

    const STATUS_PENDING = 'pending';

    const STATUS_VERIFIED = 'verified';

    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'loan_application_id',
        'document_type',
        'document_label',
        'original_name',
        'file_path',
        'mime_type',
        'file_size',
        'status',
        'verified_by',
        'verified_at',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'file_size' => 'integer',
        'loan_application_id' => 'integer',
        'verified_by' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    protected $appends = ['url'];

    public function getUrlAttribute(): ?string
    {
        return $this->file_path
            ? Storage::disk('public')->url($this->file_path)
            : null;
    }

    public function loanApplication(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'verified_by');
    }
}
