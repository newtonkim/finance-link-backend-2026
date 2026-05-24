<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * staff_branch_access pivot table.
 *
 * Allows a staff member to be explicitly granted access to one or more branches
 * beyond their primary assigned branch (staff.branch_id).
 *
 * When allowedBranchIds() is called for a SCOPE_BRANCH / SCOPE_SELF staff member,
 * it unions staff.branch_id with any rows in this table for that staff.
 * SCOPE_ALL (tenant admin) bypasses this table entirely.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        // if (Schema::connection($this->connection)->hasTable('staff_branch_access')) {
        //     return;
        // }

        // Schema::connection($this->connection)->create('staff_branch_access', function (Blueprint $table) {
        //     $table->id();
        //     $table->foreignId('staff_id')
        //         ->constrained('staff')
        //         ->cascadeOnDelete();
        //     $table->foreignId('branch_id')
        //         ->constrained('branches')
        //         ->cascadeOnDelete();
        //     $table->timestamps();

        //     $table->unique(['staff_id', 'branch_id']);
        // });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('staff_branch_access');
    }
};
