<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot the exchange rate and both the settlement (base) and charge
     * currencies on the billing records so a renewal is fully reproducible
     * and auditable, independent of any later rate movement.
     */
    public function up(): void
    {
        if (Schema::connection('master')->hasTable('license_invoices')) {
            Schema::connection('master')->table('license_invoices', function (Blueprint $table) {
                if (! Schema::connection('master')->hasColumn('license_invoices', 'charge_currency')) {
                    $table->string('charge_currency', 10)->nullable()->after('currency')->index();
                }

                if (! Schema::connection('master')->hasColumn('license_invoices', 'fx_rate')) {
                    $table->decimal('fx_rate', 20, 8)->default(1)->after('charge_currency');
                }

                if (! Schema::connection('master')->hasColumn('license_invoices', 'charge_total')) {
                    $table->decimal('charge_total', 18, 2)->nullable()->after('fx_rate');
                }
            });
        }

        if (Schema::connection('master')->hasTable('license_payments')) {
            Schema::connection('master')->table('license_payments', function (Blueprint $table) {
                if (! Schema::connection('master')->hasColumn('license_payments', 'base_currency')) {
                    $table->string('base_currency', 10)->nullable()->after('currency')->index();
                }

                if (! Schema::connection('master')->hasColumn('license_payments', 'base_amount')) {
                    $table->decimal('base_amount', 18, 2)->nullable()->after('base_currency');
                }

                if (! Schema::connection('master')->hasColumn('license_payments', 'fx_rate')) {
                    $table->decimal('fx_rate', 20, 8)->default(1)->after('base_amount');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('master')->hasTable('license_invoices')) {
            Schema::connection('master')->table('license_invoices', function (Blueprint $table) {
                foreach (['charge_currency', 'fx_rate', 'charge_total'] as $column) {
                    if (Schema::connection('master')->hasColumn('license_invoices', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::connection('master')->hasTable('license_payments')) {
            Schema::connection('master')->table('license_payments', function (Blueprint $table) {
                foreach (['base_currency', 'base_amount', 'fx_rate'] as $column) {
                    if (Schema::connection('master')->hasColumn('license_payments', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
