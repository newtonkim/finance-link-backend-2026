<?php

namespace App\Http\Requests\Tenant;

use App\Models\Member;
use App\Tenant\Modules\Settings\Models\OnboardingSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MemberFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $memberId = $this->route('member')?->id;
        $isExisting = $this->input('member_type') === 'existing_member';
        $isStore = $this->isMethod('post');

        $onboarding = $isStore ? OnboardingSettings::current() : null;
        $sharesCompulsory = $onboarding?->shares_compulsory ?? false;
        $appliesToExisting = $onboarding?->shares_compulsory_applies_to_existing ?? false;
        $minShares = $onboarding?->min_shares_on_onboarding ?? 1;

        // Shares are required when: compulsory AND (new member OR applies to existing too)
        $sharesRequired = $sharesCompulsory && (! $isExisting || $appliesToExisting);

        return [
            'member_type' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'salutation' => ['nullable', 'string', 'max:20'],
            'gender' => ['required', 'string', 'in:male,female,other'],
            'dob' => ['nullable', 'date'],
            'phone' => ['required', 'string', 'max:20'],
            'phone_country' => ['nullable', 'string', 'size:2'],
            'other_contact' => ['nullable', 'string', 'max:20'],
            'other_contact_country' => ['nullable', 'string', 'size:2'],
            'mobile_money_number' => ['nullable', 'string', 'max:20'],
            'mobile_money_country' => ['nullable', 'string', 'size:2'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                $memberId
                    ? Rule::unique(Member::class, 'email')->ignore($memberId)
                    : Rule::unique(Member::class, 'email'),
            ],
            'national_id_number' => ['nullable', 'string', 'max:50'],
            'marital_status' => ['required', 'string', 'in:single,married,divorced,widowed'],
            'nationality' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:500'],
            'next_of_kin' => ['nullable', 'string', 'max:255'],
            'next_of_kin_contact' => ['nullable', 'string', 'max:20'],
            'next_of_kin_contact_country' => ['nullable', 'string', 'size:2'],
            'initial_deposit' => [$isExisting ? 'nullable' : 'nullable', 'numeric', 'min:0'],
            'joined_at' => ['nullable', 'date'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            // Existing member fields
            'is_shareholder' => [$isExisting ? 'required' : 'nullable', 'string', 'in:yes,no'],
            'savings_product_id' => [$isExisting ? 'required' : 'nullable', 'integer'],
            'opening_balance' => [$isExisting ? 'required' : 'nullable', 'numeric', 'min:0'],
            // Staff tracking
            'referred_by' => ['nullable', 'integer', 'exists:staff,id'],
            // Shares onboarding
            'shares_quantity' => [
                $sharesRequired ? 'required' : 'nullable',
                'integer',
                'min:'.$minShares,
            ],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        $minShares = OnboardingSettings::current()->min_shares_on_onboarding ?? 1;

        return [
            'name.min' => 'The full name must be at least 2 characters.',
            'email.unique' => 'This email address is already in use by another member.',
            'gender.in' => 'Please select a valid gender.',
            'marital_status.in' => 'Please select a valid marital status.',
            'shares_quantity.required' => 'Share purchase is required for new members. Minimum '.$minShares.' share(s).',
            'shares_quantity.min' => 'Minimum of '.$minShares.' share(s) required to register a member.',
        ];
    }
}
