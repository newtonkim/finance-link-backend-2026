<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Trigger to prevent posting to control accounts
        DB::unprepared("
            CREATE TRIGGER trg_prevent_control_account_posting
            BEFORE INSERT ON journal_entry_lines
            FOR EACH ROW
            BEGIN
                DECLARE v_is_control BOOLEAN;
                SELECT is_control INTO v_is_control 
                FROM chart_of_accounts 
                WHERE id = NEW.account_id;

                IF v_is_control = 1 THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Cannot post to a control account.';
                END IF;
            END
        ");

        // 2. Trigger to prevent mutation of posted journals
        DB::unprepared("
            CREATE TRIGGER trg_prevent_journal_line_update
            BEFORE UPDATE ON journal_entry_lines
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Journal lines are immutable. Use reversal journals instead.';
            END
        ");

        DB::unprepared("
            CREATE TRIGGER trg_prevent_journal_line_delete
            BEFORE DELETE ON journal_entry_lines
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Journal lines are immutable. Use reversal journals instead.';
            END
        ");

        DB::unprepared("
            CREATE TRIGGER trg_prevent_journal_header_update
            BEFORE UPDATE ON journal_entries
            FOR EACH ROW
            BEGIN
                IF OLD.status = 'posted' THEN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Posted journals are immutable. Use reversal journals instead.';
                END IF;
            END
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_prevent_control_account_posting');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_prevent_journal_line_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_prevent_journal_line_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_prevent_journal_header_update');
    }
};
