<?php

use App\Tenant\Modules\Loans\Data\DocumentType;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Document types used to be inserted only by a tenant migration. New tenants are
 * built from database/schema/tenant-schema.sql, which carries no table data and
 * marks that migration as already run — so every newly provisioned tenant had an
 * empty document_types table and the loan product "Document Type" dropdown was blank.
 */
beforeEach(function () {
    DB::connection('tenant')->table('loan_product_required_documents')->delete();
    DB::connection('tenant')->table('document_types')->delete();
});

it('seeds every canonical document type into an empty table', function () {
    $this->seed(DocumentTypeSeeder::class);

    $rows = DB::connection('tenant')->table('document_types')->pluck('name', 'code')->all();

    expect($rows)->toEqual(DocumentType::TYPES);
    expect(DB::connection('tenant')->table('document_types')->where('is_active', false)->count())->toBe(0);
});

it('only adds missing types and keeps existing rows untouched', function () {
    DB::connection('tenant')->table('document_types')->insert([
        'code' => 'payslip',
        'name' => 'Payslip (renamed by sacco)',
        'is_active' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->seed(DocumentTypeSeeder::class);
    $this->seed(DocumentTypeSeeder::class);

    $payslip = DB::connection('tenant')->table('document_types')->where('code', 'payslip')->first();

    expect(DB::connection('tenant')->table('document_types')->count())->toBe(count(DocumentType::TYPES))
        ->and($payslip->name)->toBe('Payslip (renamed by sacco)')
        ->and((bool) $payslip->is_active)->toBeFalse();
});

it('runs as part of the tenant provisioning seeder', function () {
    $this->seed(TenantSeeder::class);

    expect(DB::connection('tenant')->table('document_types')->count())->toBe(count(DocumentType::TYPES));
});
