<?php


namespace App\Console\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

function alterTable($blueprint, $columns, $table, $logConnection)
{
    foreach ($columns as $column) {

        $columnName = $column->Field;

        if (Schema::connection($logConnection)->hasColumn($table, $columnName)) {
            continue;
        }

        $type = strtolower($column->Type);

        if (str_contains($type, 'bigint')) {
            $blueprint->bigInteger($columnName)->nullable();

        } elseif (str_contains($type, 'int')) {
            $blueprint->integer($columnName)->nullable();

        } elseif (str_contains($type, 'decimal')) {
            preg_match('/\((\d+),(\d+)\)/', $type, $matches);
            $precision = $matches[1] ?? 10;
            $scale = $matches[2] ?? 2;

            $blueprint->decimal($columnName, $precision, $scale)->nullable();

        } elseif (str_contains($type, 'double') || str_contains($type, 'float')) {
            $blueprint->double($columnName)->nullable();

        } elseif (str_contains($type, 'varchar')) {
            preg_match('/\d+/', $type, $matches);
            $length = $matches[0] ?? 255;

            $blueprint->string($columnName, $length)->nullable();

        } elseif (str_contains($type, 'text')) {
            $blueprint->text($columnName)->nullable();

        } elseif (str_contains($type, 'timestamp')) {
            $blueprint->timestamp($columnName)->nullable();

        } elseif (str_contains($type, 'datetime')) {
            $blueprint->dateTime($columnName)->nullable();

        } elseif (str_contains($type, 'date')) {
            $blueprint->date($columnName)->nullable();

        } elseif (str_contains($type, 'time')) {
            $blueprint->time($columnName)->nullable();

        } elseif (str_contains($type, 'json')) {
            $blueprint->json($columnName)->nullable();

        } elseif (str_contains($type, 'tinyint(1)')) {
            $blueprint->boolean($columnName)->nullable();

        } else {
            $blueprint->string($columnName)->nullable();
        }
    }

    return $blueprint;
}


function defaultColumns($blueprint, $existing)
{
    $columns = [
        "action_taken_by",
        "date_of_log_action",
        "sacco_log_tenant_id",
        "log_action_status_taken",
        "log_failure_reason",
        "database_name",
        "before_changes",
        "after_changes",
    ];

    foreach ($columns as $key => $value) {
        if (!in_array($value, $existing)) {
            $blueprint->string($value)->nullable()->index();
        }
    }
    return $blueprint;
}
class PatchTenantColumns
{
    public function duplicateTenantSchemaToLogs($tenant)
    {
        $tenantConnection = 'tenant';
        $logConnection = 'sacco_logs';
        if (!Schema::connection($logConnection)->hasTable('login_outs')) {
            Schema::connection($logConnection)->create("login_outs", function (Blueprint $blueprint) {
                $blueprint->string('sacco_log_tenant_id')->nullable()->index();
                $blueprint->string('sacco_log_staff_id')->nullable()->index();
                $blueprint->string('sacco_log_token')->nullable()->index();
                $blueprint->string('sacco_log_status')->nullable()->index()->comment("success or failed");
                $blueprint->string('sacco_log_database_name')->nullable()->index();
                // $blueprint->timestamp('created_at')->useCurrent()->index();
                $blueprint->timestamp('date_of_log_action')->useCurrent()->index();
            });
        }
        $tables = DB::connection($tenantConnection)->select('SHOW TABLES');
        foreach ($tables as $tableObj) {
            $table = array_values((array) $tableObj)[0];
            if (in_array($table, ['migrations', 'failed_jobs', 'password_resets'])) {
                continue;
            }
            $columns = DB::connection($tenantConnection)->select("SHOW COLUMNS FROM `$table`");
            $columnNames = collect($columns)->pluck('Field')->toArray();
            if (!Schema::connection($logConnection)->hasTable($table)) {
                Schema::connection($logConnection)->create($table, function (Blueprint $blueprint) use ($columns, $table, $logConnection) {
                    // up them up . but i dont want, and there is nothing u going to do about it !!!     
                    $blueprint->bigInteger('action_taken_by')->nullable()->comment("who made this change this is staff id")->index();
                    $blueprint->timestamp('date_of_log_action')->useCurrent()->index()->comment("date of log action has taken place");
                    $blueprint->string('sacco_log_tenant_id')->nullable()->index()->comment("save the tenant id just to help in truck");
                    $blueprint->string('log_action_status_taken')->nullable()->index()->comment("updated or create or deleted or any");
                    $blueprint->text('log_failure_reason')->nullable()->comment("failure reason");
                    $blueprint->string('database_name')->nullable()->index();
                    $blueprint->json('before_changes')->nullable();
                    $blueprint->json('after_changes')->nullable();
                    $blueprint = alterTable($blueprint, $columns, $table, $logConnection);
                });
            } else {


                Schema::connection($logConnection)->table($table, function (Blueprint $blueprint) use ($logConnection, $table, $columns) {

                    $existing = Schema::connection($logConnection)->getColumnListing($table);
                    $blueprint = defaultColumns($blueprint, $existing);
                    $blueprint = alterTable($blueprint, $columns, $table, $logConnection);
                });
            }
        }
    }
}
