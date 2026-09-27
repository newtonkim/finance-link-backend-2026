<?php

namespace App\Tenant\Http\Resources;

use App\Tenant\Support\TenantMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class LoanApplicationGuarantorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'loan_application_id' => $this->loan_application_id,
            'guarantor_type' => $this->guarantor_type,
            'guarantor_id' => $this->guarantor_id,
            'guarantor_account_id' => $this->guarantor_account_id,
            'name' => $this->guarantorName(),
            'guarantor_code' => $this->guarantor_type === 'group' ? $this->group?->code : $this->member?->code,
            'guarantee_amount' => (float) $this->guarantee_amount,
            'guarantee_amount_formatted' => TenantMoney::format($this->guarantee_amount),
            'recovered_amount' => (float) $this->recovered_amount,
            'status' => $this->status,
            'requested_at' => $this->requested_at,
            'consent_expires_at' => $this->consent_expires_at,
            'responded_at' => $this->responded_at,
            'response_channel' => $this->response_channel,
            'decline_reason' => $this->decline_reason,
            'consent_document_url' => $this->consent_document_path
                ? Storage::disk('public')->url($this->consent_document_path)
                : null,
            'loan_id' => $this->loan_id,
            'locked_at' => $this->locked_at,
            'released_at' => $this->released_at,
            'release_reason' => $this->release_reason,
            'arrears_notified_at' => $this->arrears_notified_at,
            'arrears_notice_count' => (int) $this->arrears_notice_count,
            'substitutes_id' => $this->substitutes_id,
            'substituted_by_id' => $this->substituted_by_id,
            'release_requested_at' => $this->release_requested_at,
            'release_request_reason' => $this->release_request_reason,
            'note' => $this->note,
            'created_at' => $this->created_at,
        ];
    }
}
