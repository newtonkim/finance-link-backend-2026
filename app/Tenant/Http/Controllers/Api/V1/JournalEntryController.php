<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Exports\JournalEntryExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreJournalEntryRequest;
use App\Tenant\Modules\Accounting\Services\JournalEntryService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class JournalEntryController extends Controller
{
    public function __construct(
        protected JournalEntryService $service
    ) {}

    public function index(Request $request)
    {
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10); // User requested 10 per page
        $fromDate = $request->input('date_from');
        $toDate = $request->input('date_to');

        $entries = $this->service->getPaginatedEntries($perPage, $search, $fromDate, $toDate);

        return response()->json($entries);
    }

    public function store(StoreJournalEntryRequest $request)
    {
        try {
            $entry = $this->service->createJournalEntry($request->validated());

            return response()->json([
                'message' => 'Journal entry created successfully.',
                'data' => $entry,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(int $id)
    {
        $entry = $this->service->getEntry($id);

        return response()->json([
            'data' => $entry,
        ]);
    }

    public function update(StoreJournalEntryRequest $request, int $id)
    {
        try {
            $entry = $this->service->updateJournalEntry($id, $request->validated());

            return response()->json([
                'message' => 'Journal entry updated successfully.',
                'data' => $entry,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(int $id)
    {
        try {
            $this->service->deleteJournalEntry($id);

            return response()->json([
                'message' => 'Journal entry deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function post(int $id)
    {
        try {
            $entry = $this->service->postDraftById($id);

            return response()->json([
                'message' => 'Journal entry posted successfully.',
                'data' => $entry,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function export(Request $request)
    {
        $fromDate = $request->input('date_from');
        $toDate = $request->input('date_to');
        $search = $request->input('search');
        $format = $request->input('format', 'csv');

        $fileName = 'journal_entries_'.date('Y-m-d_His').'.'.$format;
        $export = new JournalEntryExport($fromDate, $toDate, $search);

        return Excel::download($export, $fileName);
    }
}
