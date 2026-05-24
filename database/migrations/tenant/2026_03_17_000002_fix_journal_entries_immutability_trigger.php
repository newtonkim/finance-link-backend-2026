<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Allow posted → reversed transitions so reversal journals can mark the
        // original as reversed. All other updates to posted entries remain blocked.
        DB::connection('tenant')->unprepared('DROP TRIGGER IF EXISTS trg_prevent_journal_header_update');
        DB::connection('tenant')->unprepared('
            CREATE TRIGGER trg_prevent_journal_header_update
            BEFORE UPDATE ON journal_entries
            FOR EACH ROW
            BEGIN
                IF OLD.status = \'posted\' AND NEW.status != \'reversed\' THEN
                    SIGNAL SQLSTATE \'45000\'
                    SET MESSAGE_TEXT = \'Posted journals are immutable. Use reversal journals instead.\';
                END IF;
            END
        ');
    }

    public function down(): void
    {
        DB::connection('tenant')->unprepared('DROP TRIGGER IF EXISTS trg_prevent_journal_header_update');
        DB::connection('tenant')->unprepared('
            CREATE TRIGGER trg_prevent_journal_header_update
            BEFORE UPDATE ON journal_entries
            FOR EACH ROW
            BEGIN
                IF OLD.status = \'posted\' THEN
                    SIGNAL SQLSTATE \'45000\'
                    SET MESSAGE_TEXT = \'Posted journals are immutable. Use reversal journals instead.\';
                END IF;
            END
        ');
    }
};
