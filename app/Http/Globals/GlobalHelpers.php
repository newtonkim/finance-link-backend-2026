<?php

namespace App\Http\Globals;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

// / dont play with this class  it globaly used
class GlobalHelpers extends BaseController
{
    public function perpage()
    {
        return request('per_page', 200);
    }

    public function transaction(callable $cal)
    {
        try {
            return DB::transaction(function () use ($cal) {
                return $cal();
            });
        } catch (\Throwable $th) {
            DB::rollBack();
            $error = [
                'status' => 'FAILED',
                'error' => $th,
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'code' => $th->getCode(),
            ];

            return $error;
        }
    }

    public function routeList(array $routes, string $module, $particular)
    {
        // central
        // / i create this  befor add found out  that this is slower compare to routeListV2

        foreach ($routes as $key => $action) {
            $uri = is_numeric($key) ? $action : $key;
            $method = $module . '_' . $uri;
            if (in_array($action, ['list', 'details'])) {
                $method = 'get_' . $module . '_' . $uri;
            }
            $permission = str_replace('_', '-', $particular . $module . '-' . $uri);
            // Log::info($uri);
            Route::post($uri, str_replace('-', '_', $method))
                ->name($permission)
                ->middleware("permission:{$permission}");
        }
    }

    /**
     * uniform  conlloer methodes
     *  takes in an array [
     *  "route" => "save-changed-settings",
                     "permission" => "save-changed-settings",
                    "method" => "save-changed-settings"
     * ]
     *
     * **/
    public function routeListV2(array $routes)
    {
        $pertern = '/[^A-z0-9]/ig';
        foreach ($routes as $key => $action) {
            $route = str_replace($pertern, '-', strtolower($action['route']));
            $method = str_replace($pertern, '_', strtolower($action['method'] ?? $action['route']));
            $permission = str_replace($pertern, '-', $action['permission'] ?? null);
            Route::post($route, $method)
                ->name($permission)
                ->middleware($permission ? "permission:{$permission}" : null);
        }
    }

    /**
     * Delete a record from a database table.
     *
     * This method supports both **soft delete** and **permanent delete**.
     *
     * If `permanent_delete` is true in the request, the record will be permanently
     * removed from the database. Otherwise, the record will be soft deleted by
     * updating the `deleted_at` column.
     *
     * If a condition array is provided, it will be used in the `where` clause.
     * Otherwise, the method defaults to using the `id` from the request.
     *
     * Example condition:
     * [
     *     'id' => 1,
     *     'status' => 'active'
     * ]
     * default condition: ['id' => $req->id]
     *
     * @param  string  $table  The database table name.
     * @param  object  $req  Request object containing `id` and `permanent_delete`.
     * @param  array|string  $condition  Optional custom where conditions.
     * @return int|bool Number of affected rows or boolean result.
     */
    public function DeleteRecord(string $table, $req, $condition = [])
    {
        return $this->TryCatch(function () use ($table, $req, $condition) {
            $prepareCondition = is_array($condition) && ! empty($condition) ? $condition : ['id' => $req->id];
            if (! empty($req->permanent_delete)) {
                return DB::table($table)
                    ->where($prepareCondition)
                    ->delete();
            }

            // Soft delete
            return DB::table($table)
                ->where($prepareCondition)
                ->update([
                    'deleted_at' => now(),
                ]);
        });
    }

    public function TryCatch(callable $cal)
    {
        try {
            return $cal();
        } catch (\Throwable $th) {
            $error = [
                'status' => 'FAILED',
                'error' => $th,
                'message' => $th->getMessage(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'code' => $th->getCode(),
            ];

            return $error;
        }
    }

    public function isJSONToArray($string)
    {
        $d = is_string($string) && is_array(json_decode($string, true)) ? true : false;
        if ($d) {
            return json_decode($string);
        }

        return $string;
    }

    public function send_email_and_it_self_as_attachment($data, $email, $subject, $blade1 = '', $blade2 = '', $portrait = 'portrait', $show = false)
    {
        $pdfContent = '';
        if (isset($blade1)) {
            $pdf = Pdf::loadView($blade1, compact('data'));
            $pdf->setPaper('a4', $portrait);
            $pdfContent = $pdf->output();
        }

        $bladeViewContent = View::make($blade2, ['data' => $data])->render();
        if ($show) {
            return $bladeViewContent;
        }
        $patientName = '';
        if (gettype($data) == 'array' && isset($data['prescription_data'])) {
            $patientName = $data['prescription_data']['Patient_details']->first_name . ' ' . $data['prescription_data']['Patient_details']->middle_name . ' ' . $data['prescription_data']['Patient_details']->last_name;
        } else {
            if (gettype($data) == 'array' && isset($data['Patient_details'])) {

                $getArrayBaack = $this->isJSONToArray($data['Patient_details']);

                $patientName = $getArrayBaack['first_name'] ?? '' . ' ' . $getArrayBaack['middle_name'] ?? '' . ' ' . $getArrayBaack['last_name'] ?? '';
            }
        }
        $subjects = 'HELLO! ' . $patientName . $subject;

        return Mail::send([], [], function ($message) use ($pdfContent, $subjects, $bladeViewContent, $email, $blade2) {
            $html = new HtmlString($bladeViewContent);

            $msg = $message->to($email)->subject($subjects)->html($html->__toString());
            if (isset($blade2)) {
                $msg->attachData($pdfContent, "$subjects." . time() . '.pdf');
            }
        });
    }

    /**
     *prepare the search patten  and return the results
     *query returns when search
     * searchBy [
     * "key front end"=>"database column name",
     * "key front end2"=>"database column name2",
     * ]
     * */
    /**
     * Parse any common date format and return a MySQL-compatible Y-m-d string.
     * Returns null if the value is empty or unparseable.
     */
    public function normalizeDate(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    public function removeAllNullValues(array $arrayData): array
    {
        $arrayData['created_at'] = ! empty($arrayData['created_at']) ? ($arrayData['created_at']) : null;
        $arrayData['updated_at'] = ! empty($arrayData['updated_at']) ? ($arrayData['updated_at']) : null;
        $arrayData['deleted_at'] = ! empty($arrayData['deleted_at']) ? ($arrayData['deleted_at']) : null;

        return array_filter(
            $arrayData,
            function ($value) {
                return $value !== null;
            }
        );
    }

    public function dynamic_search_db_query($query, $search, $searchableFields, $filterable = [])
    {
        // return $searchableFields;
        $req = request()->all();
        if (! empty($req['search_by'])) {
         $dataKey = explode(',', $req['search_by']);
            $searchableFields = array_values(array_unique(
                array_filter($searchableFields, function ($item) use ($dataKey) {
                    foreach ($dataKey as $value) {
                        if (str_contains($item, $value)) {
                            return true;
                        }
                    }

                    return false;
                })
            ));
        }

        if (! empty($req['search_filter']) && $req['search_filter']) {
            $search_filter = $this->isJSONToArray($req['search_filter']);
            foreach ($search_filter as $column => $filter) {
                if (($filter['type'] ?? null) === 'date-range' && ! empty($filter['value'])) {
                    $start = strtotime($filter['value'][0] ?? now());
                    $end = strtotime($filter['value'][1] ?? now());
                    if ($start && $end) {
                        $query->whereBetween($filterable[$column] ?? $column, [
                            Carbon::parse($start)->format('Y-m-d H:i:s'),
                            Carbon::parse($end)->format('Y-m-d H:i:s'),
                        ]);
                    }
                }
                // // for dropdown
                if (($filter['type'] ?? null) === 'select' && ! empty($filter['value'])) {
                   $query->whereRaw("$column LIKE ?", ["%{$filter['value']}%"]);
                }
            }
        }


        if (is_array($searchableFields) && count($searchableFields)) {
            return $query->where(function ($q) use ($searchableFields, $search) {
                $patterns = [
                    '/\bSELECT\b/i',
                    '/\bINSERT\b/i',
                    '/\bUPDATE\b/i',
                    '/\bDELETE\b/i',
                    '/\bDROP\b/i',
                    '/\bTRUNCATE\b/i',
                    '/\bALTER\b/i',
                    '/\bCREATE\b/i',
                    '/\bEXEC\b/i',
                    '/\bUNION\b/i',
                    '/--/',
                    '/\*/',
                ];

                $patterns = preg_replace($patterns, '', $search);
                $chekedifEmpty = strlen($search) ? $patterns : '[A-z]';
                $keywordsA = explode(' ', $chekedifEmpty);
                $dd = array_map(function ($value) {
                    return "($value)";
                }, $keywordsA);

                foreach ($searchableFields as $key => $value1) {
                    $value = explode(' ', $value1)[0];
                    $callers[] = "IFNULL($value,'')";
                }

                $keySearch = implode('|', $dd);
                $callersCombine = 'CONCAT(' . implode(',', $callers) . ')';

                $q->whereRaw('IFNULL(' . $callers[0] . ",'') REGEXP ?", ["$keySearch"]);

                return $q->orWhereRaw("$callersCombine REGEXP ?", [$keySearch]);
            });
        } else {
            return ['Please i need a searchableFields which is argument 3  as an array [of columns in the database ]'];
        }
        // });
    }
    /***
     * $table is the table name where the log will be created
     * 
     * @Params
     * $currentData ['table column'=>value of the comlums]
     * $before  json/array of the before changes
     * $After json/array of the after changes
     * ****/
    public function createTenantLog($table, $currentData, $status = 'create', $before = null, $After = null)
    {
        $subdomain = request()->header('X-Tenant-Subdomain');
        $tenantId = DB::connection('master')->table('tenants')->where('subdomain', $subdomain)->first(['id'])->id;
        $conllection = [
            'log_action_status_taken' => $status,
            'sacco_log_tenant_id' => $tenantId,
            'database_name' => $subdomain,
            'before_changes' => $before ? ($before) : null,
            'after_changes' => $After ? ($After) : null,
            'action_taken_by' => auth()->check() ? auth()->id() : null,
        ];

        foreach ($currentData as $key => $value) {
            $conllection[$key] = $value;
        }
        DB::connection('sacco_logs')->table($table)->insert($conllection);
    }


    /**f provided it will update the record else it will create a new record
     *  table
     * updateCondition ['id'=>1]
     * **/
    public function UpdateOrCreateRecord(string $table, array $dataToUpdate, array $updateCondition = [], bool $modalSet = true, $nullOverride = false)
    {
        $condition = ! empty($updateCondition) ? $updateCondition : (request()->has('id') ? ['id' => request('id')] : []);
        // $record = DB::table($table)->where($condition)->first();
        array_walk($dataToUpdate, function (&$value) {
            if (is_array($value)) {
                $value = json_encode($value);
            }
        });
        // code
        if (empty($condition)) {
            // /  i expect all gthe  table to have default columns like updated_at and created_at
            // created_at  i expect this to generated by the system data base
            $dataToUpdate['created_by'] = auth()->check() ? auth()->id() : null;
            $dataToUpdate['created_at'] = $dataToUpdate['created_at'] ?? now();
            $dataToUpdate['updated_at'] = $dataToUpdate['updated_at'] ?? now();

            // if (Schema::connection(DB::getDefaultConnection())->hasColumn($table, 'branch_id') && empty($dataToUpdate['branch_id'])) { i dont want this here it will break the code in the future  make sure its out side of the
            //     $dataToUpdate['branch_id'] = request()->branch_id;
            // }

            $res = $this->insert_column_when_value_is_set(DB::table($table), $dataToUpdate, $modalSet);
            $statusCheck = $res ? 'created successfully' : 'created failed';
            $res->log_failure_reason = isset($res->error) ? $res->error : null;
            // $this->createTenantLog($table, $res, $statusCheck, [], json_encode($dataToUpdate));
            return $res;
        } else {
            $dataToUpdate['updated_by'] = auth()->check() ? auth()->id() : null;
            $dataToUpdate['updated_at'] = $dataToUpdate['updated_at'] ?? now();

            $selectedColumns = DB::table($table)->where($condition)->first();
            $res = $this->update_column_when_value_is_set(DB::table($table)->where($condition), $dataToUpdate, $modalSet, $nullOverride);
            if (is_array($res)) {
                $res['log_failure_reason'] = isset($res['error']) ? $res['error'] : ($res->error ?? null);
            }
            if (is_object($res)) {
                $res->log_failure_reason = isset($res->error) ? $res->error : null;
            }
            $statusCheck = $res ? 'update successfully' : 'update failed';
            // $this->createTenantLog($table, $res, $statusCheck, json_encode($selectedColumns),json_encode( $dataToUpdate));

            return $res;
        }
    }

    public function insert_column_when_value_is_set($modals, $array_of_database_columns = [], $modalSet = false)
    {
        /*** useage
         * $DataToUdated=['column'=>'value']
         *  $classGlobal= new PomGlobalService();
         * $myValidateColumns=$classGlobal->insert_column_when_value_is_set($patient, $DataToUdated);
         * $patient->update($myValidateColumns);
         *
         */
        $table_column = [];
        if (! empty($array_of_database_columns)) {

            foreach ($array_of_database_columns as $key => $value) {

                if (! empty($value) || $value == '0' || $value == 'false' || $value == ' ') {
                    $table_column[$key] = $value;
                }
            }
            // return $array_of_database_columns;
            if ($modalSet) {
                $id = $modals->insertGetId($table_column);
                $table_column = $modals->where('id', $id)->first();
            }

            return $table_column;
        }
    }

    public function update_column_when_value_is_set($modals, $array_of_database_columns = [], $modalSet = false, $nullOverride = false)
    {
        /*** useage
         * $DataToUdated=['column'=>'value']
         *  $classGlobal= new PomGlobalService();
         * $myValidateColumns=$classGlobal->update_column_when_value_is_set($patient, $DataToUdated);
         * $patient->update($myValidateColumns);
         *
         */
        $table_column = [];
        if (! empty($array_of_database_columns)) {

            foreach ($array_of_database_columns as $key => $value) {
                if ($nullOverride) {
                    $table_column[$key] = $value;
                    // continue;
                } else {
                    if (! empty($value) || $value == '0' || $value == 'false' || $value == ' ') {
                        $table_column[$key] = $value;
                    }
                }
            }
            switch (strtolower($modalSet)) {
                case 'create':
                    return $modals::create($table_column);
                    break;
                case true || 'true' || '1' || 'update':
                    if ($modals->update($table_column)) {
                        $table_column = $modals->first();
                    }
                    break;
                default:
                    break;
            }

            return $table_column;
        }
    }

    /**
     * this function is good where making seeder for a database
     * Generate missing columns in a database table and insert data into it.
     *
     * This function will check if a database table exists and if it does,
     * it will generate any missing columns in the table based on the
     * `$requiredColumns` array. After generating the columns, it will
     * insert the data specified in the `$data` array into the table.
     *
     * If the table does not exist, it will skip the operation and print a message.
     *
     * @param  string  $tableName  The name of the database table.
     * @param  array  $data  The data to be inserted into the table.
     * @param  array  $requiredColumns  An array of columns that must exist in the table.
     *                                  The array should be in the format `['column' => 'type']`.
     *                                  The type should be either 'string', 'json', or 'timestamp'.
     */
    public function GeneratDBColumnsAndInsetData($tableName = '', $data = [], $requiredColumns = [])
    {
        if (Schema::connection('master')->hasTable($tableName)) {
            Schema::connection('master')->table($tableName, function (Blueprint $table) use ($requiredColumns, $tableName) {
                foreach ($requiredColumns as $column => $type) {
                    if (! Schema::connection('master')->hasColumn($tableName, $column)) {
                        switch ($type) {
                            case 'string':
                                $table->string($column)->nullable();
                                break;
                            case 'json':
                                $table->json($column)->nullable();
                                break;
                            case 'timestamp':
                                $table->timestamp($column)->nullable();
                                break;
                        }
                        echo "Created missing column '{$column}' in table {$tableName}\n";
                    }
                }
            });

            DB::table($tableName)->insertOrIgnore($data);
        } else {
            echo "Table {$tableName} does not exist, skipping...\n";
        }
    }

    public function collectSettings($type = [])
    {
        $settings = DB::table('system_settings')
            ->whereIn('settings_module', [...$type, 'all-system'])
            ->get(['settings_name', 'settings_action']);
        $structure = [];
        foreach ($settings as $setting) {
            $structure[$setting->settings_name] = json_decode($setting->settings_action, true);
        }

        return $structure;
    }

    public function dropDownList($table = '', $name = ['name' => 'rl.name AS name'])
    {

        $req = request();

        return $this->TryCatch(function () use ($req, $table, $name) {
            $query = DB::table($table . ' as rl')
                ->select(['rl.id AS id', ...$name]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], ['rl.id AS id', 'name']);
            }
            $query = $query;

            if ($table != 'branches' && ! empty($req->branch_id) && Schema::connection('tenant')->hasColumn($table, 'branch_id')) {
                $query = $query->where('rl.branch_id', $req->branch_id);
            }

            return $query->whereNull('rl.deleted_at')
                ->orderBy('rl.id', 'DESC')->paginate($this->perpage());
        });
    }

    public function memberDefaultPassword($data = null)
    {
        return Hash::make($data ?? 'password');
    }

    public function amountError($NeededMinAmount, $message = null, $addtionalMessage = null)
    {
        return [
            'code' => 422, // status code
            'message' => ($message ?? "Initial deposit must be at least UGX {$NeededMinAmount} for this product. ") . $addtionalMessage,
            'error' => ($message ?? "Initial deposit must be at least UGX {$NeededMinAmount} for this product. ") . $addtionalMessage,
            'addtionalMessage' => $addtionalMessage
        ];
    }

    public function saveFile($file, $folderPath = 'uploads', $fileName = '', $type = 'file')
    {
        if ($type == 'base64') {
            return $this->saveStorageFilesBase64($file, $folderPath, $fileName);
        }

        return $this->saveStorageFiles($file, $folderPath);
    }

    public function saveStorageFiles($file, $folderPath = 'migration-import-files')
    {
        if (is_string($file)) {
            $file = request()->file($file);
        }

        if (! $file || ! $file->isValid()) {
            return null;
        }

        $originalName = $file->getClientOriginalName();
        $originalEx = $file->getClientOriginalExtension();
        $clean = preg_replace('/[^A-Za-z0-9]+/', '-', time() . '_' . $originalName);
        $fileName = trim($clean, '-') . '.' . $originalEx;

        $file->storeAs($folderPath, $fileName, 'public');

        return "storage/$folderPath/$fileName"; // better public path
    }

    public function saveStorageFilesBase64($base64, $folderPath = 'migration-import-files', $fileName = '')
    {
        if (! $base64) {
            return null;
        }
        $file = base64_decode($base64);
        $fileName = $fileName
            ? $fileName . '.png'
            : time() . Str::random(10) . '.png';
        $path = $folderPath . '/' . $fileName;
        Storage::disk('public')->put($path, $file);

        return $path;
    }

    public function strictTheTrigger($dataBaseName)
    {
        DB::unprepared('
            CREATE TRIGGER prevent_delete_system_' . $dataBaseName . '
            BEFORE DELETE ON ' . $dataBaseName . '
            FOR EACH ROW
            BEGIN
                IF OLD.system_type = "system" THEN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "System records cannot be deleted";
                END IF;
            END;
        ');
    }
    public    function paymentModeUsedForTransaction($account_id, $req, $altenativeMethod = "")
    {
        if (isset($account_id) && ($account_id == 'member-account')) {
            $res = DB::table('savings_accounts')->where('id', $req['account_id'])->first(['id', 'balance', 'code']);
            return [
                "name" => 'member-account',
                "id" => $res->id,
                "balance" => $res->balance,
                "gl_code" => $res->code
            ];
        } else if (isset($account_id) && is_numeric($account_id)) {
            $res = DB::table('chart_of_accounts')->where('id', $account_id)->first(['name', 'id', 'gl_code']);
            if (isset($res) && !empty($res)) {
                return [
                    "name" => '',
                    "id" => $res->id,
                    "balance" => null,
                    "gl_code" => $res->gl_code
                ];
            }
        }

        return [
            "id" => null,
            "name" => $altenativeMethod,
            "gl_code" => $altenativeMethod
        ];;
    }
    public function umbrella_code($code = 'umbl-code-')
    {
        return  $code . time() . ':' . rand(10000000, 99999999);
    }
}
