<?php

namespace App\Central\Console\Commands;

use App\Central\Models\License;
use App\Domain\Tenancy\Entities\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckLicenseExpiry extends Command
{
    protected $signature = 'licenses:check-expiry';

    protected $description = 'Check for expiring and expired licenses, update statuses, and log warnings.';

    public function handle(): int
    {
        $this->info('Checking license expiry...');

        // 1. Auto-expire active licenses whose expires_at has passed
        $expired = License::where('status', 'active')
            ->where('expires_at', '<', Carbon::now())
            ->get();

        foreach ($expired as $license) {
            // If grace_ends_at is set and hasn't passed, set to 'grace'
            if ($license->grace_ends_at && Carbon::parse($license->grace_ends_at)->isFuture()) {
                $license->update(['status' => 'grace']);
                $this->comment("License {$license->id} moved to grace period (tenant: {$license->tenant_id}).");
            } else {
                $license->update(['status' => 'expired']);
                // Also suspend the tenant if grace period has ended
                Tenant::where('id', $license->tenant_id)->update(['status' => 'suspended']);
                $this->warn("License {$license->id} expired + tenant suspended (tenant: {$license->tenant_id}).");
            }
        }

        // 2. Auto-suspend tenants whose grace period has ended
        $graceExpired = License::where('status', 'grace')
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<', Carbon::now())
            ->get();

        foreach ($graceExpired as $license) {
            $license->update(['status' => 'expired']);
            Tenant::where('id', $license->tenant_id)->update(['status' => 'suspended']);
            $this->warn("Grace ended for license {$license->id} — tenant suspended.");
        }

        // 3. Log warnings for licenses expiring within milestone days
        $milestones = [30, 14, 7, 1];
        foreach ($milestones as $days) {
            $expiringSoon = License::where('status', 'active')
                ->whereDate('expires_at', Carbon::now()->addDays($days)->toDateString())
                ->with('tenant')
                ->get();

            foreach ($expiringSoon as $license) {
                $tenantName = $license->tenant?->name ?? 'Unknown';
                Log::warning("License expiring in {$days} day(s): Tenant [{$tenantName}] (License: {$license->id})");
                $this->line("⚠ License for [{$tenantName}] expires in {$days} day(s).");
            }
        }

        $this->info('License expiry check completed.');

        return 0;
    }
}
