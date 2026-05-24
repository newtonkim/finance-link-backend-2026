<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            if (! Schema::hasColumn('shares', 'transferring_member_id')) {
                $table->unsignedBigInteger('transferring_member_id')
                    ->nullable()
                    ->after('member_id')
                    ->comment('Shares transferred from which member')
                    ->index();

                $table->foreign('transferring_member_id', 'shares_transferring_member_id_fk')
                    ->references('id')
                    ->on('members')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shares', function (Blueprint $table) {
            if (Schema::hasColumn('shares', 'transferring_member_id')) {
                $table->dropForeign('shares_transferring_member_id_fk');
                $table->dropColumn('transferring_member_id');
            }
        });
    }
};
