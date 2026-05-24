<?php

use App\Tenant\Modules\Loans\Data\DocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection('tenant');

        foreach (DocumentType::TYPES as $code => $name) {
            $connection->table('document_types')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'description' => null,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $documentTypeIds = $connection->table('document_types')
            ->pluck('id', 'code');

        $products = $connection->table('loan_products')
            ->select(['id', 'required_document_types'])
            ->get();

        foreach ($products as $product) {
            $legacyTypes = $product->required_document_types;

            if (is_string($legacyTypes)) {
                $legacyTypes = json_decode($legacyTypes, true);
            }

            if (! is_array($legacyTypes) || empty($legacyTypes)) {
                continue;
            }

            foreach (array_values($legacyTypes) as $index => $slug) {
                $documentTypeId = $documentTypeIds[$slug] ?? null;

                if (! $documentTypeId) {
                    continue;
                }

                $connection->table('loan_product_required_documents')->updateOrInsert(
                    [
                        'loan_product_id' => $product->id,
                        'document_type_id' => $documentTypeId,
                        'required_stage' => 'submission',
                    ],
                    [
                        'is_required' => true,
                        'sort_order' => $index,
                        'is_active' => true,
                        'notes' => null,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        // Backfill only. Normalized rows remain the source of truth.
    }
};
