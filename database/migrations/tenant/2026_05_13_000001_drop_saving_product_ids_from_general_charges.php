<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The savings_product_charges pivot (with general_charge_id FK) is the canonical
 * link between a general charge and the savings products it applies to. The
 * saving_product_ids JSON column on general_charges duplicated that information
 * and was never read by the application-time code path — ChargeCalculatorService
 * looks at the pivot, not at the JSON. Dropping the column locks the pivot in as
 * the single source of truth so the two can't drift.
 *
 * Before dropping the column we backfill any existing JSON values into pivot
 * rows so admins who already clicked-through the UI don't silently lose their
 * configuration. The backfill expands each row's products × all three savings
 * event types (deposit/withdraw/transfer) — without the new "Apply on" form
 * field there's no way to know which events the admin intended, so the
 * widest-but-safest reading wins. Admins can untick events they don't want on
 * the next edit through the redesigned form.
 *
 * loan_product_ids is kept for now — the loan side of the unification is tracked
 * separately as Gap 2 of the charge-system audit.
 */
return new class extends Migration
{
    private const TRIGGER_TYPES = ['deposit', 'withdraw', 'transfer'];

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('general_charges', 'saving_product_ids')) {
            return;
        }

        $this->backfillPivotFromJson();

        Schema::connection('tenant')->table('general_charges', function (Blueprint $table) {
            $table->dropColumn('saving_product_ids');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasColumn('general_charges', 'saving_product_ids')) {
            Schema::connection('tenant')->table('general_charges', function (Blueprint $table) {
                $table->json('saving_product_ids')->nullable()->after('credit_account_id');
            });
        }
    }

    /**
     * Read every general_charges row's saving_product_ids JSON and expand into
     * savings_product_charges pivot rows. Idempotent: skips combinations that
     * already exist so this is safe to run alongside any partial manual seed.
     */
    private function backfillPivotFromJson(): void
    {
        DB::connection('tenant')->table('general_charges')
            ->whereNotNull('saving_product_ids')
            ->where('saving_product_ids', '!=', '[]')
            ->orderBy('id')
            ->each(function ($charge) {
                $productIds = json_decode($charge->saving_product_ids, true);
                if (! is_array($productIds) || count($productIds) === 0) {
                    return;
                }

                $existing = DB::connection('tenant')->table('savings_product_charges')
                    ->where('general_charge_id', $charge->id)
                    ->get(['savings_product_id', 'type'])
                    ->map(fn ($r) => "{$r->savings_product_id}|{$r->type}")
                    ->flip();

                $rows = [];
                $now = now();
                $chargeType = $charge->charge_type ?? 'amount';

                foreach ($productIds as $productId) {
                    $productId = (int) $productId;
                    if ($productId <= 0) {
                        continue;
                    }
                    foreach (self::TRIGGER_TYPES as $type) {
                        if (isset($existing["{$productId}|{$type}"])) {
                            continue;
                        }
                        $rows[] = [
                            'savings_product_id' => $productId,
                            'general_charge_id' => $charge->id,
                            'type' => $type,
                            'name' => $charge->name,
                            'charge_type' => $chargeType,
                            'amount' => $charge->amount,
                            'minimum_amount' => 0,
                            'is_reversible' => $charge->is_reversible ?? true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::connection('tenant')->table('savings_product_charges')->insert($rows);
                }
            });
    }
};
