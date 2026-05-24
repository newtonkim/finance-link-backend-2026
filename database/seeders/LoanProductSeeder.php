<?php

namespace Database\Seeders;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Seeds the five standard SACCO loan products.
 *
 * GL accounts are resolved by gl_code so the seeder is portable across
 * every new tenant database regardless of the auto-increment IDs assigned
 * during the Chart-of-Accounts seeding step.
 *
 * Products seeded:
 *   DVLP-001  Development / Normal Loan        (reducing balance, 12–60 months)
 *   EMRG-001  Emergency / Short-Term Loan      (flat rate,         1–6  months)
 *   BIZ-001   Business / Enterprise Loan       (reducing balance,  6–36 months)
 *   EDU-001   School Fees / Education Loan     (reducing balance,  3–12 months)
 *   ASST-001  Asset / Mortgage Loan            (reducing balance, 24–120 months)
 */
class LoanProductSeeder extends Seeder
{
    // ─── GL code → account_id resolution cache ────────────────────────────────

    /** @var array<string, int|null> */
    private array $glCache = [];

    // ─── GL code constants (from SaccoCoaSeeder / SACCO_UGANDA template) ─────

    // Loan portfolio asset accounts
    private const GL_PERSONAL_LOANS = '11301'; // Personal / Consumer Loans

    private const GL_BUSINESS_LOANS = '11302'; // Business Loans

    // Disbursement (cash-out) account
    private const GL_LOAN_DISBURSEMENT = '11103'; // Cash at Bank – Loan Disbursement

    // Receivable asset accounts
    private const GL_INTEREST_RECEIVABLE = '11500'; // Interest Receivable

    private const GL_PENALTY_RECEIVABLE = '11600'; // Penalty Receivable

    // Income accounts
    private const GL_INTEREST_PERSONAL = '41102'; // Cash Interest – Personal Loans

    private const GL_INTEREST_BUSINESS = '41200'; // Interest on Business Loans

    private const GL_PENALTY_DEFAULT = '41400'; // Penalty / Default Interest

    private const GL_PROCESSING_FEES = '42200'; // Loan Processing Fees

    private const GL_CHARGES_INCOME = '42250'; // Loan Charges Income

    private const GL_CHARGES_RECEIVABLE = '11700'; // Charges Receivable

    // ─── Entry point ─────────────────────────────────────────────────────────

    public function run(): void
    {
        foreach ($this->products() as $product) {
            LoanProduct::updateOrCreate(
                ['code' => $product['code']],
                $product,
            );
        }

        $this->command?->info('  ✓ LoanProductSeeder: 5 loan products seeded.');
    }

    // ─── Product definitions ─────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function products(): array
    {
        return [
            $this->developmentLoan(),
            $this->emergencyLoan(),
            $this->businessLoan(),
            $this->educationLoan(),
            $this->assetMortgageLoan(),
        ];
    }

    // ── 1. Development / Normal Loan ─────────────────────────────────────────

    private function developmentLoan(): array
    {
        return [
            'code' => 'DVLP-001',
            'name' => 'Development Loan',
            'description' => 'General personal development loan for buying land, building, starting or expanding a business, school fees, and other personal needs. Most popular product. Amount based on up to 3× member savings.',

            // ─── Limits ──────────────────────────────────────────────────────
            'min_amount' => 100_000.00,
            'max_amount' => 5_000_000.00,
            'exposure_limit' => 10_000_000.00,

            // ─── Interest ────────────────────────────────────────────────────
            'interest_rate' => 1.50,   // 1.5% per month → 18% p.a.
            'interest_method' => 'reducing_balance',
            'repayment_structure' => 'equal_installment',
            'interest_period' => 'monthly',

            // ─── Term & cycle ─────────────────────────────────────────────────
            'loan_duration' => 60,     // maximum term (months)
            'duration_type' => 'months',
            'repayment_cycle' => 'monthly',

            // ─── Fees ────────────────────────────────────────────────────────
            'processing_fee_type' => 'percentage',
            'processing_fee_value' => 1.00,  // 1% of principal

            // ─── Penalty ─────────────────────────────────────────────────────
            'penalty_type' => 'percentage_per_day',
            'penalty_rate' => 0.10,  // 0.1% per day on outstanding
            'grace_period' => 7,     // days before penalty kicks in

            // ─── Eligibility ─────────────────────────────────────────────────
            'min_membership_months' => 6,
            'savings_appraisal_threshold' => 0.33,  // loan ≤ 3× savings
            'min_guarantors' => 2,
            'max_guarantors' => 4,

            // ─── Risk & workflow ─────────────────────────────────────────────
            'warning_days' => 14,
            'arrears_action' => 'flag',
            'requires_approval' => true,
            'allow_top_up' => true,
            'allow_reschedule' => true,
            'allow_sub_schedule' => false,
            'max_securities' => 4,
            'security_value_percentage' => 100.00,

            // ─── GL mapping ──────────────────────────────────────────────────
            'loan_portfolio_account_id' => $this->gl(self::GL_PERSONAL_LOANS),
            'disbursement_account_id' => $this->gl(self::GL_LOAN_DISBURSEMENT),
            'interest_income_account_id' => $this->gl(self::GL_INTEREST_PERSONAL),
            'interest_receivable_account_id' => $this->gl(self::GL_INTEREST_RECEIVABLE),
            'penalty_income_account_id' => $this->gl(self::GL_PENALTY_DEFAULT),
            'penalty_receivable_account_id' => $this->gl(self::GL_PENALTY_RECEIVABLE),
            'charges_income_account_id' => $this->gl(self::GL_CHARGES_INCOME),
            'charges_receivable_account_id' => $this->gl(self::GL_CHARGES_RECEIVABLE),

            'is_active' => true,
            'system_type' => 'system',
        ];
    }

    // ── 2. Emergency / Short-Term Loan ───────────────────────────────────────

    private function emergencyLoan(): array
    {
        return [
            'code' => 'EMRG-001',
            'name' => 'Emergency Loan',
            'description' => 'Fast-tracked loan for urgent and unexpected needs — medical bills, funeral expenses, urgent repairs, and calamities. Disbursed within 24–48 hours. Minimal documentation required.',

            // ─── Limits ──────────────────────────────────────────────────────
            'min_amount' => 50_000.00,
            'max_amount' => 2_000_000.00,
            'exposure_limit' => 4_000_000.00,

            // ─── Interest ────────────────────────────────────────────────────
            'interest_rate' => 7.00,   // 7% flat for the full term
            'interest_method' => 'flat',
            'repayment_structure' => 'equal_installment',
            'interest_period' => 'monthly',

            // ─── Term & cycle ─────────────────────────────────────────────────
            'loan_duration' => 6,      // maximum 6 months
            'duration_type' => 'months',
            'repayment_cycle' => 'monthly',

            // ─── Fees ────────────────────────────────────────────────────────
            'processing_fee_type' => 'flat',
            'processing_fee_value' => 5_000.00, // fixed UGX 5,000

            // ─── Penalty ─────────────────────────────────────────────────────
            'penalty_type' => 'percentage',
            'penalty_rate' => 5.00,   // 5% of outstanding on first missed payment
            'grace_period' => 3,

            // ─── Eligibility ─────────────────────────────────────────────────
            'min_membership_months' => 3,
            'savings_appraisal_threshold' => 1.00,   // loan ≤ 1× savings
            'min_guarantors' => 1,
            'max_guarantors' => 2,

            // ─── Risk & workflow ─────────────────────────────────────────────
            'warning_days' => 5,
            'arrears_action' => 'flag',
            'requires_approval' => true,
            'allow_top_up' => false,
            'allow_reschedule' => false,
            'allow_sub_schedule' => false,
            'max_securities' => 2,
            'security_value_percentage' => 100.00,

            // ─── GL mapping ──────────────────────────────────────────────────
            'loan_portfolio_account_id' => $this->gl(self::GL_PERSONAL_LOANS),
            'disbursement_account_id' => $this->gl(self::GL_LOAN_DISBURSEMENT),
            'interest_income_account_id' => $this->gl(self::GL_INTEREST_PERSONAL),
            'interest_receivable_account_id' => $this->gl(self::GL_INTEREST_RECEIVABLE),
            'penalty_income_account_id' => $this->gl(self::GL_PENALTY_DEFAULT),
            'penalty_receivable_account_id' => $this->gl(self::GL_PENALTY_RECEIVABLE),
            'charges_income_account_id' => $this->gl(self::GL_CHARGES_INCOME),
            'charges_receivable_account_id' => $this->gl(self::GL_CHARGES_RECEIVABLE),

            'is_active' => true,
            'system_type' => 'system',
        ];
    }

    // ── 3. Business / Enterprise Loan ────────────────────────────────────────

    private function businessLoan(): array
    {
        return [
            'code' => 'BIZ-001',
            'name' => 'Business / Enterprise Loan',
            'description' => 'Tailored for income-generating activities and SME financing. Covers stock and inventory, equipment, working capital, and trade financing. Repayment can be structured around business cycles. Requires a business plan and cash-flow projections.',

            // ─── Limits ──────────────────────────────────────────────────────
            'min_amount' => 500_000.00,
            'max_amount' => 20_000_000.00,
            'exposure_limit' => 40_000_000.00,

            // ─── Interest ────────────────────────────────────────────────────
            'interest_rate' => 2.50,   // 2.5% per month → 30% p.a.
            'interest_method' => 'reducing_balance',
            'repayment_structure' => 'equal_installment',
            'interest_period' => 'monthly',

            // ─── Term & cycle ─────────────────────────────────────────────────
            'loan_duration' => 36,     // maximum 36 months
            'duration_type' => 'months',
            'repayment_cycle' => 'monthly',

            // ─── Fees ────────────────────────────────────────────────────────
            'processing_fee_type' => 'percentage',
            'processing_fee_value' => 2.00,  // 2% of principal

            // ─── Penalty ─────────────────────────────────────────────────────
            'penalty_type' => 'percentage_per_day',
            'penalty_rate' => 0.15,  // 0.15% per day on outstanding
            'grace_period' => 7,

            // ─── Eligibility ─────────────────────────────────────────────────
            'min_membership_months' => 6,
            'savings_appraisal_threshold' => 0.20,  // loan ≤ 5× savings (looser — cash-flow driven)
            'min_guarantors' => 2,
            'max_guarantors' => 5,

            // ─── Risk & workflow ─────────────────────────────────────────────
            'warning_days' => 14,
            'arrears_action' => 'flag',
            'requires_approval' => true,
            'allow_top_up' => true,
            'allow_reschedule' => true,
            'allow_sub_schedule' => true,  // supports seasonal re-structuring
            'max_securities' => 5,
            'security_value_percentage' => 80.00, // lend up to 80% of security value

            // ─── GL mapping ──────────────────────────────────────────────────
            'loan_portfolio_account_id' => $this->gl(self::GL_BUSINESS_LOANS),
            'disbursement_account_id' => $this->gl(self::GL_LOAN_DISBURSEMENT),
            'interest_income_account_id' => $this->gl(self::GL_INTEREST_BUSINESS),
            'interest_receivable_account_id' => $this->gl(self::GL_INTEREST_RECEIVABLE),
            'penalty_income_account_id' => $this->gl(self::GL_PENALTY_DEFAULT),
            'penalty_receivable_account_id' => $this->gl(self::GL_PENALTY_RECEIVABLE),
            'charges_income_account_id' => $this->gl(self::GL_CHARGES_INCOME),
            'charges_receivable_account_id' => $this->gl(self::GL_CHARGES_RECEIVABLE),

            'is_active' => true,
            'system_type' => 'system',
        ];
    }

    // ── 4. School Fees / Education Loan ──────────────────────────────────────

    private function educationLoan(): array
    {
        return [
            'code' => 'EDU-001',
            'name' => 'School Fees / Education Loan',
            'description' => 'Subsidised loan specifically for education-related costs at all levels — tuition, accommodation, school supplies, and university fees. Term aligned to school terms. Disbursed directly to institutions where possible.',

            // ─── Limits ──────────────────────────────────────────────────────
            'min_amount' => 100_000.00,
            'max_amount' => 3_000_000.00,
            'exposure_limit' => 6_000_000.00,

            // ─── Interest ────────────────────────────────────────────────────
            'interest_rate' => 1.25,   // 1.25% per month (subsidised)
            'interest_method' => 'reducing_balance',
            'repayment_structure' => 'equal_installment',
            'interest_period' => 'monthly',

            // ─── Term & cycle ─────────────────────────────────────────────────
            'loan_duration' => 12,     // maximum 12 months (3 school terms)
            'duration_type' => 'months',
            'repayment_cycle' => 'monthly',

            // ─── Fees ────────────────────────────────────────────────────────
            'processing_fee_type' => 'flat',
            'processing_fee_value' => 10_000.00, // fixed UGX 10,000

            // ─── Penalty ─────────────────────────────────────────────────────
            'penalty_type' => 'percentage',
            'penalty_rate' => 1.50,  // 1.5% on outstanding balance
            'grace_period' => 5,

            // ─── Eligibility ─────────────────────────────────────────────────
            'min_membership_months' => 3,
            'savings_appraisal_threshold' => 0.50,  // loan ≤ 2× savings
            'min_guarantors' => 1,
            'max_guarantors' => 3,

            // ─── Risk & workflow ─────────────────────────────────────────────
            'warning_days' => 7,
            'arrears_action' => 'flag',
            'requires_approval' => true,
            'allow_top_up' => false,
            'allow_reschedule' => false,
            'allow_sub_schedule' => false,
            'max_securities' => 2,
            'security_value_percentage' => 100.00,

            // ─── GL mapping ──────────────────────────────────────────────────
            'loan_portfolio_account_id' => $this->gl(self::GL_PERSONAL_LOANS),
            'disbursement_account_id' => $this->gl(self::GL_LOAN_DISBURSEMENT),
            'interest_income_account_id' => $this->gl(self::GL_INTEREST_PERSONAL),
            'interest_receivable_account_id' => $this->gl(self::GL_INTEREST_RECEIVABLE),
            'penalty_income_account_id' => $this->gl(self::GL_PENALTY_DEFAULT),
            'penalty_receivable_account_id' => $this->gl(self::GL_PENALTY_RECEIVABLE),
            'charges_income_account_id' => $this->gl(self::GL_CHARGES_INCOME),
            'charges_receivable_account_id' => $this->gl(self::GL_CHARGES_RECEIVABLE),

            'is_active' => true,
            'system_type' => 'system',
        ];
    }

    // ── 5. Asset / Mortgage Loan ─────────────────────────────────────────────

    private function assetMortgageLoan(): array
    {
        return [
            'code' => 'ASST-001',
            'name' => 'Asset / Mortgage Loan',
            'description' => 'Long-term financing for high-value asset acquisition and improvement — land purchase, home construction or renovation, vehicle purchase, and equipment. Secured by title deed or logbook. Requires valuation report and comprehensive insurance. Lower monthly instalment due to longer tenure.',

            // ─── Limits ──────────────────────────────────────────────────────
            'min_amount' => 1_000_000.00,
            'max_amount' => 50_000_000.00,
            'exposure_limit' => 100_000_000.00,

            // ─── Interest ────────────────────────────────────────────────────
            'interest_rate' => 1.75,   // 1.75% per month → 21% p.a.
            'interest_method' => 'reducing_balance',
            'repayment_structure' => 'equal_installment',
            'interest_period' => 'monthly',

            // ─── Term & cycle ─────────────────────────────────────────────────
            'loan_duration' => 120,    // maximum 120 months (10 years)
            'duration_type' => 'months',
            'repayment_cycle' => 'monthly',

            // ─── Fees ────────────────────────────────────────────────────────
            'processing_fee_type' => 'percentage',
            'processing_fee_value' => 1.50,  // 1.5% of principal

            // ─── Penalty ─────────────────────────────────────────────────────
            'penalty_type' => 'percentage_per_day',
            'penalty_rate' => 0.08,  // 0.08% per day (lower — secured loan)
            'grace_period' => 10,    // 10-day grace (longer — title deed security)

            // ─── Eligibility ─────────────────────────────────────────────────
            'min_membership_months' => 12,    // must be a long-standing member
            'savings_appraisal_threshold' => 0.10,  // loan ≤ 10× savings (collateral-driven)
            'min_guarantors' => 2,
            'max_guarantors' => 4,

            // ─── Risk & workflow ─────────────────────────────────────────────
            'warning_days' => 21,    // 3-week warning before penalty
            'arrears_action' => 'flag',
            'requires_approval' => true,
            'allow_top_up' => false,
            'allow_reschedule' => true,
            'allow_sub_schedule' => false,
            'max_securities' => 3,
            'security_value_percentage' => 70.00, // lend up to 70% of asset value (LTV ratio)

            // ─── GL mapping ──────────────────────────────────────────────────
            'loan_portfolio_account_id' => $this->gl(self::GL_PERSONAL_LOANS),
            'disbursement_account_id' => $this->gl(self::GL_LOAN_DISBURSEMENT),
            'interest_income_account_id' => $this->gl(self::GL_INTEREST_PERSONAL),
            'interest_receivable_account_id' => $this->gl(self::GL_INTEREST_RECEIVABLE),
            'penalty_income_account_id' => $this->gl(self::GL_PENALTY_DEFAULT),
            'penalty_receivable_account_id' => $this->gl(self::GL_PENALTY_RECEIVABLE),
            'charges_income_account_id' => $this->gl(self::GL_CHARGES_INCOME),
            'charges_receivable_account_id' => $this->gl(self::GL_CHARGES_RECEIVABLE),

            'is_active' => true,
            'system_type' => 'system',
        ];
    }

    // ─── GL account resolver ──────────────────────────────────────────────────

    /**
     * Resolve a GL code to its integer primary key in the tenant database.
     *
     * Caches results so each code is queried only once per seeder run.
     * Logs a warning (rather than throwing) if the account is not found so
     * the seeder does not abort — the admin can fix GL mappings later.
     */
    private function gl(string $glCode): ?int
    {
        if (array_key_exists($glCode, $this->glCache)) {
            return $this->glCache[$glCode];
        }

        $account = ChartOfAccount::where('gl_code', $glCode)->first();

        if (! $account) {
            Log::warning("LoanProductSeeder: GL account '{$glCode}' not found — product GL mapping will be null.");
            $this->command?->warn("  ⚠ GL code {$glCode} not found in Chart of Accounts.");
        }

        return $this->glCache[$glCode] = $account?->id;
    }
}
