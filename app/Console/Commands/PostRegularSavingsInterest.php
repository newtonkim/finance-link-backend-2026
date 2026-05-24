<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Tenant\Modules\Savings\Services\RegularSavingsInterestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PostRegularSavingsInterest extends Command
{
    protected $signature = 'savings:post-regular-interest
                            {--tenant= : Run for a specific tenant subdomain only}';

    protected $description = 'Post interest for voluntary and mandatory savings accounts across all active tenants.';

    public function __construct(protected DatabaseSwitcher $switcher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $subdomain = $this->option('tenant');
        $query = Tenant::where('status', 'active');

        if ($subdomain) {
            $query->where('subdomain', $subdomain);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('No active tenants found.');

            return self::SUCCESS;
        }

        $totalPosted = 0;
        $totalSkipped = 0;
        $hasErrors = false;

        foreach ($tenants as $tenant) {
            try {
                $this->switcher->switch($tenant);

                /** @var RegularSavingsInterestService $service */
                $service = app(RegularSavingsInterestService::class);
                $result = $service->runBatchPosting(1);

                $totalPosted += $result['posted'];
                $totalSkipped += $result['skipped'];

                if (! empty($result['errors'])) {
                    $hasErrors = true;
                    foreach ($result['errors'] as $err) {
                        $this->warn("  [{$tenant->subdomain}] {$err['account_no']}: {$err['reason']}");
                    }
                } else {
                    $this->line("  [{$tenant->subdomain}] posted={$result['posted']} skipped={$result['skipped']}");
                }

                Log::info("savings:post-regular-interest [{$tenant->subdomain}]", $result);
            } catch (\Throwable $e) {
                $hasErrors = true;
                $this->error("  [{$tenant->subdomain}] failed: {$e->getMessage()}");
                Log::error("savings:post-regular-interest [{$tenant->subdomain}] failed: ".$e->getMessage());
            }
        }

        $this->info("Done. posted={$totalPosted} skipped={$totalSkipped}");

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }
}
