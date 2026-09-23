<?php

namespace Tests\Unit;

use Database\Seeders\SaccoCoaSeeder;
use Database\Seeders\TenantChartOfAccountsSeeder;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class TenantChartOfAccountsSeederTest extends TestCase
{
    private Manager $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new Manager;
        foreach (['master', 'tenant', 'other'] as $name) {
            $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], $name);
        }
        $this->db->bootEloquent();
        $container = $this->db->getContainer();
        $container->instance('db', $this->db->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        (require __DIR__.'/../../database/migrations/landlord/2026_02_27_000001_create_coa_templates_tables.php')->up();
        foreach (['tenant', 'other'] as $name) {
            $this->db->getConnection($name)->getSchemaBuilder()->create('chart_of_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('gl_code')->unique();
                foreach (['name', 'account_type', 'account_subtype', 'normal_balance', 'ifrs_category'] as $column) {
                    $table->string($column)->nullable();
                }
                $table->integer('level');
                $table->unsignedBigInteger('parent_id')->nullable();
                foreach (['is_control', 'is_postable', 'allow_manual', 'is_active'] as $column) {
                    $table->boolean($column);
                }
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    protected function tearDown(): void
    {
        foreach (['master', 'tenant', 'other'] as $name) {
            $this->db->getDatabaseManager()->purge($name);
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    private function seed(): void
    {
        ob_start();
        try {
            (new TenantChartOfAccountsSeeder)->run();
        } finally {
            ob_end_clean();
        }
    }

    public function test_missing_template_is_initialized_and_only_selected_tenant_is_seeded(): void
    {
        $this->seed();
        $count = $this->db->getConnection('master')->table('coa_template_accounts')->count();
        self::assertGreaterThan(0, $count);
        self::assertSame($count, $this->db->getConnection('tenant')->table('chart_of_accounts')->count());
        self::assertSame(0, $this->db->getConnection('other')->table('chart_of_accounts')->count());
    }

    public function test_repair_preserves_existing_tenant_accounts_and_is_repeatable(): void
    {
        $this->seed();
        $accounts = $this->db->getConnection('tenant')->table('chart_of_accounts');
        $before = $accounts->count();
        $accounts->where('gl_code', '10000')->update(['name' => 'Custom asset label', 'is_active' => false, 'deleted_at' => '2026-01-01 00:00:00']);
        $accounts->where('gl_code', '11101')->delete();
        $this->seed();
        $this->seed();
        self::assertSame($before, $this->db->getConnection('tenant')->table('chart_of_accounts')->count());
        $asset = $this->db->getConnection('tenant')->table('chart_of_accounts')->where('gl_code', '10000')->first();
        self::assertSame('Custom asset label', $asset->name);
        self::assertSame(0, $asset->is_active);
        self::assertNotNull($asset->deleted_at);
    }

    public function test_empty_template_is_filled_and_master_reseeding_preserves_ids_and_custom_names(): void
    {
        $master = $this->db->getConnection('master');
        $master->table('coa_templates')->insert(['id' => 'existing-template', 'template_type' => 'SACCO_UGANDA', 'name' => 'Existing']);
        $this->seed();
        $row = $master->table('coa_template_accounts')->first();
        $master->table('coa_template_accounts')->where('id', $row->id)->update(['name' => 'Custom master account']);
        ob_start();
        try {
            (new SaccoCoaSeeder)->run();
        } finally {
            ob_end_clean();
        }
        self::assertSame('Custom master account', $master->table('coa_template_accounts')->where('id', $row->id)->value('name'));
        self::assertSame('existing-template', $master->table('coa_templates')->value('id'));
    }
}
