<?php

namespace Database\Seeders;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Populate missing accounts without overwriting tenant customization or restoring deleted accounts. */
class TenantChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        DB::connection('tenant')->transaction(fn () => $this->seedAccounts());
    }

    private function seedAccounts(): void
    {
        $template = DB::connection('master')
            ->table('coa_templates')
            ->where('template_type', 'SACCO_UGANDA')
            ->first();

        if (! $template || ! DB::connection('master')->table('coa_template_accounts')->where('template_id', $template->id)->exists()) {
            (new SaccoCoaSeeder)->run();
            $template = DB::connection('master')->table('coa_templates')->where('template_type', 'SACCO_UGANDA')->first();
        }

        if (! $template) {
            throw new \RuntimeException('SACCO_UGANDA COA template could not be initialized.');
        }

        $accounts = DB::connection('master')
            ->table('coa_template_accounts')
            ->where('template_id', $template->id)
            ->orderBy('level')
            ->get();

        if ($accounts->isEmpty()) {
            throw new \RuntimeException('SACCO_UGANDA COA template has no accounts.');
        }

        // template_id => gl_code, so parent_template_id can be resolved to a gl_code without a DB query.
        $glByTemplateId = $accounts->pluck('gl_code', 'id')->all();

        // gl_code => tenant ChartOfAccount id, populated as we insert so children find their parent in memory.
        $tenantIdByGl = [];

        foreach ($accounts as $account) {
            $parentId = null;
            if ($account->parent_template_id && isset($glByTemplateId[$account->parent_template_id])) {
                $parentId = $tenantIdByGl[$glByTemplateId[$account->parent_template_id]] ?? null;
            }

            $tenantCoa = ChartOfAccount::withTrashed()->firstOrCreate(
                ['gl_code' => $account->gl_code],
                [
                    'name' => $account->name,
                    'account_subtype' => $account->account_subtype,
                    'account_type' => $account->account_type,
                    'normal_balance' => $account->normal_balance,
                    'level' => $account->level,
                    'parent_id' => $parentId,
                    'is_control' => $account->is_control,
                    'is_postable' => $account->is_postable,
                    'allow_manual' => $account->allow_manual,
                    'ifrs_category' => $account->ifrs_category,
                    'is_active' => true,
                ]
            );

            $tenantIdByGl[$account->gl_code] = $tenantCoa->id;
        }
    }
}
