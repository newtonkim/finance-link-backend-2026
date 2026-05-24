<?php

namespace App\Tenant\Services;

use App\Tenant\Services\TenantSavingsAcountServices\OtherHelpers;
use Illuminate\Support\Facades\DB;

class TenantSettingService extends TenantSettingUpdateOrCreateService
{
    protected array $permissionDbFields = [
        'id AS id',
        'action AS name',
        'parent_module AS module',
        'description AS description',
        'created_at AS created_at',
    ];

    protected array $opencapitalDbFields = [
        'sc.id AS id',
        'sc.open_capital AS capital',
        'sc.opening_balance AS blc',
        'sc.open_capital_points_walth_amount AS amount',
        'sc.created_at AS created_at',
        'sc.code AS code',
        'sc.status AS status',
        // 'sc.share_price AS price',
        'sc.share_price AS share_price',
    ];

    protected $shareTransactionDbFields = [
        'trs.member_id',
        'mbs.code as member_code',
        'trs.reference',
        'trs.amount',
        'trs.charge_amount',
        'trs.payment_mode',
        'trs.account_type as account_type',
        'narration',
        'trs.created_at',
    ];

    protected array $rolesDbFields = [
        'rl.id AS id',
        'rl.name AS name',
        'rl.created_at AS created_at',
        'rl.description AS dec',
    ];

    protected array $brachDbFields = [
        'br.id AS id',
        'br.name AS name',
        'br.phone AS contact_number',
        'br.code AS branch_code',
        'br.created_at AS created_at',
    ];

    protected array $shareTransactionChargesDbFields = [
        'trsc.code as code',
        'trsc.transaction_type as type',
        'trsc.minimum_shares as minimum_shares',
        'trsc.maximum_shares as maximum_shares',
        'trsc.status as status',
        'trsc.charge_type as charge_type',
        'trsc.amount as charge',
        'trsc.id as id',
        'trsc.created_at as created_at',
    ];

    private $paginatedBy = 100;

    private $otherHelpers;

    public function __construct(OtherHelpers $otherHelpers)
    {
        $this->otherHelpers = $otherHelpers;
    }

    public function systemAuditLogList()
    {
        $subdomain = request()->header('X-Tenant-Subdomain');
        $tenantId = DB::connection('master')->table('tenants')->where('subdomain', $subdomain)->first(['id'])->id;
        $table = DB::select("
        SELECT TABLE_NAME, UPDATE_TIME
        FROM information_schema.tables
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY UPDATE_TIME DESC 
        ");
        $req = request();
        $collection = [];
        foreach ($table as $key => $value) {
            // // $tables[] = $value->TABLE_NAME;
            // $status = isset($req['status']) && $req['status'] !== 'all' ? [$req['status']] : null;
            $query = DB::connection('sacco_logs')->table($value->TABLE_NAME . ' as d')
            ->where('d.sacco_log_tenant_id', $tenantId);

            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], [], filterable: ['date_of_log_action' => 'created_at']);
            }
            $data = $query->where('d.branch_id', $req->branch_id)->orderBy('d.date_of_log_action', 'DESC')
                ->paginate($this->paginatedBy);
            $collection[] = $data;
        }

        return $collection;
    }
    public function RolesDetails()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $roles = DB::table('roles as rl')
                ->select([...$this->rolesDbFields, 'default_permissions as permissions'])
                ->where('id', $req->id)
                ->first();
            $permissions = $this->isJSONToArray(json_decode($roles->permissions ?? '[]', true));
            if (! empty($permissions)) {
                $roles->permissions = DB::table('permissions')->whereIn('id', $permissions)->get(['id', 'action as name', 'parent_module as module', 'description as description']);
            }

            return $roles;
        });
    }

    public function shareTransactionChargeList()
    {
        $req = request();
        $status = isset($req['status']) && $req['status'] !== 'all' ? [$req['status']] : null;

        $query = DB::table('share_transaction_charges as trsc')
            ->when($status, function ($q) use ($status) {
                return $q->where('trsc.transaction_type', $status);
            });
        if ($req->has('search_keyword')) {
            $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->shareTransactionChargesDbFields, filterable: ['created_at' => 'trsc.created_at']);
        }

        return $query->where('trsc.branch_id', $req->branch_id)->select($this->shareTransactionChargesDbFields)->whereNull('trsc.deleted_at')->orderBy('trsc.created_at', 'DESC')
            ->paginate($this->paginatedBy);
    }

    public function smsConfigList()
    {
        return $this->otherHelpers->SettingsListPreparation(['system-sms-notifications']);
    }

    public function notificationList()
    {
        $req = request();
        request()->validate([
            'channel' => ['nullable', 'in:all,whatsapp,sms,email'],
            'search_keyword' => ['nullable', 'string'],
        ]);

        return $this->TryCatch(function () use ($req) {
            $fields = [
                'sn.code',
                'sn.title',
                'sn.body',
                'sn.from_module',
                'sn.sender_id',
                'staff.name AS sender_id',
                'sn.receiver_id',
                'sn.status',
                'sn.cost',
                'sn.other_data',
                'sn.read_at',
                'sn.sent_time_at',
                'sn.sender_type',
                'sn.created_at',
                'sn.reason_for_failure',
            ];
            $channel = $req['channel'] ?? null;
            $status = isset($req['status']) && $req['status'] !== 'all' ? [$req['status']] : null;
            $query = DB::table('sent_message_notifications as sn')
                ->join('staff', 'sn.sender_id', '=', 'staff.id')
                ->when($channel, function ($q) use ($channel) {
                    return $q->where('channel', $channel);
                })
                ->when($status, function ($q) use ($status) {
                    return $q->where('sn.status', $status);
                });
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $fields, filterable: ['created_at' => 'sn.created_at']);
            }

            return $query
                ->select($fields)
                ->whereNull('sn.deleted_at')
                ->orderBy('sn.created_at', 'DESC')->paginate($this->paginatedBy);
        });
    }

    public function notificationSettingsList()
    {
        $req = request();
        request()->validate([
            'channel' => ['required', 'in:all,whatsapp,sms,email'],
        ]);

        return $this->TryCatch(function () use ($req) {
            $channel = $req['channel'];
            // $status=isset($req['status']) && $req['status'] !== 'all' ? [$req['status']] : null;
            $query = DB::table('message_notification_settings')
                ->where('channel', $channel);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->permissionDbFields);
            }

            return $query->whereNull('deleted_at')
                ->orderBy('created_at', 'DESC')->paginate($this->paginatedBy);
        });
    }

    public function permissionDetails()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $permission = DB::table('permissions')->where('id', $req->id)->first($this->permissionDbFields);

            return $permission;
        });
    }

    public function permissionsDropdown()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('permissions')->select(['id', DB::raw("REPLACE(action,'-',' ') AS name")]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->permissionDbFields);
            }

            return $query->whereNull('deleted_at')->orderBy('created_at', 'DESC')->paginate($this->paginatedBy);
        });
    }

    public function permissionsListCollection()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('permissions')->select([
                ...$this->permissionDbFields,
            ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->permissionDbFields);
            }
            $dataCollection = $query->whereNull('deleted_at')
                ->orderBy('created_at', 'DESC')->paginate($this->paginatedBy);

            return $dataCollection;
        });
    }

    public function UserAttachedToRoleList()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('roles As rl')
                ->join('staff As pu', 'rl.id', '=', 'pu.role_id')
                ->select([
                    ...$this->rolesDbFields,
                    'pu.name As staff_name',
                    'pu.id As staff_id',
                ])->where('rl.id', $req->id);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->permissionDbFields);
            }
            $dataCollection = $query->whereNull('rl.deleted_at')
                ->orderBy('rl.created_at', 'DESC')->paginate($this->paginatedBy);

            return $dataCollection;
        });
    }

    public function capitalizeLogList()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('share_capitalization_history As sc')
                ->select([
                    ...$this->opencapitalDbFields,
                    'new_open_capital As new_capital',
                    'new_open_capital_points_walth_amount As new_capital_points_amount',
                    'new_opening_balance AS new_capital_remaining',
                    'new_share_price As new_share_price',
                    // "other_data As other_data",
                ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->opencapitalDbFields);
            }
            $dataCollection = $query->whereNull('deleted_at')
                ->orderBy('created_at', 'DESC')->paginate($this->paginatedBy);

            return $dataCollection;
        });
    }

    public function capitalizeList()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('share_capitalization As sc')->select([
                ...$this->opencapitalDbFields,
            ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->opencapitalDbFields);
            }
            $dataCollection = $query->whereNull('deleted_at')
                ->orderBy('created_at', 'DESC')->paginate($this->paginatedBy);

            return $dataCollection;
        });
    }

    public function RolesListCollection()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('roles As rl')->select([
                ...$this->rolesDbFields,
            ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->rolesDbFields);
            }
            $dataCollection = $query->whereNull('deleted_at')
                ->orderBy('created_at', 'DESC')->paginate($this->paginatedBy);

            return $dataCollection;
        });
    }

    public function permissionListHolders()
    {
        $req = request()->all();

        return $this->TryCatch(function () use ($req) {
            $dataCollection = DB::table('permissions_users')->select([
                'pu.name As staff_name',
                'pu.id As staff_id',
            ])->join('staff as pu', 'pu.id', '=', 'permissions_users.user_id')
                ->whereNull('pu.deleted_at')
                ->whereJsonContains('permission_ids', (int) $req['id'])
                ->orderBy('created_at', 'DESC')->paginate($this->paginatedBy);

            return $dataCollection;
        });
    }

    public function savingsGroupSettingsList()
    {
        return $this->otherHelpers->SettingsListPreparation(['savings-group']);
    }

    public function savingsSettingsList()
    {
        return $this->otherHelpers->SettingsListPreparation(['savings-accounts']);
    }

    public function loanSettingsList()
    {
        return $this->otherHelpers->SettingsListPreparation(['loan-application']);
    }

    public function onboardingSettingsList()
    {

        return $this->otherHelpers->SettingsListPreparation(['members-onboarding'], ['sacco-share-on-member-creation-create-share-account-at-the-same-time', 'sacco-share-price-value', 'sacco-share-on-member-creation-create-share-minimum-value']);
    }

    public function shareSettingList()
    {

        return $this->otherHelpers->SettingsListPreparation(['share']);
    }

    public function branchList()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('branches As br')
                ->leftJoin('staff As pu', 'br.manager_id', '=', 'pu.id')
                ->select($this->brachDbFields);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], [
                    ...$this->brachDbFields,
                    'pu.id',
                    'pu.name',
                    'pu.email',
                    'pu.code',
                ]);
            }
            $dataCollection = $query->whereNull('br.deleted_at')
                ->orderBy('br.created_at', 'DESC')->paginate($this->paginatedBy);

            return $dataCollection;
        });
    }

    public function branchDetails()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('branches As br')
                ->leftJoin('staff As pu', 'br.manager_id', '=', 'pu.id')
                ->select([
                    ...$this->brachDbFields,
                    DB::raw("JSON_OBJECT('id',pu.id,'name',pu.name,'email',pu.email,'code',pu.code) As manager"),
                ]);
            $dataCollection = $query->whereRaw('br.id = ?', [$req->id])->first();

            return $dataCollection;
        });
    }

    public function branchesDropDownList()
    {
        return $this->TryCatch(function () {// dont touch what you  did do 
            return $this->dropDownList('branches');
        });
    }
}
