<?php

namespace App\Central\Services;

use Illuminate\Support\Facades\DB;

class StaffService extends StaffUpdateOrCreateService
{
    protected array $staffDbFields = [
        'stf.id AS id',
        'stf.name AS staff_fall_name',
        'stf.email AS staff_email',
        'stf.email_verified_at AS email_verified_time',
        'stf.role_id AS system_role',
        'stf.status AS status',
        'stf.created_at AS created_at',
    ];

    public function staffDetails()
    {
        request()->validate([
            'id' => 'required|numeric|exists:platform_users,id',
            'staff_email' => 'nullable|email|exists:platform_users,email',
        ]);
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $tenant = DB::table('platform_users AS stf')
                ->where('stf.id', $req->id)
                ->orWhere('stf.email', $req->staff_email)
                ->first($this->staffDbFields);

            return $tenant;
        });
    }

    public function staffDropdownCollection()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('platform_users as rl')
                ->select(['rl.id AS id', 'name']);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], ['rl.id AS id', 'name']);
            }

            return $query
                ->whereNull('rl.deleted_at')
                ->orderBy('rl.id', 'DESC')->paginate($this->perpage());
        });
    }

    public function rolesDropdown()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('roles as rl')
                ->select(['rl.id AS id', 'name']);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], ['rl.id AS id', 'name']);
            }

            return $query
                ->whereNull('rl.deleted_at')
                ->orderBy('rl.id', 'DESC')->paginate($this->perpage());
        });
    }

    public function staffListCollection()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $staus = $req['status'] != 'all' ? [$req['status']] : ['active', 'suspended', 'expired', 'trial'];
            $query = DB::table('platform_users as stf')
                ->select($this->staffDbFields);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->staffDbFields);
            }

            return $query
                ->whereNull('stf.deleted_at')
                ->whereIn('stf.status', $staus)
                ->orderBy('stf.created_at', 'DESC')->paginate($this->perpage());
        });
    }
}
