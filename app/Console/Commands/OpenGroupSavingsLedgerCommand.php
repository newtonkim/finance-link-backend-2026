<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Tenant\Modules\Accounting\Services\GroupSavingsJournalService;
use Illuminate\Console\Command;

/**
 * Brings group savings accounts onto the general ledger: posts each account's
 * balance as an opening balance and journals its movements from then on. The
 * migration does this for tenants that already had a chart of accounts; this is
 * for any it could not (the chart was missing an account). Safe to run again.
 */
class OpenGroupSavingsLedgerCommand extends Command
{
    protected $signature = 'group-savings:open-ledger
                            {--tenant= : Run for a specific tenant subdomain only}';

    protected $description = 'Bring group savings accounts onto the general ledger, across all tenants.';

    public function __construct(protected DatabaseSwitcher $switcher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = Tenant::where('status', 'active');
        if ($subdomain = $this->option('tenant')) {
            $query->where('subdomain', $subdomain);
        }

        $opened = 0;
        $failed = 0;

        foreach ($query->get() as $tenant) {
            try {
                $this->switcher->switch($tenant);

                $result = app(GroupSavingsJournalService::class)->openAllLedgers();
                $opened += $result['opened'];
                $failed += count($result['failed']);

                $this->line("  [{$tenant->subdomain}] opened={$result['opened']} failed=".count($result['failed']));
                foreach ($result['failed'] as $failure) {
                    $this->warn("    group savings account {$failure['id']}: {$failure['error']}");
                }
            } catch (\Throwable $e) {
                $this->error("  [{$tenant->subdomain}] failed: {$e->getMessage()}");
            }
        }

        $this->info("Done. opened={$opened} failed={$failed}");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
