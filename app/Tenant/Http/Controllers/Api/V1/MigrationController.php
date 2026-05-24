<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Exports\OpeningBalancesTemplateExport;
use App\Exports\TransactionHistoryTemplateExport;
use App\Http\Controllers\Controller;
use App\Imports\OpeningBalancesImport;
use App\Imports\TransactionHistoryImport;
use App\Services\Migration\OpeningBalanceMigrationService;
use App\Services\Migration\TransactionHistoryMigrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class MigrationController extends Controller
{
    public function __construct(
        private readonly OpeningBalanceMigrationService $openingBalanceService,
        private readonly TransactionHistoryMigrationService $transactionService
    ) {}

    public function downloadOpeningBalancesTemplate()
    {
        return Excel::download(
            new OpeningBalancesTemplateExport,
            'opening-balances-template.xlsx'
        );
    }

    public function downloadTransactionHistoryTemplate()
    {
        return Excel::download(
            new TransactionHistoryTemplateExport,
            'transaction-history-template.xlsx'
        );
    }

    public function importOpeningBalances(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $import = new OpeningBalancesImport($this->openingBalanceService);

        try {
            Excel::import($import, $request->file('file'));
        } catch (\Exception $e) {
            return response()->json(['message' => 'Import failed: '.$e->getMessage()], 500);
        }

        return $this->importResponse($import->imported, $import->errors);
    }

    public function importTransactionHistory(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $import = new TransactionHistoryImport($this->transactionService);

        try {
            Excel::import($import, $request->file('file'));
        } catch (\Exception $e) {
            return response()->json(['message' => 'Import failed: '.$e->getMessage()], 500);
        }

        return $this->importResponse($import->imported, $import->errors);
    }

    public function importOpeningBalancesJson(Request $request)
    {
        $request->validate(['rows' => ['required', 'array', 'min:1']]);

        $result = $this->openingBalanceService->process(
            collect($request->input('rows'))->map(fn ($r) => array_values($r))
        );

        return $this->importResponse($result['imported'], $result['errors']);
    }

    public function importTransactionHistoryJson(Request $request)
    {
        $request->validate(['rows' => ['required', 'array', 'min:1']]);

        $result = $this->transactionService->process(
            collect($request->input('rows'))->map(fn ($r) => array_values($r))
        );

        return $this->importResponse($result['imported'], $result['errors']);
    }

    private function importResponse(int $imported, array $errors): JsonResponse
    {
        return response()->json([
            'message' => "{$imported} record(s) imported.".($errors ? ' Some rows had errors.' : ''),
            'imported' => $imported,
            'errors' => $errors,
        ]);
    }
}
