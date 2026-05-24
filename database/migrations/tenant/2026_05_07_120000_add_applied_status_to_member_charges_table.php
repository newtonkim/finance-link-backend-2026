<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection(): string
    {
        return 'tenant';
    }

    public function up(): void
    {
        DB::connection('tenant')->statement(
            "ALTER TABLE member_charges MODIFY COLUMN status ENUM('pending','paid','waived','applied') NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        DB::connection('tenant')->statement(
            "UPDATE member_charges SET status = 'pending' WHERE status = 'applied'"
        );
        DB::connection('tenant')->statement(
            "ALTER TABLE member_charges MODIFY COLUMN status ENUM('pending','paid','waived') NOT NULL DEFAULT 'pending'"
        );
    }
};
