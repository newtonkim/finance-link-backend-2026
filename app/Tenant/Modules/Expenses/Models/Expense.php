<?php

namespace App\Tenant\Modules\Expenses\Models;

use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @method static \Illuminate\Database\Eloquent\Builder|Expense query()
 * @method static \Illuminate\Database\Eloquent\Builder|Expense where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder|Expense whereNotNull($column)
 * @method static \Illuminate\Database\Eloquent\Builder|Expense create(array $attributes = [])
 * @method static \Illuminate\Database\Eloquent\Builder|Expense findOrFail($id, $columns = ['*'])
 * @method static \Illuminate\Database\Eloquent\Builder|Expense with($relations)
 * @method static \Illuminate\Database\Eloquent\Builder|Expense orderByDesc($column)
 * @method static \Illuminate\Database\Eloquent\Builder|Expense orderBy($column, $direction = 'asc')
 * @method static float sum($column)
 * @method static int count($columns = '*')
 * @method static bool update(array $values)
 * @method bool save(array $options = [])
 * @method bool delete()
 * @method static \Illuminate\Database\Eloquent\Collection|Expense[] get($columns = ['*'])
 * @method static \Illuminate\Database\Eloquent\Collection|Expense[] all($columns = ['*'])
 * @method static Expense|null first($columns = ['*'])
 * @method static Expense find($id, $columns = ['*'])
 * @method static \Illuminate\Contracts\Pagination\LengthAwarePaginator paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null)
 * @mixin \Illuminate\Database\Eloquent\Model
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @mixin \Illuminate\Database\Query\Builder
 */
class Expense extends Model
{
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $fillable = [
        'parent_id',
        'title',
        'expense_category_id',
        'chart_of_account_id',
        'amount',
        'vendor_name',
        'transaction_date',
        'reference_no',
        'description',
        'payment_method',
        'status',
        'is_recurring',
        'recurring_frequency',
        'next_due_date',
        'created_by',
        'approved_by',
        'paid_by',
        'branch_id',
        'type',
        'current_approval_level',
        'is_over_budget',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transaction_date' => 'date',
        'next_due_date' => 'date',
        'is_recurring' => 'boolean',
        'is_over_budget' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'paid_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ExpenseAttachment::class);
    }

    public function approvalHistory(): HasMany
    {
        return $this->hasMany(ExpenseApprovalHistory::class)->orderBy('created_at', 'asc');
    }
}
