<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Tenant\Modules\Expenses\Enums\ExpenseStatus;
use App\Tenant\Modules\Expenses\Models\Expense;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessRecurringExpenses extends Command
{
    protected $signature = 'expense:process-recurring
                            {--tenant= : Run for a specific tenant subdomain only}';

    protected $description = 'Generate pending expenses for recurring schedules due today.';

    public function __construct(protected DatabaseSwitcher $switcher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantSubdomain = $this->option('tenant');

        if ($tenantSubdomain) {
            $tenant = Tenant::where('subdomain', $tenantSubdomain)->first();
            if (! $tenant) {
                $this->error("Tenant with subdomain {$tenantSubdomain} not found.");
                return 1;
            }
            $this->processForTenant($tenant);
            return 0;
        }

        $tenants = Tenant::where('status', 'active')->get();

        foreach ($tenants as $tenant) {
            try {
                $this->processForTenant($tenant);
            } catch (\Throwable $e) {
                $this->error("Failed to process tenant {$tenant->subdomain}: {$e->getMessage()}");
            }
        }

        $this->info('Recurring expenses processed successfully.');
        return 0;
    }

    protected function processForTenant(Tenant $tenant): void
    {
        $this->switcher->switch($tenant);

        $today = \Illuminate\Support\Carbon::now()->toDateString();
        
        $recurringTemplates = Expense::where('is_recurring', true)
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<=', $today)
            ->get();

        $count = 0;
        foreach ($recurringTemplates as $template) {
            // 1. Create the new pending expense instance
            Expense::create([
                'parent_id' => $template->id,
                'title' => $template->title,
                'expense_category_id' => $template->expense_category_id,
                'amount' => $template->amount,
                'transaction_date' => $template->next_due_date,
                'payment_method' => $template->payment_method,
                'vendor_name' => $template->vendor_name,
                'description' => $template->description,
                'status' => ExpenseStatus::Pending->value,
                'branch_id' => $template->branch_id,
                'created_by' => $template->created_by,
                'is_recurring' => false,
            ]);

            // 2. Update next due date for the template
            $template->next_due_date = $this->calculateNextDueDate(
                $template->next_due_date, 
                $template->recurring_frequency
            );
            $template->save();
            $count++;
        }

        if ($count > 0) {
            $this->line("Generated {$count} recurring expenses for tenant: {$tenant->subdomain}");
        }
    }

    private function calculateNextDueDate(\Illuminate\Support\Carbon|string $currentDate, string $frequency): \Illuminate\Support\Carbon
    {
        $date = \Illuminate\Support\Carbon::parse($currentDate);
        
        return match (strtolower($frequency)) {
            'weekly' => $date->addWeek(),
            'monthly' => $date->addMonth(),
            'quarterly' => $date->addMonths(3),
            'annually', 'yearly' => $date->addYear(),
            default => $date->addMonth(),
        };
    }
}
