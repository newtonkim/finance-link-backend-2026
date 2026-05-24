<?php

namespace App\Tenant\Modules\Expenses\Models;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @method static \Illuminate\Database\Eloquent\Builder|ExpenseAttachment query()
 * @method static \Illuminate\Database\Eloquent\Builder|ExpenseAttachment create(array $attributes = [])
 * @method static \Illuminate\Database\Eloquent\Builder|ExpenseAttachment findOrFail($id, $columns = ['*'])
 * @mixin \Illuminate\Database\Eloquent\Model
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @mixin \Illuminate\Database\Query\Builder
 */
class ExpenseAttachment extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'expense_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
        'uploaded_by',
        'created_by',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'uploaded_by');
    }
}
