<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            if (!Schema::hasColumn('members', 'id_number')) {
                $table->string('id_number', 50)->nullable()->after('name')->index();
            }
            if (!Schema::hasColumn('members', 'national_id_number')) {
                $table->string('national_id_number', 50)->nullable()->after('name')->index();
            }
            if (!Schema::hasColumn('members', 'profile_path')) {
                $table->text('profile_path')->nullable()->after('national_id_number');
            }
            if (!Schema::hasColumn('members', 'phone')) {
                $table->string('phone', 20)->nullable()->after('id_number')->index();
            }
            if (!Schema::hasColumn('members', 'dob')) {
                $table->date('dob')->nullable()->after('phone')->index();
            }
            if (!Schema::hasColumn('members', 'address')) {
                $table->text('address')->nullable()->after('dob');
            }
            if (!Schema::hasColumn('members', 'status')) {
                $table->enum('status', ['active', 'pending', 'suspended'])->default('active')->after('address')->index();
            }
            if (!Schema::hasColumn('members', 'joined_at')) {
                $table->date('joined_at')->nullable()->after('status')->index();
            }
            if (!Schema::hasColumn('members', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('joined_at')->index();
                $table->foreign('created_by')->references('id')->on('staff')->nullOnDelete();
            }
            if (!Schema::hasColumn('members', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable()->after('remember_token');
            }
            if (!Schema::hasColumn('members', 'two_factor_recovery_codes')) {
                $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            }
            if (!Schema::hasColumn('members', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes')->index();
            }
            if (!Schema::hasColumn('members', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn(['id_number', 'phone', 'dob', 'address', 'status', 'joined_at', 'created_by', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
            $table->dropSoftDeletes();
        });
    }
};
