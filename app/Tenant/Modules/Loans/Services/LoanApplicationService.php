<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Contracts\LoanApplicationServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanDocumentServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class LoanApplicationService implements LoanApplicationServiceInterface
{
    public function __construct(
        protected LoanApplicationStatusGuard $statusGuard,
        protected LoanDocumentServiceInterface $documents,
    ) {}

    /**
     * Return a paginated, filtered list of loan applications.
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $withRecommended = ! empty($filters['status']) && $filters['status'] === 'recommended';

        $query = LoanApplication::query()
            ->with(array_filter(['member', 'loanProduct', $withRecommended ? 'approvals' : null]))
            ->orderBy('created_at', 'desc');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (! empty($filters['loan_product_id'])) {
            $query->where('loan_product_id', $filters['loan_product_id']);
        }

        if (! empty($filters['member_search'])) {
            $search = $filters['member_search'];
            $query->whereHas('member', function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Create a new loan application in draft status and generate the application_no.
     */
    public function create(array $data): LoanApplication
    {
        $req = request()->all();
        $data['status'] = LoanApplication::STATUS_DRAFT;
        $data['created_by'] = $data['created_by'] ?? auth('tenant')->id();
        $data['application_no'] = 'DRAFT-'.uniqid(); // temporary; replaced after insert

        $application = LoanApplication::create($data);

        // ///////////////////////////////////
        $codeSequence = new CodeSequence;
        $coderef = $codeSequence->codeSequence($req['code'] ?? null, type: 'loan-application', moduleTarget: 'loan-application', tableTaget: 'loan_applications');

        // Replace the placeholder with the real application number once we have the ID
        $application->application_no = $coderef;
        // $application->application_no = 'APP-' . date('Ymd') . '-' . str_pad($application->id, 5, '0', STR_PAD_LEFT);
        $application->save();

        return $application;
    }

    /**
     * Update an existing draft application.
     *
     * @throws ValidationException
     */
    public function update(LoanApplication $application, array $data): bool
    {
        $editableStatuses = [
            LoanApplication::STATUS_DRAFT,
            LoanApplication::STATUS_RETURNED_FOR_CORRECTION,
        ];

        if (! in_array($application->status, $editableStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or returned-for-correction applications can be updated.'],
            ]);
        }

        return $application->update($data);
    }

    /**
     * Validate required fields and transition the application to submitted status.
     *
     * @throws ValidationException
     */
    public function submit(LoanApplication $application): bool
    {
        $errors = [];

        $application->loadMissing(['loanProduct', 'documents']);

        if (empty($application->member_id)) {
            $errors['member_id'] = ['A member must be assigned before submitting.'];
        }

        if (empty($application->loan_product_id)) {
            $errors['loan_product_id'] = ['A loan product must be selected before submitting.'];
        }

        if (empty($application->requested_amount) || $application->requested_amount <= 0) {
            $errors['requested_amount'] = ['A valid requested amount is required before submitting.'];
        }

        if (empty($application->requested_term) || $application->requested_term < 1) {
            $errors['requested_term'] = ['A valid requested term is required before submitting.'];
        }

        if (empty($application->purpose)) {
            $errors['purpose'] = ['Purpose is required before submitting.'];
        }

        // Temporarily allow submission even when required submission-stage documents are missing.
        // Keep the document checks for later workflow steps when enforcement is re-enabled.

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $this->statusGuard->transition($application, LoanApplication::STATUS_SUBMITTED);

        $application->submitted_at = now();
        $application->save();

        return true;
    }

    /**
     * Cancel a loan application with a mandatory reason.
     *
     * @throws ValidationException
     */
    public function cancel(LoanApplication $application, string $reason): bool
    {
        $this->statusGuard->transition(
            $application,
            LoanApplication::STATUS_CANCELLED,
            $reason
        );

        $application->update([
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
            'cancelled_by' => auth('tenant')->id(),
        ]);

        return true;
    }

    /**
     * Reopen a cancelled application back to draft for corrections.
     *
     * @throws ValidationException
     */
    public function reopen(LoanApplication $application): bool
    {
        $this->statusGuard->transition(
            $application,
            LoanApplication::STATUS_DRAFT,
            'Application reopened for corrections.'
        );

        $application->update([
            'cancellation_reason' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
        ]);

        return true;
    }
}
