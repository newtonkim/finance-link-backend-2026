<?php

namespace App\Tenant\Http\Resources;

use App\Tenant\Support\TenantMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function __construct($resource)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'expense_category_id' => $this->expense_category_id,
            'category_name' => $this->category?->name,
            'chart_of_account_id' => $this->chart_of_account_id,
            'payment_account_name' => $this->paymentAccount?->name,
            'amount' => $this->amount,
            'amount_formatted' => TenantMoney::format($this->amount),
            'vendor_name' => $this->vendor_name,
            'transaction_date' => $this->transaction_date?->format('Y-m-d'),
            'reference_no' => $this->reference_no,
            'description' => $this->description,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'is_recurring' => $this->is_recurring,
            'recurring_frequency' => $this->recurring_frequency,
            'next_due_date' => $this->next_due_date?->format('Y-m-d'),
            'created_by_name' => $this->creator?->name,
            'approved_by_name' => $this->approver?->name,
            'paid_by_name' => $this->payer?->name,
            'is_over_budget' => (bool) $this->is_over_budget,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'attachments' => ExpenseAttachmentResource::collection($this->whenLoaded('attachments')),
            'approval_history' => $this->approvalHistory?->map(fn($h) => [
                'action' => $h->action,
                'comments' => $h->comments,
                'user_name' => $h->approver?->name ?? 'System',
                'created_at' => $h->created_at?->format('Y-m-d H:i:s'),
                'level' => $h->level,
            ]),
        ];
    }
}
