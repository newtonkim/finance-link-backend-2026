<?php

use App\Tenant\Settings\GuarantorSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recovering a defaulted loan from the borrower's and guarantors' savings.
 *
 * guarantor_recoveries: one recovery of one loan, proposed by one staff member and
 *   approved by another. Once executed, what the guarantors paid becomes a recovery
 *   loan the borrower owes them (recovery_loan_* columns).
 * guarantor_recovery_lines: who pays what, from which savings account. Guarantor
 *   lines also track how much of it the borrower has since repaid them.
 * guarantor_recovery_repayments: the borrower's repayments of the recovery loan,
 *   each split across the guarantors.
 */
return new class extends Migration
{
    private const ADDED_SETTINGS = [
        'sacco-guarantor-recovery-after-days',
        'sacco-guarantor-recovery-loan-term-months',
    ];

    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasTable('guarantor_recoveries')) {
            $schema->create('guarantor_recoveries', function (Blueprint $table) {
                $table->id();
                $table->string('code', 30)->nullable()->unique();
                $table->unsignedBigInteger('loan_id')->index();
                $table->unsignedBigInteger('member_id')->index()->comment('The borrower');
                $table->string('status', 30)->default('pending_approval')->index();
                $table->decimal('requested_amount', 15, 2);
                $table->decimal('borrower_amount', 15, 2)->default(0);
                $table->decimal('guarantor_amount', 15, 2)->default(0);
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('initiated_by')->nullable();
                $table->timestamp('initiated_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('rejected_by')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->string('recovery_loan_status', 20)->nullable()->index();
                $table->decimal('recovery_loan_repaid', 15, 2)->default(0);
                $table->unsignedSmallInteger('recovery_loan_term_months')->nullable();
                $table->date('recovery_loan_first_due_date')->nullable();
                $table->timestamps();

                $table->foreign('loan_id')->references('id')->on('loans')->cascadeOnDelete();
                $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('guarantor_recovery_lines')) {
            $schema->create('guarantor_recovery_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('guarantor_recovery_id')->index();
                $table->string('source', 20)->comment('borrower or guarantor');
                $table->unsignedBigInteger('loan_application_guarantor_id')->nullable()->index();
                $table->unsignedBigInteger('member_id')->index();
                $table->unsignedBigInteger('savings_account_id')->index();
                $table->decimal('amount', 15, 2);
                $table->unsignedBigInteger('loan_transaction_id')->nullable();
                $table->decimal('repaid_amount', 15, 2)->default(0);
                $table->timestamps();

                $table->foreign('guarantor_recovery_id')->references('id')->on('guarantor_recoveries')->cascadeOnDelete();
            });
        }

        if (! $schema->hasTable('guarantor_recovery_repayments')) {
            $schema->create('guarantor_recovery_repayments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('guarantor_recovery_id')->index();
                $table->decimal('amount', 15, 2);
                $table->date('payment_date');
                $table->string('payment_mode', 30)->nullable();
                $table->string('reference', 100)->nullable();
                $table->json('allocations')->comment('Per guarantor line: line id, amount, savings transaction id');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->foreign('guarantor_recovery_id')->references('id')->on('guarantor_recoveries')->cascadeOnDelete();
            });
        }

        if (! $schema->hasColumn('loan_application_guarantors', 'recovered_amount')) {
            $schema->table('loan_application_guarantors', function (Blueprint $table) {
                $table->decimal('recovered_amount', 15, 2)->default(0)->after('guarantee_amount');
            });
        }

        foreach (GuarantorSettings::definitions() as $definition) {
            if (! in_array($definition['settings_name'], self::ADDED_SETTINGS, true)) {
                continue;
            }

            if (DB::table('system_settings')->where('settings_name', $definition['settings_name'])->exists()) {
                continue;
            }

            DB::table('system_settings')->insert([
                'settings_name' => $definition['settings_name'],
                'settings_module' => GuarantorSettings::MODULE,
                'settings_status' => 'active',
                'settings_action' => json_encode($definition['settings_action']),
                'settings_action_description' => $definition['settings_action_description'],
                'settings_setting_description' => $definition['settings_setting_description'],
                'system_type' => 'system',
                'created_by' => 0,
                'updated_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('settings_name', self::ADDED_SETTINGS)->delete();

        $schema = Schema::connection('tenant');
        $schema->dropIfExists('guarantor_recovery_repayments');
        $schema->dropIfExists('guarantor_recovery_lines');
        $schema->dropIfExists('guarantor_recoveries');

        if ($schema->hasColumn('loan_application_guarantors', 'recovered_amount')) {
            $schema->table('loan_application_guarantors', function (Blueprint $table) {
                $table->dropColumn('recovered_amount');
            });
        }
    }
};
