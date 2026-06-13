<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Exports\MembersTemplateExport;
use App\Http\Requests\Tenant\MemberFormRequest;
use App\Imports\MembersImport;
use App\Models\Member;
use App\Tenant\Http\Resources\MemberResource;
use App\Tenant\Http\Resources\SavingsAccountResource;
use App\Tenant\Http\Resources\SavingsProductResource;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Members\Models\MemberCharge;
use App\Tenant\Modules\Members\Services\MemberChargeService;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Services\SavingsAccountService;
use App\Tenant\Modules\Settings\Models\OnboardingSettings;
use App\Tenant\Modules\Charges\Contracts\ChargeCalculatorServiceInterface;
use App\Tenant\Modules\Shares\Contracts\ShareAccountingServiceInterface;
use App\Tenant\Modules\Shares\Models\Share;
use App\Tenant\Services\MemberService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class MemberController extends MemberService
{
    public function __construct(
        protected SavingsAccountService $savingsAccountService,
        protected SavingsJournalService $savingsJournal,
        protected MemberChargeService $memberChargeService,
        protected ShareAccountingServiceInterface $shareAccountingService,
        protected ChargeCalculatorServiceInterface $chargeCalculator,
    ) {}

  
    public function member_saving_accounts_drop_down_list()
    {
        return $this->Response([
            'data' => self::MemberSavingAccountsDropDownList(),
        ]);
    }
    public function download_members_opening_balance_import_template()
    {
        return $this->Response([
            'data' => self::downloadMemberImportTemplate(),
        ]);
    }
    public function general_product_charges_drop_down_list()
    {
        return $this->Response([
            'data' => self::GeneralProductChargesDropDownList(),
        ]);
    }
    public function unarchive_members_action()
    {
        return $this->Response([
            'data' => self::unarchiveMember(),
        ]);
    }

    // public function import_opening_balance()
    // {
    //     return $this->Response([
    //         'data' => self::importOpeningBalance(),
    //     ]);
    // }

    public function charge_member_status()
    {
        return $this->Response([
            'data' => self::chargeMemberStatus(),
        ]);
    }

    public function member_drop_down_list_total_balance_accounts()
    {
        return $this->Response([
            'data' => self::MemberAndAccountBalanceDropDownList(),
        ]);
    }

    public function member_drop_down_list()
    {
        return $this->Response([
            'data' => self::MemberDropDownList(),
        ]);
    }

    public function member_share_total_drop_down_list()
    {
        return $this->Response([
            'data' => self::MemberShareNoDropDownList(),
        ]);
    }

    public function members_create()
    {
        return $this->Response([
            'data' => self::createNewSaccoMemebers(),
        ]);
    }

    public function import_member_data_excel()
    {
        return $this->Response([
            'data' => self::importMemberViaExcel(),
        ]);
    }

    public function members_delete()
    {
        return $this->Response([
            'data' => self::dormantMemebers(),
        ]);
    }

    public function get_members_list()
    {
        return $this->Response([
            'data' => self::membersList(),
        ]);
    }

    public function edit_members_details()
    {
        return $this->Response([
            'data' => self::editMemberDetails(),
        ]);
    }

    public function print_member_list()
    {
        return $this->Response(['data' => self::printMemberList()]);
    }

    public function get_members_details()
    {
        return $this->Response(['data' => self::membersDetails()]);
    }

    public function profile_completeness()
    {
        return $this->Response(['data' => self::profileCompleteness()]);
    }

    public function savings_products()
    {
        return $this->Response(['data' => self::savingsProducts()]);
    }

    public function get_product_charges()
    {
        return $this->Response(['data' => self::productCharges()]);
    }

    public function download_member_template_keys()
    {
        return $this->Response(['data' => self::seleceTableKeysToUseOnTemplate()]);
    }

    /**
     * Display a listing of members.
     */
    public function create()
    {
        // // request()->validate([
        // //     'full_name' => 'required|string|max:255',
        // //     'primary_contact' => 'required|string|max:255',
        // //     'other_contacts' => 'required|string|max:255',
        // //     'mobile_money_number' => 'required|string|max:255',
        // //     'email' => 'required|email|max:255',
        // //     'national_id' => 'required|string|max:255',
        // //     'marital_status' => 'required|string|max:255',
        // //     'nationality' => 'required|string|max:255',
        // //     'address' => 'required|string|max:255',
        // //     'profile_picture' => 'required|image|max:2048',
        // //     'next_of_kin' => 'required|string|max:255',
        // //     'next_of_kin_contact' => 'required|string|max:255',
        // //     'primary_contact' => 'nullable|string|max:255',
        // //     'initial_deposit' => 'nullable|string|max:255',
        // //     'joined_date' => 'nullable|string|max:255',
        // //     'referred_by' => 'nullable|string|max:255',
        // //     'member_type' => 'required|in:existing_member,new_member',
        // // ]);

        //     $getMemebrSettings = DB::table('system_settings')
        //     ->whereIn('settings_names', [
        //         "sacco-members-Require-approval-before-members-becomes-active",
        //         "sacco-member-save-and-saving-account-at-once",
        //         "sacco-member-code-prefix",
        //         "sacco-member-code-segment-length",
        //         "sacco-member-code-auto-generate",
        //         "sacco-member-free-input-code",
        //         'system-default-code',
        //         'system-max-code',
        //         'sacco-member-code-str-pad',
        //         'sacco-member-save-and-saving-account-at-once',
        //         'sacco-members-Require-approval-before-members-becomes-active'])
        //     ->where('settings_status', 'active')
        //     ->get(['settings_name', 'settings_action']);

    }

    public function index(Request $request)
    {
        $query = Member::query()->orderBy('created_at', 'desc');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('member_number', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $members = $query->paginate(15)->withQueryString();

        return MemberResource::collection($members);
    }

    /**
     * Get data for creating a new member.
     */
    // public function create()
    // {
    //     $savingsAccounts = SavingsAccount::select('id', 'account_no', 'account_type')
    //         ->where('status', 'active')
    //         ->get();

    //     $savingsProducts = SavingsProduct::with('charges')->where('status', 'active')
    //         ->get(['id', 'name', 'type', 'minimum_balance']);

    //     $staff = \App\Models\Staff::where('status', 'active')
    //         ->orderBy('name')
    //         ->get(['id', 'name', 'role']);

    //     return response()->json([
    //         'success' => 200,
    //         'message' => 'Registration options retrieved successfully.',
    //         'data' => [
    //             'savingsAccounts' => SavingsAccountResource::collection($savingsAccounts),
    //             'savingsProducts' => SavingsProductResource::collection($savingsProducts),
    //             'staff' => $staff,
    //         ],
    //     ]);
    // }

    /**
     * Store a newly created member.
     */
    public function store(MemberFormRequest $request)
    {
        $validated = $request->validated();
        $isExisting = $validated['member_type'] === 'existing_member';

        // Generate member number
        $lastMember = Member::withTrashed()->orderBy('id', 'desc')->first();
        $nextNumber = $lastMember ? ((int) substr($lastMember->member_number, 4)) + 1 : 1;
        $validated['member_number'] = 'MBR-'.str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
        $validated['code'] = $validated['member_number'];

        // Set default password (member can change later)
        $validated['password'] = Hash::make('password123');

        // Load onboarding settings to determine initial status
        $onboardingForStatus = OnboardingSettings::current();
        $validated['status'] = $onboardingForStatus->require_member_approval ? 'pending' : 'active';
        $validated['registered_by'] = Auth::id();
        // referred_by comes from the request (the staff who recruited the member); keep null if not provided
        if (empty($validated['referred_by'])) {
            $validated['referred_by'] = null;
        }

        if ($request->hasFile('avatar')) {
            $validated['profile_picture'] = $request->file('avatar')->store('avatars', 'public');
        }

        // Extract savings-related data before creating member
        $savingsProductId = $validated['savings_product_id'] ?? null;
        $openingBalance = $validated['opening_balance'] ?? 0;
        $sharesQuantity = $validated['shares_quantity'] ?? null;
        unset($validated['savings_product_id'], $validated['opening_balance'], $validated['shares_quantity']);

        // Re-use already-loaded settings (avoid double query)
        $onboarding = $onboardingForStatus;
        $sharesCompulsory = $onboarding->shares_compulsory;
        $appliesToExisting = $onboarding->shares_compulsory_applies_to_existing;
        $sharePrice = (float) $onboarding->share_price;
        $autoCreateSavingsAccount = $onboarding->auto_create_savings_account;

        // Resolve product-scoped registration charges for the chosen product and
        // reject early if the initial deposit doesn't cover them. Done OUTSIDE the
        // transaction so a 422 doesn't open and roll back a transaction.
        // Only applicable when auto-creation of savings account is enabled — if it
        // is OFF no savings account (and therefore no charge) will be created.
        $registrationCharges = [];
        $registrationTotal = 0;
        if ($autoCreateSavingsAccount) {
            $productIdForCharges = $isExisting && $savingsProductId
                ? $savingsProductId
                : optional(SavingsProduct::where('name', 'General Savings Account')->first())->id;

            $registrationCharges = $productIdForCharges
                ? $this->chargeCalculator->resolveForRegistration($productIdForCharges)
                : [];

            $registrationTotal = array_sum(array_column($registrationCharges, 'amount'));
        }

        if ($registrationTotal > 0
            && (float) $request->input('initial_deposit', 0) < $registrationTotal) {
            return response()->json([
                'success' => 422,
                'message' => "Initial deposit must be at least UGX {$registrationTotal} to cover registration charges.",
                'errors'  => ['initial_deposit' => ["Initial deposit must be at least UGX {$registrationTotal} to cover registration charges."]],
            ], 422);
        }

        try {
            $member = DB::connection('tenant')->transaction(function () use (
                $validated,
                $request,
                $isExisting,
                $savingsProductId,
                $openingBalance,
                $sharesQuantity,
                $sharesCompulsory,
                $appliesToExisting,
                $sharePrice,
                $autoCreateSavingsAccount,
                $registrationCharges
            ) {
                // Create member
                $member = Member::create($validated);

                // Only create savings account when auto-creation setting is ON
                if ($autoCreateSavingsAccount) {
                    if ($isExisting && $savingsProductId) {
                        // Existing member: use selected product and opening balance
                        $this->savingsAccountService->create([
                            'member_id' => $member->id,
                            'savings_product_id' => $savingsProductId,
                            'account_type' => 'voluntary',
                            'is_new_account' => false,
                            'initial_deposit' => $openingBalance,
                            'consider_min_balance' => true,
                            'status' => 'active',
                        ]);
                    } else {
                        // New member: use default savings product
                        $defaultProduct = SavingsProduct::where('name', 'General Savings Account')->first();

                        if ($defaultProduct) {
                            $this->savingsAccountService->create([
                                'member_id' => $member->id,
                                'savings_product_id' => $defaultProduct->id,
                                'account_type' => 'voluntary',
                                'is_new_account' => true,
                                'initial_deposit' => $request->input('initial_deposit', 0),
                                'consider_min_balance' => true,
                                'status' => 'active',
                            ]);
                        }
                    }
                }

                // Post the product-scoped registration charges resolved earlier as
                // withdrawal transactions against the savings account. Universal
                // on_registration charges are handled below by applyRegistrationCharges.
                $savingsAccountForCharges = SavingsAccount::on('tenant')->where('member_id', $member->id)->latest()->first();
                if ($savingsAccountForCharges) {
                    foreach ($registrationCharges as $rc) {
                        $this->memberChargeService->postRegistrationCharge(
                            $savingsAccountForCharges,
                            (int) $rc['general_charge_id'],
                            (float) $rc['amount'],
                            $this->savingsJournal,
                        );
                    }
                }

                // Apply active on_registration general charges to the member's savings account
                $this->applyRegistrationCharges($member);

                // Create initial share purchase if shares are compulsory and quantity provided
                $shouldCreateShares = $sharesCompulsory
                    && $sharesQuantity
                    && (! $isExisting || $appliesToExisting);

                if ($shouldCreateShares) {
                    $share = Share::create([
                        'member_id' => $member->id,
                        'share_no' => (int) $sharesQuantity,
                        'share_value' => $sharePrice,
                        'total_value' => (int) $sharesQuantity * $sharePrice,
                        'purchased_at' => now()->toDateString(),
                    ]);
                    $this->shareAccountingService->postSharePurchaseEntry($share, Auth::id());
                }

                return $member;
            });

            return response()->json([
                'success' => 200,
                'message' => $autoCreateSavingsAccount
                    ? 'Member registered successfully and savings account generated.'
                    : 'Member registered successfully.',
                'data' => new MemberResource($member),
            ]);
        } catch (\Exception $e) {
            Log::error('Member Creation Failed: '.$e->getMessage());

            return response()->json([
                'success' => 500,
                'message' => 'Failed to register member: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show the specified member.
     */
    public function show(Member $member)
    {
        $member->load([
            'savingsAccounts.savingsProduct',
            // 'transactions' => function ($query) use($member) {
            //    return  DB::table('transactions')->where('member_id', $member->id)->orderBy('created_at', 'desc');
            // },
            'transactions' => function ($query) {
                $query->with('account')->orderBy('created_at', 'desc');
            },
            'referredBy:id,name',
            'registeredBy:id,name',
        ]);

        $savingsProducts = SavingsProduct::where('status', 'active')
            ->with('charges')
            ->get(['id', 'name', 'type', 'minimum_balance']);

        return response()->json([
            'success' => 200,
            'message' => 'Member details retrieved successfully.',
            'data' => [
                'member' => new MemberResource($member),
                'savingsProducts' => SavingsProductResource::collection($savingsProducts),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified member.
     */
    public function edit(Member $member)
    {
        $savingsAccounts = SavingsAccount::select('id', 'account_no', 'account_type')
            ->where('status', 'active')
            ->get();

        return response()->json([
            'success' => 200,
            'message' => 'Edit data retrieved successfully.',
            'data' => [
                'member' => new MemberResource($member),
                'savingsAccounts' => SavingsAccountResource::collection($savingsAccounts),
            ],
        ]);
    }

    /**
     * Update the specified member.
     */
    public function update(MemberFormRequest $request, Member $member)
    {
        $validated = $request->validated();

        if ($request->hasFile('avatar')) {
            if ($member->profile_picture) {
                Storage::disk('public')->delete($member->profile_picture);
            }
            $validated['profile_picture'] = $request->file('avatar')->store('avatars', 'public');
        }

        try {
            $member->update($validated);

            return response()->json([
                'success' => 200,
                'message' => 'Member updated successfully.',
                'data' => new MemberResource($member),
            ]);
        } catch (\Exception) {
            return response()->json([
                'success' => 500,
                'message' => 'Failed to update member. Please try again.',
            ], 500);
        }
    }

    /**
     * Remove the specified member (soft delete).
     */
    public function destroy(Member $member)
    {
        try {
            $member->delete();

            return response()->json([
                'success' => 200,
                'message' => 'Member deleted successfully.',
                'data' => null,
            ]);
        } catch (\Exception) {
            return response()->json([
                'success' => 500,
                'message' => 'Failed to delete member. Please try again.',
            ], 500);
        }
    }

    /**
     * Update the member's avatar.
     */
    public function updateAvatar(Request $request, Member $member)
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048'], // 2MB Max
        ]);

        try {
            if ($member->profile_picture) {
                Storage::disk('public')->delete($member->profile_picture);
            }

            $path = $request->file('avatar')->store('avatars', 'public');
            $member->update(['profile_picture' => $path]);

            return response()->json([
                'success' => 200,
                'message' => 'Profile picture updated successfully.',
                'data' => [
                    'avatar_url' => $member->avatar_url,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => 500,
                'message' => 'Failed to update profile picture: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Approve a pending member — sets status to active.
     */
    public function approve(Member $member)
    {
        if ($member->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending members can be approved.',
            ], 422);
        }

        $member->update([
            'status' => 'active',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        $member->refresh();
        $memberData = (new MemberResource($member))->resolve();

        return response()->json([
            'success' => 200,
            'message' => 'Member approved successfully.',
            'data' => [
                ...$memberData,
                'member' => $memberData,
                'member_details' => [
                    ...$memberData,
                    'full_name' => $memberData['name'] ?? null,
                    'memeber_code' => $memberData['member_number'] ?? null,
                ],
            ],
        ]);
    }

    /**
     * Reject a pending member — sets status to rejected.
     */
    public function reject(Request $request, Member $member)
    {
        if ($member->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending members can be rejected.',
            ], 422);
        }

        $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $member->update([
            'status' => 'rejected',
            'rejected_by' => Auth::id(),
            'rejected_at' => now(),
            'rejection_reason' => $request->input('rejection_reason'),
        ]);

        return response()->json([
            'message' => 'Member registration rejected.',
            'data' => new MemberResource($member),
        ]);
    }

    /**
     * Download a blank Excel import template for bulk member upload.
     */
    public function downloadTemplate()
    {
        return Excel::download(
            new MembersTemplateExport,
            'members-import-template.xlsx'
        );
    }

    /**
     * Import members from an uploaded Excel/CSV file.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $import = new MembersImport;

        try {
            Excel::import($import, $request->file('file'));
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Import failed: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => "{$import->imported} member(s) imported successfully.".
                ($import->errors ? ' Some rows had errors.' : ''),
            'imported' => $import->imported,
            'errors' => $import->errors,
        ]);
    }

    /**
     * Import members from a JSON array (used after client-side preview/edit).
     */
    public function importJson(Request $request)
    {
        $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['array'],
        ]);

        $onboarding = OnboardingSettings::current();
        $defaultStatus = $onboarding->require_member_approval ? 'pending' : 'active';
        $autoCreateSavingsAccount = $onboarding->auto_create_savings_account;
        $registeredBy = Auth::id();

        // Always load the default product — needed when savings_balance or account_number is provided
        $defaultProduct = SavingsProduct::where('name', 'General Savings Account')->first();

        $imported = 0;
        $errors = [];
        $seenPhones = [];
        $seenEmails = [];

        $parseDate = function (?string $raw): ?string {
            if (! $raw) {
                return null;
            }
            try {
                return Carbon::createFromFormat('Y-m-d', $raw)->toDateString();
            } catch (\Exception) {
                return null;
            }
        };

        // Compute starting member number once before the loop
        $lastMember = Member::withTrashed()->orderBy('id', 'desc')->first();
        $nextNum = $lastMember ? ((int) substr($lastMember->member_number, 4)) + 1 : 1;

        foreach ($request->input('rows') as $index => $row) {
            $rowNum = $index + 1;
            $name = trim($row['name'] ?? '');
            $phone = trim($row['phone'] ?? '');
            $gender = strtolower(trim($row['gender'] ?? ''));
            $marital = strtolower(trim($row['marital_status'] ?? ''));
            $nationality = trim($row['nationality'] ?? '') ?: 'Uganda';
            $address = trim($row['address'] ?? '');
            $email = trim($row['email'] ?? '') ?: null;

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
                $errors[] = "Row {$rowNum}: Missing ".implode(', ', $missing).'.';

                continue;
            }

            if (! in_array($gender, ['male', 'female', 'other'])) {
                $errors[] = "Row {$rowNum}: Invalid gender '{$gender}'.";

                continue;
            }

            if (! in_array($marital, ['single', 'married', 'divorced', 'widowed'])) {
                $errors[] = "Row {$rowNum}: Invalid marital status '{$marital}'.";

                continue;
            }

            // Skip duplicates — check both the DB and the current batch
            if (in_array($phone, $seenPhones) || Member::where('phone', $phone)->exists()) {
                $errors[] = "Row {$rowNum}: Skipped — phone '{$phone}' is already registered.";

                continue;
            }
            if ($email && (in_array($email, $seenEmails) || Member::where('email', $email)->exists())) {
                $errors[] = "Row {$rowNum}: Skipped — email '{$email}' is already registered.";

                continue;
            }
            $seenPhones[] = $phone;
            if ($email) {
                $seenEmails[] = $email;
            }

            $memberNumber = 'MBR-'.str_pad($nextNum, 5, '0', STR_PAD_LEFT);

            try {
                $member = Member::create([
                    'member_number' => $memberNumber,
                    'code' => $memberNumber,
                    'name' => $name,
                    'phone' => $phone,
                    'gender' => $gender,
                    'marital_status' => $marital,
                    'nationality' => $nationality,
                    'address' => $address,
                    'email' => $email,
                    'dob' => $parseDate(trim($row['dob'] ?? '')),
                    'other_contact' => trim($row['other_contact'] ?? '') ?: null,
                    'initial_deposit' => is_numeric($row['initial_deposit'] ?? null) ? (float) $row['initial_deposit'] : null,
                    'opening_balance' => is_numeric($row['savings_balance'] ?? null) ? (float) $row['savings_balance'] : null,
                    'shares_quantity' => is_numeric($row['shares_quantity'] ?? null) ? (int) $row['shares_quantity'] : null,
                    'account_number' => trim($row['account_number'] ?? '') ?: null,
                    'employee_number' => trim($row['employee_number'] ?? '') ?: null,
                    'joined_at' => $parseDate(trim($row['date_joined'] ?? '')),
                    'status' => $defaultStatus,
                    'password' => Hash::make('password123'),
                    'registered_by' => $registeredBy,
                ]);
                // Auto-create savings account when:
                //  a) the onboarding setting is on AND initial_deposit > 0, OR
                //  b) savings_balance or account_number is provided (migration import with existing balance)
                $savingsBalance = $row['savings_balance'] ?? null;
                $accountNumber = trim($row['account_number'] ?? '') ?: null;
                $initialDeposit = $row['initial_deposit'] ?? null;
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

                        $this->savingsAccountService->create($accountData);
                    } catch (\Exception $e) {
                        $errors[] = "Row {$rowNum}: Member created but savings account failed — {$e->getMessage()}";
                    }
                }

                $imported++;
                $nextNum++;
            } catch (\Exception $e) {
                $errors[] = "Row {$rowNum}: Failed — {$e->getMessage()}";
            }
        }

        return response()->json([
            'message' => "{$imported} member(s) imported.".($errors ? ' Some rows had errors.' : ''),
            'imported' => $imported,
            'errors' => $errors,
        ]);
    }

    /**
     * Re-queue a rejected member back to pending for re-review.
     */
    public function requeue(Member $member)
    {
        if ($member->status !== 'rejected') {
            return response()->json([
                'message' => 'Only rejected members can be re-queued for review.',
            ], 422);
        }

        $member->update([
            'status' => 'pending',
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'Member re-queued for approval.',
            'data' => new MemberResource($member),
        ]);
    }

    /**
     * Apply all active on_registration charges to a member as receivables (Option B).
     *
     * Phase 1 — Always create MemberCharge records regardless of savings account existence.
     * Phase 2 — Attempt immediate collection if a funded savings account is available.
     *            Any charges that cannot be collected remain as pending receivables and
     *            will be auto-collected on the member's next deposit.
     */
    private function applyRegistrationCharges(Member $member): void
    {
        $charges = GeneralCharge::where('application', 'on_registration')
            ->where('is_active', true)
            ->whereDoesntHave('productCharges', fn ($q) => $q->where('type', 'registration'))
            ->get();

        if ($charges->isEmpty()) {
            return;
        }

        // Phase 1: Create a MemberCharge receivable for every active registration charge.
        foreach ($charges as $charge) {
            $chargeAmount = (float) $charge->amount;
            if ($chargeAmount <= 0) {
                continue;
            }

            try {
                MemberCharge::create([
                    'member_id' => $member->id,
                    'general_charge_id' => $charge->id,
                    'charge_name' => $charge->name,
                    'amount' => $chargeAmount,
                    'status' => 'pending',
                    'applied_at' => now(),
                    'narration' => 'Registration charge applied on member onboarding.',
                    'created_by' => Auth::id(),
                ]);
            } catch (\Throwable $e) {
                Log::error("Failed to create MemberCharge for charge '{$charge->name}' on member {$member->id}: {$e->getMessage()}");
            }
        }

        // Phase 2: Attempt immediate collection from savings account if one exists.
        $account = SavingsAccount::where('member_id', $member->id)->latest()->first();

        if (! $account) {
            // No savings account yet — charges remain pending, collected on first deposit.
            return;
        }

        $this->memberChargeService->collectPendingCharges($member, $account, $this->savingsJournal);
    }
}
