<?php

use App\Tenant\Modules\Accounting\Support\IncomeStatementLines;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('chart_of_accounts', function (Blueprint $table) {
            $table->string('income_statement_line', 40)->nullable();
        });
        Schema::connection('tenant')->table('journal_entries', function (Blueprint $table) {
            $table->boolean('is_closing_entry')->default(false);
        });
        foreach (IncomeStatementLines::TEMPLATE as $code => [$name, $line]) {
            DB::connection('tenant')->table('chart_of_accounts')
                ->where('gl_code', $code)->where('name', $name)
                ->where('account_type', IncomeStatementLines::DEFINITIONS[$line][1])
                ->whereNull('income_statement_line')->update(['income_statement_line' => $line]);
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('chart_of_accounts', fn (Blueprint $table) => $table->dropColumn('income_statement_line'));
        Schema::connection('tenant')->table('journal_entries', fn (Blueprint $table) => $table->dropColumn('is_closing_entry'));
    }
};
