<?php

namespace App\Tenant\Services\MemebersSettingSevices;

class FindsettingsAction extends CodeSequence
{
    protected $settingsCollection = [];

    protected $action = [
        'approve' => 'active',
        'active' => 'active',
        'pending' => 'pending',
        'suspended' => 'suspended',
    ];

    protected $settingsKeysNeeded = ['members-onboarding', 'share'];

    public function __construct($outKeys = null)
    {

        $this->settingsCollection = $this->collectSettings($outKeys ?? $this->settingsKeysNeeded);
    }

    protected function getSettingAction($key, $default = null)
    {

        if (! isset($this->settingsCollection[strtolower($key)])) {
            return false;
        }

        return $this->settingsCollection[strtolower($key)]['action'];
    }

    public function saccoLoanMemberOnLoanApplicationCanBeGuaranteedByOtherGroup()
    {
        return $this->isEnabled('sacco-loan-member-on-loan-application-can-be-guaranteed-by-other-group');
        // $proval = $this->getSettingAction('sacco-loan-member-on-loan-application-can-be-guaranteed-by-other-group');

        // return $proval == '1' || $proval == 'true' ? true : false;
    }

    public function saccoMemberRequireApprovalBeforeMemberBecomesActive()
    {
        $proval = $this->getSettingAction('sacco-members-Require-approval-before-members-becomes-active');
        return $proval == '1' ? 'active' : 'Pending';
    }

    public function saccoTransferSavingsRequireApprovalToBecomeAcompleteTransfer()
    {
        $proval = $this->getSettingAction('sacco-transfer-savings-Require-approval-to-become-acomplete-transfer');

        return $proval;
    }

    public function saccoMemberOnMemberCreationCreateShareAccountAtTheSameTime()
    {
        $proval = $this->getSettingAction('sacco-share-on-member-creation-create-share-account-at-the-same-time');

        return $proval;
    }

    public function saccoSharePrice()
    {
        $results = $this->getSettingAction('sacco-share-price-value') ?? 1;

        return $results;
    }

    public function saccoMemberOnMemberCreationCreateShareMinimumValue()
    {
        $results = $this->getSettingAction('sacco-share-on-member-creation-create-share-minimum-value') ?? 1;
        return $results;
    }

    public function canMemberExisitsInMultipleGroups()
    {
        return $this->isEnabled('sacco-savings-group-can-member-be-in-multiple-groups');

        // return in_array($results, ['1', 'true']) ? true : false;
    }

    public function inAGroupCantWithrawBeyondGuaranteedAmount()
    {
        return $this->isEnabled('sacco-savings-group-dont-withdrawal-beyond-guarantee-total-amount');

        // return in_array($results, ['1', 'true']) ? true : false;
        // $results = $this->getSettingAction('sacco-savings-group-dont-withdrawal-beyond-guarantee-total-amount');

        // return in_array($results, ['1', 'true']) ? true : false;
    }

    public function saccoAccountOnAccountCreationShowInitialDeposit()
    {
        // sacco-members-show-initial-deposit-field
        return $this->isEnabled('sacco-members-show-initial-deposit-field');

        // $results = $this->getSettingAction('sacco-members-show-initial-deposit-field');

        // return in_array($results, ['1', 'true']) ? true : false;
    }

    // //// notification  ------------------------------------------
    public function saccoOnSellOfShares()
    {
        $results = $this->getSettingAction('sacco-On-sell-of-shares');

        return in_array($results, ['1', 'true']) ? true : false;
    }

    public function saccoOnMembershipChange()
    {
        $results = $this->getSettingAction('sacco-On-membership-change');

        return in_array($results, ['1', 'true']) ? true : false;
    }

    public function saccoOnLoanClosing()
    {
        return $this->isEnabled('sacco-On-loan-closing');
    }

    public function saccoOnLoanPayment()
    {
        return $this->isEnabled('sacco-On-loan-payment');
    }

    public function saccoOnLoanDueDateWarning()
    {
        return $this->isEnabled('sacco-On-loan-due-date-warning');
    }

    public function saccoOnCommitteeActionOnLoan()
    {
        return $this->isEnabled('sacco-On-committee-action-on-the-loan');
    }

    public function saccoOnOpeningAnotherAccount()
    {
        return $this->isEnabled('sacco-On-opening-another-account');
    }

    public function saccoOnLoanWritingOff()
    {
        return $this->isEnabled('sacco-On-loan-writing-off');
    }

    public function saccoOnLoanWaivingOff()
    {
        return $this->isEnabled('sacco-On-loan-waiving-off');
    }

    public function saccoOnLoanRejecting()
    {
        return $this->isEnabled('sacco-On-loan-rejecting');
    }

    public function saccoOnLoanDisbursement()
    {
        return $this->isEnabled('sacco-On-loan-disbursement');
    }

    public function saccoOnLoanApproval()
    {
        return $this->isEnabled('sacco-On-loan-approval');
    }

    public function saccoOnLoanAppraisal()
    {
        return $this->isEnabled('sacco-On-loan-appraisal');
    }

    public function saccoOnLoanApplication()
    {
        return $this->isEnabled('sacco-On-loan-application');
    }

    public function saccoOnDepositTransaction()
    {
        return $this->isEnabled('sacco-On-deposit-transaction');
    }

    public function saccoOnTransferTransaction()
    {
        return $this->isEnabled('sacco-On-transfer-transaction');
    }

    public function saccoOnWithdrawTransaction()
    {
        return $this->isEnabled('sacco-On-withdraw-transaction');
    }

    public function saccoOnNewMemberRegistration()
    {
        return $this->isEnabled('sacco-On-new-member-registration');
    }

    public function saccoNotifyTheGuarantor()
    {
        return $this->isEnabled('sacco-notify-the-guarantor');
    }

    private function isEnabled($key)
    {
        $result = $this->getSettingAction($key);

        return in_array($result, ['1', 'true', 1, true], true) ? true : false;
    }

    public function saccoMemberSaveAndSavingAccountAtOnce()
    {
        return $this->isEnabled('sacco-members-on-member-creation-save-a-sacco-account-at-the-same-time');
        
    }
    public function saccoSavingsAccountsConsiderMinimumBalance()
    {
        return $this->isEnabled('sacco-savings-accounts-consider-minimum-balance');
        
    }
}
