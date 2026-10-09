<?php

namespace Database\Seeders;

use App\Tenant\Modules\Loans\Data\DocumentType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentTypeSeeder extends Seeder
{
    /**
     * Insert any canonical loan document types the tenant is missing.
     *
     * Existing rows are left untouched so a sacco's renames and deactivations
     * survive re-seeding.
     */
    public function run(): void
    {
        $connection = DB::connection('tenant');

        $existing = $connection->table('document_types')->pluck('code')->all();

        $missing = array_diff_key(DocumentType::TYPES, array_flip($existing));

        if ($missing === []) {
            return;
        }

        $now = now();

        $connection->table('document_types')->insert(array_map(
            fn ($code, $name) => [
                'code' => $code,
                'name' => $name,
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            array_keys($missing),
            $missing,
        ));
    }
}
