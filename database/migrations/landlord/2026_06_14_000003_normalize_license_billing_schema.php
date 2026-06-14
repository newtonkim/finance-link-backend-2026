<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addLicensePlanId();
        $this->backfillLicensePlanId();
        $this->enhanceInvoices();
        $this->enhancePayments();
        $this->createPaymentAttempts();
        $this->addForeignKeys();
    }

    public function down(): void
    {
        $this->dropForeignKeys();

        if (Schema::connection('master')->hasTable('license_payment_attempts')) {
            Schema::connection('master')->dropIfExists('license_payment_attempts');
        }

        if (Schema::connection('master')->hasTable('license_payments')) {
            Schema::connection('master')->table('license_payments', function (Blueprint $table) {
                $this->dropColumnIfExists($table, 'license_payments', [
                    'provider_reference',
                    'idempotency_key',
                    'gateway_payload',
                    'failure_reason',
                    'confirmed_at',
                    'approved_by',
                    'approved_at',
                    'created_by',
                    'updated_by',
                ]);
            });
        }

        if (Schema::connection('master')->hasTable('license_invoices')) {
            Schema::connection('master')->table('license_invoices', function (Blueprint $table) {
                $this->dropColumnIfExists($table, 'license_invoices', [
                    'billing_cycle',
                    'renewal_starts_at',
                    'renewal_expires_at',
                    'activated_license_id',
                    'idempotency_key',
                    'gateway_payload',
                    'failure_reason',
                    'approved_by',
                    'approved_at',
                    'created_by',
                    'updated_by',
                ]);
            });
        }

        if (Schema::connection('master')->hasTable('licenses') && Schema::connection('master')->hasColumn('licenses', 'plan_id')) {
            Schema::connection('master')->table('licenses', function (Blueprint $table) {
                $table->dropColumn('plan_id');
            });
        }
    }

    private function dropForeignKeys(): void
    {
        if (Schema::connection('master')->hasTable('license_payments')) {
            Schema::connection('master')->table('license_payments', function (Blueprint $table) {
                $table->dropForeign('license_payments_invoice_id_fk');
                $table->dropForeign('license_payments_tenant_id_fk');
            });
        }

        if (Schema::connection('master')->hasTable('license_invoices')) {
            Schema::connection('master')->table('license_invoices', function (Blueprint $table) {
                $table->dropForeign('license_invoices_license_id_fk');
                $table->dropForeign('license_invoices_tenant_id_fk');
                $table->dropForeign('license_invoices_plan_id_fk');
                $table->dropForeign('license_invoices_activated_license_id_fk');
            });
        }

        if (Schema::connection('master')->hasTable('licenses') && Schema::connection('master')->hasColumn('licenses', 'plan_id')) {
            Schema::connection('master')->table('licenses', function (Blueprint $table) {
                $table->dropForeign('licenses_plan_id_fk');
            });
        }
    }

    private function addLicensePlanId(): void
    {
        if (! Schema::connection('master')->hasColumn('licenses', 'plan_id')) {
            Schema::connection('master')->table('licenses', function (Blueprint $table) {
                $table->unsignedBigInteger('plan_id')->nullable()->after('plan')->index();
            });
        }
    }

    private function backfillLicensePlanId(): void
    {
        DB::connection('master')->statement("
            UPDATE licenses ls
            JOIN plans pl ON pl.id = CAST(ls.plan AS UNSIGNED)
            SET ls.plan_id = pl.id
            WHERE ls.plan_id IS NULL
              AND ls.plan REGEXP '^[0-9]+$'
        ");

        DB::connection('master')->statement("
            UPDATE licenses ls
            JOIN plans pl ON pl.slug = ls.plan
            SET ls.plan_id = pl.id
            WHERE ls.plan_id IS NULL
        ");
    }

    private function enhanceInvoices(): void
    {
        Schema::connection('master')->table('license_invoices', function (Blueprint $table) {
            if (! Schema::connection('master')->hasColumn('license_invoices', 'billing_cycle')) {
                $table->string('billing_cycle', 40)->nullable()->after('plan_id')->index();
            }

            if (! Schema::connection('master')->hasColumn('license_invoices', 'renewal_starts_at')) {
                $table->date('renewal_starts_at')->nullable()->after('due_at')->index();
            }

            if (! Schema::connection('master')->hasColumn('license_invoices', 'renewal_expires_at')) {
                $table->date('renewal_expires_at')->nullable()->after('renewal_starts_at')->index();
            }

            if (! Schema::connection('master')->hasColumn('license_invoices', 'activated_license_id')) {
                $table->uuid('activated_license_id')->nullable()->after('renewal_expires_at')->index();
            }

            if (! Schema::connection('master')->hasColumn('license_invoices', 'idempotency_key')) {
                $table->string('idempotency_key', 120)->nullable()->after('invoice_number')->unique();
            }

            if (! Schema::connection('master')->hasColumn('license_invoices', 'gateway_payload')) {
                $table->json('gateway_payload')->nullable()->after('paid_at');
            }

            if (! Schema::connection('master')->hasColumn('license_invoices', 'failure_reason')) {
                $table->text('failure_reason')->nullable()->after('gateway_payload');
            }

            if (! Schema::connection('master')->hasColumn('license_invoices', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('failure_reason')->index();
            }

            if (! Schema::connection('master')->hasColumn('license_invoices', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by')->index();
            }

            if (! Schema::connection('master')->hasColumn('license_invoices', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('approved_at')->index();
            }

            if (! Schema::connection('master')->hasColumn('license_invoices', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by')->index();
            }
        });

        DB::connection('master')
            ->table('license_invoices')
            ->where('status', 'pending')
            ->update(['status' => 'invoice_pending']);
    }

    private function enhancePayments(): void
    {
        Schema::connection('master')->table('license_payments', function (Blueprint $table) {
            if (! Schema::connection('master')->hasColumn('license_payments', 'provider_reference')) {
                $table->string('provider_reference', 160)->nullable()->after('provider')->unique();
            }

            if (! Schema::connection('master')->hasColumn('license_payments', 'idempotency_key')) {
                $table->string('idempotency_key', 120)->nullable()->after('provider_reference')->unique();
            }

            if (! Schema::connection('master')->hasColumn('license_payments', 'gateway_payload')) {
                $table->json('gateway_payload')->nullable()->after('paid_at');
            }

            if (! Schema::connection('master')->hasColumn('license_payments', 'failure_reason')) {
                $table->text('failure_reason')->nullable()->after('gateway_payload');
            }

            if (! Schema::connection('master')->hasColumn('license_payments', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable()->after('failure_reason')->index();
            }

            if (! Schema::connection('master')->hasColumn('license_payments', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('confirmed_at')->index();
            }

            if (! Schema::connection('master')->hasColumn('license_payments', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by')->index();
            }

            if (! Schema::connection('master')->hasColumn('license_payments', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('approved_at')->index();
            }

            if (! Schema::connection('master')->hasColumn('license_payments', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by')->index();
            }
        });

        DB::connection('master')
            ->table('license_payments')
            ->where('status', 'pending')
            ->update(['status' => 'payment_pending']);
    }

    private function createPaymentAttempts(): void
    {
        if (Schema::connection('master')->hasTable('license_payment_attempts')) {
            return;
        }

        Schema::connection('master')->create('license_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('license_payment_id')->index();
            $table->string('provider')->nullable()->index();
            $table->string('provider_reference', 160)->nullable()->index();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->string('status')->default('payment_pending')->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('attempted_at')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
            $table->foreign('license_payment_id', 'lpa_payment_fk')
                ->references('id')
                ->on('license_payments')
                ->cascadeOnDelete();
        });
    }

    private function addForeignKeys(): void
    {
        Schema::connection('master')->table('licenses', function (Blueprint $table) {
            $table->foreign('plan_id', 'licenses_plan_id_fk')
                ->references('id')
                ->on('plans')
                ->nullOnDelete();
        });

        Schema::connection('master')->table('license_invoices', function (Blueprint $table) {
            $table->foreign('license_id', 'license_invoices_license_id_fk')
                ->references('id')
                ->on('licenses')
                ->cascadeOnDelete();

            $table->foreign('tenant_id', 'license_invoices_tenant_id_fk')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->foreign('plan_id', 'license_invoices_plan_id_fk')
                ->references('id')
                ->on('plans')
                ->nullOnDelete();

            $table->foreign('activated_license_id', 'license_invoices_activated_license_id_fk')
                ->references('id')
                ->on('licenses')
                ->nullOnDelete();
        });

        Schema::connection('master')->table('license_payments', function (Blueprint $table) {
            $table->foreign('license_invoice_id', 'license_payments_invoice_id_fk')
                ->references('id')
                ->on('license_invoices')
                ->cascadeOnDelete();

            $table->foreign('tenant_id', 'license_payments_tenant_id_fk')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
        });
    }

    private function dropColumnIfExists(Blueprint $table, string $dbTable, array $columns): void
    {
        $existing = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::connection('master')->hasColumn($dbTable, $column)
        ));

        if ($existing !== []) {
            $table->dropColumn($existing);
        }
    }
};
