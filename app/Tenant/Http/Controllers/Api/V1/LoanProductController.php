<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreLoanProductRequest;
use App\Http\Requests\Tenant\UpdateLoanProductRequest;
use App\Tenant\Http\Resources\LoanProductListResource;
use App\Tenant\Http\Resources\LoanProductResource;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Loans\Contracts\LoanProductServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApprovalSetting;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Models\LoanProductRequiredDocument;
use App\Tenant\Modules\Loans\Services\LoanProductGuardService;
use App\Tenant\Modules\Loans\Services\LoanProductPreviewService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LoanProductController extends Controller
{
    public function __construct(
        protected LoanProductServiceInterface $service,
        protected LoanProductGuardService $guard,
        protected LoanProductPreviewService $previewService,
    ) {}

    public function index(Request $request)
    {
        $products = $this->service->list(
            $request->only('search', 'is_active')
        );

        return LoanProductListResource::collection($products);
    }

    public function dropdown_list(Request $request)
    {
        $products = $this->service->list(
            $request->only('search', 'is_active')
        );

        return response()->json([
            'status' => 'OK',
            'code' => 200,
            'payload' => [
                'data' => LoanProductListResource::collection($products),
            ],
        ]);
    }

    public function store(StoreLoanProductRequest $request)
    {
        $validated = $request->validated();
        $requiredDocuments = $validated['required_documents'] ?? [];
        unset($validated['required_documents']);

        $approvalSetting = $validated['approval_setting'] ?? null;
        unset($validated['approval_setting']);

        $product = $this->runTenantTransaction(function () use ($validated, $requiredDocuments, $approvalSetting) {
            $product = $this->service->create(array_merge(
                $validated,
                ['created_by' => auth('tenant')->id()]
            ));

            $this->syncRequiredDocuments($product, $requiredDocuments);
            $this->syncApprovalSetting($product, $approvalSetting);

            return $product;
        });

        return response()->json([
            'message' => 'Loan Product Created Successfully.',
            'data' => new LoanProductResource($this->annotateGuardFlags($this->withRelations($product))),
        ], 201);
    }

    public function show(LoanProduct $loanProduct)
    {
        return new LoanProductResource(
            $this->annotateGuardFlags($this->withRelations($loanProduct))
        );
    }

    public function update(UpdateLoanProductRequest $request, LoanProduct $loanProduct)
    {
        $validated = $request->validated();
        $requiredDocuments = $validated['required_documents'] ?? null;
        unset($validated['required_documents']);

        $approvalSetting = $validated['approval_setting'] ?? null;
        unset($validated['approval_setting']);

        $this->runTenantTransaction(function () use ($loanProduct, $validated, $requiredDocuments, $approvalSetting) {
            $this->service->update($loanProduct, array_merge(
                $validated,
                ['updated_by' => auth('tenant')->id()]
            ));

            if ($requiredDocuments !== null) {
                $this->syncRequiredDocuments($loanProduct, $requiredDocuments);
            }

            $this->syncApprovalSetting($loanProduct, $approvalSetting);
        });

        return response()->json([
            'message' => 'Loan Product Updated Successfully.',
            'data' => new LoanProductResource(
                $this->annotateGuardFlags($this->withRelations($loanProduct->fresh()))
            ),
        ]);
    }

    public function destroy(LoanProduct $loanProduct)
    {
        $this->service->delete($loanProduct);

        return response()->json([
            'message' => 'Loan Product Deleted Successfully.',
        ]);
    }

    public function preview(Request $request)
    {
        $validated = $request->validate([
            'preview_amount' => ['nullable', 'numeric', 'min:0'],
            'preview_term' => ['nullable', 'integer', 'min:1'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'interest_rate' => ['nullable', 'numeric', 'min:0'],
            'interest_method' => ['nullable', 'string'],
            'repayment_structure' => ['nullable', 'string'],
            'interest_period' => ['nullable', 'string'],
            'loan_duration' => ['nullable', 'integer', 'min:1'],
            'repayment_cycle' => ['nullable', 'string'],
        ]);

        return response()->json([
            'data' => $this->previewService->preview($validated),
        ]);
    }

    private function runTenantTransaction(Closure $callback): mixed
    {
        $connection = DB::connection('tenant');

        // Tenant tests share PDO handles and may start an outer transaction on a sibling connection.
        // In that case, opening a new PDO transaction here throws "already an active transaction".
        if ($connection->transactionLevel() === 0 && $connection->getPdo()->inTransaction()) {
            return $callback();
        }

        return $connection->transaction($callback);
    }

    private function withRelations(LoanProduct $product): LoanProduct
    {
        $relations = ['penaltyRules', 'requiredDocuments.documentType', 'approvalSetting'];
        if (Schema::connection('tenant')->hasTable('loan_product_charge') && Schema::connection('tenant')->hasTable('loan_charges')) {
            $relations[] = 'charges';
        }

        $product->load($relations)->loadCount('loans');

        $accountIds = array_values(array_filter(array_unique([
            $product->loan_portfolio_account_id,
            $product->interest_income_account_id,
            $product->interest_receivable_account_id,
            $product->penalty_income_account_id,
            $product->penalty_receivable_account_id,
            $product->disbursement_account_id,
            $product->charges_income_account_id,
            $product->charges_receivable_account_id,
        ])));

        $accounts = $accountIds
            ? ChartOfAccount::whereIn('id', $accountIds)->select(['id', 'name', 'gl_code'])->get()->keyBy('id')
            : collect();

        $product->setRelation('portfolioAccount', $accounts->get($product->loan_portfolio_account_id));
        $product->setRelation('interestIncomeAccount', $accounts->get($product->interest_income_account_id));
        $product->setRelation('interestReceivableAccount', $accounts->get($product->interest_receivable_account_id));
        $product->setRelation('penaltyIncomeAccount', $accounts->get($product->penalty_income_account_id));
        $product->setRelation('penaltyReceivableAccount', $accounts->get($product->penalty_receivable_account_id));
        $product->setRelation('disbursementAccount', $accounts->get($product->disbursement_account_id));
        $product->setRelation('chargesIncomeAccount', $accounts->get($product->charges_income_account_id));
        $product->setRelation('chargesReceivableAccount', $accounts->get($product->charges_receivable_account_id));

        return $product;
    }

    private function annotateGuardFlags(LoanProduct $product): LoanProduct
    {
        $isInUse = $this->guard->isInUse($product);

        $product->setAttribute('is_in_use', $isInUse);
        $product->setAttribute('can_edit_core_fields', $this->guard->canEditCoreFields($product));

        return $product;
    }

    private function syncApprovalSetting(LoanProduct $product, ?array $setting): void
    {
        if (empty($setting['enabled'])) {
            LoanApprovalSetting::where('loan_product_id', $product->id)->delete();

            return;
        }

        LoanApprovalSetting::updateOrCreate(
            ['loan_product_id' => $product->id],
            [
                'quorum_size' => (int) ($setting['quorum_size'] ?? 3),
                'approval_threshold' => (int) ($setting['approval_threshold'] ?? 2),
            ]
        );
    }

    private function syncRequiredDocuments(LoanProduct $product, array $rows): void
    {
        $product->requiredDocuments()->delete();

        foreach ($rows as $index => $row) {
            LoanProductRequiredDocument::create([
                'loan_product_id' => $product->id,
                'document_type_id' => (int) $row['document_type_id'],
                'is_required' => array_key_exists('is_required', $row) ? (bool) $row['is_required'] : true,
                'required_stage' => $row['required_stage'] ?? 'submission',
                'sort_order' => $row['sort_order'] ?? $index,
                'is_active' => array_key_exists('is_active', $row) ? (bool) $row['is_active'] : true,
                'notes' => $row['notes'] ?? null,
                'created_by' => auth('tenant')->id(),
                'updated_by' => auth('tenant')->id(),
            ]);
        }
    }
}
