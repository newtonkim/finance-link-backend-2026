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
 * @method static \Illuminate\Database\Eloquent\Builder|ExpenseCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder|ExpenseCategory where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder|ExpenseCategory whereNotNull($column)
 * @method static \Illuminate\Database\Eloquent\Builder|ExpenseCategory create(array $attributes = [])
 * @method static \Illuminate\Database\Eloquent\Builder|ExpenseCategory findOrFail($id, $columns = ['*'])
 * @method bool save(array $options = [])
 * @method bool delete()
 * @method static \Illuminate\Database\Eloquent\Builder|ExpenseCategory with($relations)
 * @method static \Illuminate\Database\Eloquent\Builder|ExpenseCategory orderBy($column, $direction = 'asc')
 * @method static \Illuminate\Database\Eloquent\Collection|ExpenseCategory[] get($columns = ['*'])
 * @method static \Illuminate\Database\Eloquent\Collection|ExpenseCategory[] all($columns = ['*'])
 * @method static ExpenseCategory|null first($columns = ['*'])
 * @method static ExpenseCategory find($id, $columns = ['*'])
 * @mixin \Illuminate\Database\Eloquent\Model
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @mixin \Illuminate\Database\Query\Builder
 */
class ExpenseCategory extends Model
{
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $fillable = [
        'name',
        'description',
        'chart_of_account_id',
        'is_active',
        'branch_id',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'expense_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }
}
