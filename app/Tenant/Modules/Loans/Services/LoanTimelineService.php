<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Concerns\FormatsLoanActivity;
use App\Tenant\Modules\Loans\Contracts\LoanTimelineServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationApproval;
use App\Tenant\Modules\Loans\Models\LoanApplicationDocument;
use App\Tenant\Modules\Loans\Models\LoanApplicationStatusHistory;
use Illuminate\Support\Collection;

class LoanTimelineService implements LoanTimelineServiceInterface
{
    use FormatsLoanActivity;

    /**
     * Return a unified, chronologically-sorted audit trail for the application.
     */
    public function getTimeline(LoanApplication $application): Collection
    {
        $events = collect();

        $events->push($this->createdEvent($application));
        $this->statusEvents($application)->each(fn ($e) => $events->push($e));
        $this->documentEvents($application)->each(fn ($e) => $events->push($e));
        $this->approvalEvents($application)->each(fn ($e) => $events->push($e));

        return $events->sortBy('timestamp')->values();
    }

    // ─── Private builders ─────────────────────────────────────────────────────

    private function createdEvent(LoanApplication $application): array
    {
        $actor = null;
        if ($application->relationLoaded('createdBy') && $application->createdBy) {
            $actor = $this->staffSummary($application->createdBy);
        }

        return [
            'type' => 'created',
            'title' => 'Application Created',
            'description' => "Loan application {$application->application_no} was created as a draft.",
            'actor' => $actor,
            'notes' => null,
            'timestamp' => $application->created_at,
        ];
    }

    private function statusEvents(LoanApplication $application): Collection
    {
        $histories = LoanApplicationStatusHistory::with('changedBy')
            ->where('loan_application_id', $application->id)
            ->orderBy('changed_at')
            ->get();

        return $histories->map(function (LoanApplicationStatusHistory $h) {
            $from = $this->labelStatus($h->from_status);
            $to = $this->labelStatus($h->to_status);
            $actor = $h->changedBy ? $this->staffSummary($h->changedBy) : null;

            return [
                'type' => 'status_change',
                'title' => "Status: {$from} → {$to}",
                'description' => "Application status changed from '{$h->from_status}' to '{$h->to_status}'.",
                'actor' => $actor,
                'notes' => $h->notes,
                'timestamp' => $h->changed_at,
            ];
        });
    }

    private function documentEvents(LoanApplication $application): Collection
    {
        $documents = LoanApplicationDocument::where('loan_application_id', $application->id)
            ->orderBy('created_at')
            ->get();

        return $documents->map(function (LoanApplicationDocument $doc) {
            return [
                'type' => 'document_uploaded',
                'title' => 'Document Uploaded',
                'description' => "'{$doc->original_name}' ({$doc->document_type}) was uploaded.",
                'actor' => null,
                'notes' => $doc->notes,
                'timestamp' => $doc->created_at,
            ];
        });
    }

    private function approvalEvents(LoanApplication $application): Collection
    {
        $approvals = LoanApplicationApproval::with('approver')
            ->where('loan_application_id', $application->id)
            ->orderBy('decided_at')
            ->get();

        return $approvals->map(function (LoanApplicationApproval $a) {
            $actor = $a->approver ? $this->staffSummary($a->approver) : null;
            $verb = $a->decision === 'approved' ? 'approved' : 'declined';
            $title = $a->decision === 'approved' ? 'Approval Vote Cast' : 'Application Declined';

            return [
                'type' => 'approval_vote',
                'title' => $title,
                'description' => ($actor['name'] ?? 'An approver')." {$verb} this application.",
                'actor' => $actor,
                'notes' => $a->comments,
                'timestamp' => $a->decided_at ?? $a->created_at,
            ];
        });
    }

    // ─── Helpers provided by FormatsLoanActivity trait ────────────────────────
}
