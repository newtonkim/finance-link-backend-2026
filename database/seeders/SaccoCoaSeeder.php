<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaccoCoaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create Template (insert only if not exists — never update the PK/id)
        $existing = DB::connection('master')->table('coa_templates')->where('template_type', 'SACCO_UGANDA')->first();

        if (! $existing) {
            DB::connection('master')->table('coa_templates')->insert([
                'id' => Str::uuid()->toString(),
                'template_type' => 'SACCO_UGANDA',
                'name' => 'Standard SACCO Uganda Chart of Accounts',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $template = DB::connection('master')->table('coa_templates')->where('template_type', 'SACCO_UGANDA')->first();
        $templateId = $template->id;

        $accounts = $this->getSaccoUgandaAccounts();

        // 2. Clear existing template accounts to avoid conflicts during development
        DB::connection('master')->table('coa_template_accounts')->where('template_id', $templateId)->delete();

        // 3. Seed Accounts (ordered by level to handle parent-child relationships if needed)

        $insertedCount = 0;
        foreach ($accounts as $account) {
            DB::connection('master')->table('coa_template_accounts')->insert([
                'id' => Str::uuid()->toString(),
                'template_id' => $templateId,
                'gl_code' => $account['gl_code'],
                'name' => $account['name'],
                'account_type' => $account['account_type'],
                'account_subtype' => $account['account_subtype'] ?? null,
                'normal_balance' => $account['normal_balance'],
                'level' => $account['level'],
                'is_control' => $account['is_control'],
                'is_postable' => $account['is_postable'],
                'allow_manual' => $account['allow_manual'] ?? true,
                'ifrs_category' => $account['ifrs_category'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $insertedCount++;
        }

        echo "Seeded {$insertedCount} accounts into SACCO_UGANDA template.\n";
    }

    private function getSaccoUgandaAccounts(): array
    {
        return [
            // ASSETS (1xxxx)
            ['gl_code' => '10000', 'name' => 'ASSETS', 'account_type' => 'ASSET', 'account_subtype' => 'Header', 'normal_balance' => 'DR', 'level' => 1, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '11000', 'name' => 'Current Assets', 'account_type' => 'ASSET', 'account_subtype' => 'Current Asset', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '11100', 'name' => 'Cash & Cash Equivalents', 'account_type' => 'ASSET', 'account_subtype' => 'Current Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '11101', 'name' => 'Petty Cash', 'account_type' => 'ASSET', 'account_subtype' => 'Cash', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11102', 'name' => 'Cash at Bank – Operating Account', 'account_type' => 'ASSET', 'account_subtype' => 'Bank', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11103', 'name' => 'Cash at Bank – Loan Disbursement', 'account_type' => 'ASSET', 'account_subtype' => 'Bank', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11104', 'name' => 'Mobile Money – MTN', 'account_type' => 'ASSET', 'account_subtype' => 'Bank', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11105', 'name' => 'Mobile Money – Airtel', 'account_type' => 'ASSET', 'account_subtype' => 'Bank', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11200', 'name' => 'Member Savings Control', 'account_type' => 'ASSET', 'account_subtype' => 'Current Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '11201', 'name' => 'Mandatory Savings – Members', 'account_type' => 'ASSET', 'account_subtype' => 'Member Savings', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11202', 'name' => 'Voluntary Savings – Members', 'account_type' => 'ASSET', 'account_subtype' => 'Member Savings', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11203', 'name' => 'Fixed Deposit – Members', 'account_type' => 'ASSET', 'account_subtype' => 'Member Savings', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11300', 'name' => 'Loans Receivable (Gross)', 'account_type' => 'ASSET', 'account_subtype' => 'Loan', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '11301', 'name' => 'Personal / Consumer Loans', 'account_type' => 'ASSET', 'account_subtype' => 'Loan', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11302', 'name' => 'Business Loans', 'account_type' => 'ASSET', 'account_subtype' => 'Loan', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11303', 'name' => 'Agriculture Loans', 'account_type' => 'ASSET', 'account_subtype' => 'Loan', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11304', 'name' => 'Salary Loans', 'account_type' => 'ASSET', 'account_subtype' => 'Loan', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11305', 'name' => 'Group / VSLA Loans', 'account_type' => 'ASSET', 'account_subtype' => 'Loan', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11400', 'name' => 'Loan Loss Provisions (ECL)', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '11401', 'name' => 'Stage 1 ECL Provision', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11402', 'name' => 'Stage 2 ECL Provision', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11403', 'name' => 'Stage 3 ECL Provision', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11500', 'name' => 'Interest Receivable', 'account_type' => 'ASSET', 'account_subtype' => 'Accrued Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11600', 'name' => 'Penalty Receivable', 'account_type' => 'ASSET', 'account_subtype' => 'Accrued Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11700', 'name' => 'Charges Receivable', 'account_type' => 'ASSET', 'account_subtype' => 'Accrued Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11800', 'name' => 'Prepayments & Other Receivables', 'account_type' => 'ASSET', 'account_subtype' => 'Current Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '11900', 'name' => 'Investment in Govt Securities', 'account_type' => 'ASSET', 'account_subtype' => 'Investment', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '12000', 'name' => 'Non-Current Assets', 'account_type' => 'ASSET', 'account_subtype' => 'Non-Current Asset', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '12100', 'name' => 'Property, Plant & Equipment', 'account_type' => 'ASSET', 'account_subtype' => 'Fixed Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '12101', 'name' => 'Land & Buildings (Cost)', 'account_type' => 'ASSET', 'account_subtype' => 'Fixed Asset', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '12102', 'name' => 'Motor Vehicles (Cost)', 'account_type' => 'ASSET', 'account_subtype' => 'Fixed Asset', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '12103', 'name' => 'Computers & IT Equipment (Cost)', 'account_type' => 'ASSET', 'account_subtype' => 'Fixed Asset', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '12104', 'name' => 'Furniture & Fittings (Cost)', 'account_type' => 'ASSET', 'account_subtype' => 'Fixed Asset', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '12200', 'name' => 'Accumulated Depreciation', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '12201', 'name' => 'Accum Depr – Land & Buildings', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '12202', 'name' => 'Accum Depr – Motor Vehicles', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '12203', 'name' => 'Accum Depr – IT Equipment', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '12204', 'name' => 'Accum Depr – Furniture', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '12300', 'name' => 'Intangible Assets', 'account_type' => 'ASSET', 'account_subtype' => 'Intangible', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '12301', 'name' => 'Software & Licences (Cost)', 'account_type' => 'ASSET', 'account_subtype' => 'Intangible', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '12302', 'name' => 'Accum Amortisation – Software', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],

            // LIABILITIES (2xxxx)
            ['gl_code' => '20000', 'name' => 'LIABILITIES', 'account_type' => 'LIABILITY', 'account_subtype' => 'Header', 'normal_balance' => 'CR', 'level' => 1, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '21000', 'name' => 'Current Liabilities', 'account_type' => 'LIABILITY', 'account_subtype' => 'Current Liability', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '21100', 'name' => 'Member Savings Liability', 'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '21101', 'name' => 'Mandatory Savings Deposits', 'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '21102', 'name' => 'Voluntary Savings Deposits', 'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '21103', 'name' => 'Fixed Deposits', 'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '21200', 'name' => 'Share Capital Subscriptions', 'account_type' => 'LIABILITY', 'account_subtype' => 'Share Capital', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '21300', 'name' => 'Accrued Interest on Savings', 'account_type' => 'LIABILITY', 'account_subtype' => 'Accrued Liability', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '21400', 'name' => 'Dividends Payable', 'account_type' => 'LIABILITY', 'account_subtype' => 'Accrued Liability', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '21500', 'name' => 'Accounts Payable & Accruals', 'account_type' => 'LIABILITY', 'account_subtype' => 'Payable', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '21600', 'name' => 'Tax Payable', 'account_type' => 'LIABILITY', 'account_subtype' => 'Payable', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '21601', 'name' => 'PAYE Payable', 'account_type' => 'LIABILITY', 'account_subtype' => 'Payable', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '21602', 'name' => 'VAT Payable', 'account_type' => 'LIABILITY', 'account_subtype' => 'Payable', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '21603', 'name' => 'Withholding Tax Payable', 'account_type' => 'LIABILITY', 'account_subtype' => 'Payable', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '22000', 'name' => 'Non-Current Liabilities', 'account_type' => 'LIABILITY', 'account_subtype' => 'Non-Current Liability', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '22100', 'name' => 'External Borrowings', 'account_type' => 'LIABILITY', 'account_subtype' => 'Borrowings', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '22101', 'name' => 'Bank Loans Payable', 'account_type' => 'LIABILITY', 'account_subtype' => 'Borrowings', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '22102', 'name' => 'MFI Apex Borrowings', 'account_type' => 'LIABILITY', 'account_subtype' => 'Borrowings', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],

            // EQUITY (3xxxx)
            ['gl_code' => '30000', 'name' => 'EQUITY / MEMBERS\' FUNDS', 'account_type' => 'EQUITY', 'account_subtype' => 'Header', 'normal_balance' => 'CR', 'level' => 1, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '31000', 'name' => 'Share Capital', 'account_type' => 'EQUITY', 'account_subtype' => 'Share Capital', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '31100', 'name' => 'Ordinary Share Capital', 'account_type' => 'EQUITY', 'account_subtype' => 'Share Capital', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '31200', 'name' => 'Share Premium', 'account_type' => 'EQUITY', 'account_subtype' => 'Share Capital', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '32000', 'name' => 'Reserves', 'account_type' => 'EQUITY', 'account_subtype' => 'Reserves', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '32100', 'name' => 'Statutory Reserve (SACCO Act)', 'account_type' => 'EQUITY', 'account_subtype' => 'Regulatory Reserve', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '32200', 'name' => 'Institutional Capital Reserve', 'account_type' => 'EQUITY', 'account_subtype' => 'Regulatory Reserve', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '32300', 'name' => 'General Reserve', 'account_type' => 'EQUITY', 'account_subtype' => 'General Reserve', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '32400', 'name' => 'Loan Loss Reserve', 'account_type' => 'EQUITY', 'account_subtype' => 'General Reserve', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '33000', 'name' => 'Retained Earnings / Surplus', 'account_type' => 'EQUITY', 'account_subtype' => 'Retained Earnings', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '33100', 'name' => 'Retained Earnings – Prior Years', 'account_type' => 'EQUITY', 'account_subtype' => 'Retained Earnings', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '33200', 'name' => 'Surplus/Deficit – Current Year', 'account_type' => 'EQUITY', 'account_subtype' => 'Retained Earnings', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '33300', 'name' => 'Dividends Declared', 'account_type' => 'EQUITY', 'account_subtype' => 'Retained Earnings', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '33900', 'name' => 'Opening Balance Control', 'account_type' => 'EQUITY', 'account_subtype' => 'Retained Earnings', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],

            // INCOME (4xxxx)
            ['gl_code' => '40000', 'name' => 'INCOME', 'account_type' => 'INCOME', 'account_subtype' => 'Header', 'normal_balance' => 'CR', 'level' => 1, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '41000', 'name' => 'Interest Income', 'account_type' => 'INCOME', 'account_subtype' => 'Operating Income', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '41100', 'name' => 'Interest on Personal Loans', 'account_type' => 'INCOME', 'account_subtype' => 'Loan Interest', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '41101', 'name' => 'Accrued Interest – Personal Loans', 'account_type' => 'INCOME', 'account_subtype' => 'Accrued Income', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '41102', 'name' => 'Cash Interest – Personal Loans', 'account_type' => 'INCOME', 'account_subtype' => 'Cash Income', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '41200', 'name' => 'Interest on Business Loans', 'account_type' => 'INCOME', 'account_subtype' => 'Loan Interest', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '41300', 'name' => 'Interest on Agriculture Loans', 'account_type' => 'INCOME', 'account_subtype' => 'Loan Interest', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '41400', 'name' => 'Penalty / Default Interest', 'account_type' => 'INCOME', 'account_subtype' => 'Loan Interest', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '42000', 'name' => 'Fee Income', 'account_type' => 'INCOME', 'account_subtype' => 'Operating Income', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '42100', 'name' => 'Loan Application Fees', 'account_type' => 'INCOME', 'account_subtype' => 'Fee Income', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '42200', 'name' => 'Loan Processing Fees', 'account_type' => 'INCOME', 'account_subtype' => 'Fee Income', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '42250', 'name' => 'Loan Charges Income', 'account_type' => 'INCOME', 'account_subtype' => 'Fee Income', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '42300', 'name' => 'Account Maintenance Fees', 'account_type' => 'INCOME', 'account_subtype' => 'Fee Income', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '42400', 'name' => 'Late Payment Penalties', 'account_type' => 'INCOME', 'account_subtype' => 'Fee Income', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '43000', 'name' => 'Other Operating Income', 'account_type' => 'INCOME', 'account_subtype' => 'Other Income', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '44000', 'name' => 'Investment Income', 'account_type' => 'INCOME', 'account_subtype' => 'Investment Income', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => false, 'is_postable' => true],

            // EXPENSES (5xxxx)
            ['gl_code' => '50000', 'name' => 'EXPENSES', 'account_type' => 'EXPENSE', 'account_subtype' => 'Header', 'normal_balance' => 'DR', 'level' => 1, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '51000', 'name' => 'Financial Expenses', 'account_type' => 'EXPENSE', 'account_subtype' => 'Financial Cost', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '51100', 'name' => 'Interest Expense on Savings', 'account_type' => 'EXPENSE', 'account_subtype' => 'Financial Cost', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '51200', 'name' => 'Interest Expense on Borrowings', 'account_type' => 'EXPENSE', 'account_subtype' => 'Financial Cost', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '51300', 'name' => 'ECL Provision Expense (IFRS 9)', 'account_type' => 'EXPENSE', 'account_subtype' => 'Provision Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '51301', 'name' => 'ECL Charge – Stage 1', 'account_type' => 'EXPENSE', 'account_subtype' => 'Provision Expense', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '51302', 'name' => 'ECL Charge – Stage 2', 'account_type' => 'EXPENSE', 'account_subtype' => 'Provision Expense', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '51303', 'name' => 'ECL Charge – Stage 3', 'account_type' => 'EXPENSE', 'account_subtype' => 'Provision Expense', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '51304', 'name' => 'Loan Write-off Expense', 'account_type' => 'EXPENSE', 'account_subtype' => 'Provision Expense', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '52000', 'name' => 'Staff Costs', 'account_type' => 'EXPENSE', 'account_subtype' => 'Operating Expense', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '52100', 'name' => 'Salaries & Wages', 'account_type' => 'EXPENSE', 'account_subtype' => 'Staff Cost', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '52200', 'name' => 'NSSF Contributions', 'account_type' => 'EXPENSE', 'account_subtype' => 'Staff Cost', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '52300', 'name' => 'Staff Training', 'account_type' => 'EXPENSE', 'account_subtype' => 'Staff Cost', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '52400', 'name' => 'Medical & Health Insurance', 'account_type' => 'EXPENSE', 'account_subtype' => 'Staff Cost', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '53000', 'name' => 'Administrative Expenses', 'account_type' => 'EXPENSE', 'account_subtype' => 'Operating Expense', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '53100', 'name' => 'Rent & Occupancy', 'account_type' => 'EXPENSE', 'account_subtype' => 'Admin Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '53200', 'name' => 'Utilities', 'account_type' => 'EXPENSE', 'account_subtype' => 'Admin Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '53300', 'name' => 'Communications & Internet', 'account_type' => 'EXPENSE', 'account_subtype' => 'Admin Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '53400', 'name' => 'Audit & Professional Fees', 'account_type' => 'EXPENSE', 'account_subtype' => 'Admin Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '53500', 'name' => 'Regulatory Fees & Levies', 'account_type' => 'EXPENSE', 'account_subtype' => 'Admin Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '53600', 'name' => 'Board Allowances', 'account_type' => 'EXPENSE', 'account_subtype' => 'Admin Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '54000', 'name' => 'Depreciation & Amortisation', 'account_type' => 'EXPENSE', 'account_subtype' => 'Non-Cash Expense', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
            ['gl_code' => '54100', 'name' => 'Depreciation – Buildings', 'account_type' => 'EXPENSE', 'account_subtype' => 'Non-Cash Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '54200', 'name' => 'Depreciation – Motor Vehicles', 'account_type' => 'EXPENSE', 'account_subtype' => 'Non-Cash Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '54300', 'name' => 'Depreciation – IT Equipment', 'account_type' => 'EXPENSE', 'account_subtype' => 'Non-Cash Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '54400', 'name' => 'Amortisation – Software', 'account_type' => 'EXPENSE', 'account_subtype' => 'Non-Cash Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '55000', 'name' => 'Other Operating Expenses', 'account_type' => 'EXPENSE', 'account_subtype' => 'Operating Expense', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => false, 'is_postable' => true],
            ['gl_code' => '56000', 'name' => 'Tax Expense', 'account_type' => 'EXPENSE', 'account_subtype' => 'Tax', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => false, 'is_postable' => true],
        ];
    }
}
