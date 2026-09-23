<?php

namespace App\Imports;

use App\Models\Member;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Services\SavingsAccountService;
use App\Tenant\Modules\Settings\Models\OnboardingSettings;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;

class MembersImport implements SkipsEmptyRows, ToCollection, WithStartRow
{
    public array $errors = [];

    public int $imported = 0;

    /**
     * Data starts at row 3 (row 1 = instructions, row 2 = headers).
     */
    public function startRow(): int
    {
        return 3;
    }

    public function collection(Collection $rows): void
    {
        $onboarding = OnboardingSettings::current();
        $defaultStatus = $onboarding->require_member_approval ? 'pending' : 'active';
        $autoCreateSavingsAccount = $onboarding->auto_create_savings_account;
        $registeredBy = Auth::id();

        $savingsService = app(SavingsAccountService::class);
        // Always load the default product — needed when savings_balance or account_number is provided
        $defaultProduct = SavingsProduct::where('name', 'General Savings Account')->first();

        // Compute starting member number once before the loop
        $lastMember = Member::withTrashed()->orderBy('id', 'desc')->first();
        $nextNum = $lastMember ? ((int) substr($lastMember->member_number, 4)) + 1 : 1;
        $seenPhones = [];
        $seenEmails = [];

        foreach ($rows as $index => $row) {
            $excelRow = $index + 3;

            // Map by column index (0-based, matching template column order A–O)
            $name = trim((string) ($row[0] ?? ''));   // A: Name
            $phone = trim((string) ($row[1] ?? ''));   // B: Phone
            $gender = strtolower(trim((string) ($row[2] ?? '')));  // C: Gender
            $marital = strtolower(trim((string) ($row[3] ?? '')));  // D: Marital Status
            $nationality = trim((string) ($row[4] ?? '')) ?: 'Uganda';  // E: Nationality
            $address = trim((string) ($row[5] ?? ''));   // F: Address
            $email = trim((string) ($row[6] ?? '')) ?: null;      // G: Email
            $dobRaw = trim((string) ($row[7] ?? ''));   // H: Date of Birth
            $otherContact = trim((string) ($row[8] ?? '')) ?: null;      // I: Other Contact
            $initialDeposit = $row[9] ?? null;                              // J: Initial Deposit
            $joinedRaw = trim((string) ($row[10] ?? ''));  // K: Date Joined
            $savingsBalance = $row[11] ?? null;                             // L: Savings Balance
            $sharesQty = $row[12] ?? null;                             // M: Shares Quantity
            $accountNumber = trim((string) ($row[13] ?? '')) ?: null;     // N: Account Number
            $employeeNumber = trim((string) ($row[14] ?? '')) ?: null;     // O: Employee Number

            // Skip fully empty rows
            if (! $name && ! $phone) {
                continue;
            }

            // Validate required fields
            $missing = [];
            if (! $name) {
                $missing[] = 'Name';
            }
            if (! $phone) {
                $missing[] = 'Phone';
            }
            if (! $gender) {
                $missing[] = 'Gender';
            }
            if (! $marital) {
                $missing[] = 'Marital Status';
            }
            if (! $address) {
                $missing[] = 'Address';
            }

            if ($missing) {
                $this->errors[] = "Row {$excelRow}: Missing required field(s): ".implode(', ', $missing).'.';

                continue;
            }

            if (! in_array($gender, ['male', 'female', 'other'])) {
                $this->errors[] = "Row {$excelRow}: Invalid gender '{$gender}'. Allowed: male, female, other.";

                continue;
            }

            if (! in_array($marital, ['single', 'married', 'divorced', 'widowed'])) {
                $this->errors[] = "Row {$excelRow}: Invalid marital status '{$marital}'. Allowed: single, married, divorced, widowed.";

                continue;
            }

            // Skip duplicates — check both the DB and the current batch
            if (in_array($phone, $seenPhones) || Member::where('phone', $phone)->exists()) {
                $this->errors[] = "Row {$excelRow}: Skipped — phone '{$phone}' is already registered.";

                continue;
            }
            if ($email && (in_array($email, $seenEmails) || Member::where('email', $email)->exists())) {
                $this->errors[] = "Row {$excelRow}: Skipped — email '{$email}' is already registered.";

                continue;
            }
            $seenPhones[] = $phone;
            if ($email) {
                $seenEmails[] = $email;
            }

            // Parse dates
            $dob = $this->parseDate($dobRaw);
            $joinedAt = $this->parseDate($joinedRaw);

            $memberNumber = 'MBR-'.str_pad($nextNum, 5, '0', STR_PAD_LEFT);

            try {
                $member = Member::create([
                    'member_number' => $memberNumber,
                    'name' => $name,
                    'phone' => $phone,
                    'gender' => $gender,
                    'marital_status' => $marital,
                    'nationality' => $nationality,
                    'address' => $address,
                    'email' => $email,
                    'dob' => $dob,
                    'other_contact' => $otherContact,
                    'initial_deposit' => is_numeric($initialDeposit) ? (float) $initialDeposit : null,
                    'opening_balance' => is_numeric($savingsBalance) ? (float) $savingsBalance : null,
                    'shares_quantity' => is_numeric($sharesQty) ? (int) $sharesQty : null,
                    'account_number' => $accountNumber,
                    'employee_number' => $employeeNumber,
                    'joined_at' => $joinedAt,
                    'status' => $defaultStatus,
                    'password' => Hash::make('password123'),
                    'registered_by' => $registeredBy,
                ]);

                // Auto-create savings account when:
                //  a) the onboarding setting is on AND initial_deposit > 0, OR
                //  b) savings_balance or account_number is provided (migration import with existing balance)
                $hasSavingsData = (is_numeric($savingsBalance) && (float) $savingsBalance > 0) || $accountNumber;
                $shouldCreateAccount = $defaultProduct && (
                    ($autoCreateSavingsAccount && is_numeric($initialDeposit) && (float) $initialDeposit > 0)
                    || $hasSavingsData
                );

                if ($shouldCreateAccount) {
                    // Prefer savings_balance as the opening balance; fall back to initial_deposit
                    $openingBalance = (is_numeric($savingsBalance) && (float) $savingsBalance > 0)
                        ? (float) $savingsBalance
                        : (is_numeric($initialDeposit) ? (float) $initialDeposit : 0);

                    try {
                        $accountData = [
                            'member_id' => $member->id,
                            'savings_product_id' => $defaultProduct->id,
                            'account_type' => 'voluntary',
                            'is_new_account' => true,
                            'initial_deposit' => $openingBalance,
                            'consider_min_balance' => false,
                            'status' => 'active',
                        ];

                        // Use the provided account number as account_no if supplied
                        if ($accountNumber) {
                            $accountData['account_no'] = $accountNumber;
                        }

                        $savingsService->create($accountData);
                    } catch (\Exception $e) {
                        $this->errors[] = "Row {$excelRow}: Member created but savings account failed — {$e->getMessage()}";
                    }
                }

                $this->imported++;
                $nextNum++;
            } catch (\Exception $e) {
                $this->errors[] = "Row {$excelRow}: Failed to create member — {$e->getMessage()}";
            }
        }
    }

    private function parseDate(string $raw): ?string
    {
        if (! $raw) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $raw)->toDateString();
        } catch (\Exception) {
            return null;
        }
    }
}
