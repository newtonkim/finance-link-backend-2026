<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends \Illuminate\Database\Migrations\Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('expense_attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('file_size')->nullable()->after('file_type');
            $table->unsignedBigInteger('uploaded_by')->nullable()->after('file_size');
            
            $table->foreign('uploaded_by')->references('id')->on('staff');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expense_attachments', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by']);
            $table->dropColumn(['file_size', 'uploaded_by']);
        });
    }
};
