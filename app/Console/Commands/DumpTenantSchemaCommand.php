<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class DumpTenantSchemaCommand extends Command
{
    protected $signature = 'tenant:dump-schema {--subdomain= : Subdomain of a fully-migrated tenant to dump from}';

    protected $description = 'Generate database/schema/tenant-schema.sql from a fully-migrated tenant DB. New tenant creations then load this dump in ~1s instead of running 200+ migrations.';

    public function handle(): int
    {
        $subdomain = $this->option('subdomain') ?: $this->ask('Subdomain of a fully-migrated tenant to dump from');

        $tenant = Tenant::on('master')->where('subdomain', $subdomain)->first();
        if (! $tenant) {
            $this->error("No tenant found with subdomain [{$subdomain}].");

            return self::FAILURE;
        }

        // Point the `tenant` connection at this tenant's actual DB for the duration of the dump.
        Config::set('database.connections.tenant.database', $tenant->database_name);
        DB::purge('tenant');

        $this->info("Dumping schema from [{$tenant->database_name}]...");

        $exitCode = Artisan::call('schema:dump', [
            '--database' => 'tenant',
        ], $this->getOutput());

        if ($exitCode !== 0) {
            $this->error('schema:dump failed.');

            return self::FAILURE;
        }

        $this->stripDefiners();

        $this->info('Done. Commit database/schema/tenant-schema.sql so new tenant creations use it.');

        return self::SUCCESS;
    }

    /**
     * mysqldump bakes `DEFINER=`user`@`host`` into view/trigger/routine DDL. That user
     * may not exist on the host where a new tenant DB is created, which makes loading the
     * dump fail. Strip the clause so MySQL falls back to the connecting user as definer.
     */
    private function stripDefiners(): void
    {
        $path = database_path('schema/tenant-schema.sql');

        if (! is_file($path)) {
            return;
        }

        $sql = file_get_contents($path);
        $cleaned = preg_replace('/\sDEFINER=`[^`]+`@`[^`]+`/', '', $sql);

        if ($cleaned !== null && $cleaned !== $sql) {
            file_put_contents($path, $cleaned);
        }
    }
}
