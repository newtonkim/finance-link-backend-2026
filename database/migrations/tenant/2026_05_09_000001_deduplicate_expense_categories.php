<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Map old category names → canonical replacement names
        $renames = [
            'Office Supplies'  => 'Office Supplies & Stationery',
            'Rent & Utilities' => 'Rent & Occupancy',
        ];

        foreach ($renames as $oldName => $newName) {
            $old = DB::table('expense_categories')->where('name', $oldName)->first();
            $new = DB::table('expense_categories')->where('name', $newName)->first();

            if (! $old) {
                continue;
            }

            if ($new) {
                // Re-point all child records before deleting the old category
                DB::table('expenses')
                    ->where('expense_category_id', $old->id)
                    ->update(['expense_category_id' => $new->id]);

                DB::table('expense_budgets')
                    ->where('expense_category_id', $old->id)
                    ->update(['expense_category_id' => $new->id]);

                DB::table('expense_categories')->where('id', $old->id)->delete();
            } else {
                // New name doesn't exist yet — just rename in place
                DB::table('expense_categories')
                    ->where('id', $old->id)
                    ->update(['name' => $newName]);
            }
        }
    }

    public function down(): void
    {
        // Intentionally not reversible — data deduplication cannot be safely undone
    }
};
