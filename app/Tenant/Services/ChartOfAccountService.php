<?php

namespace App\Tenant\Services;

use Illuminate\Support\Facades\DB;

class ChartOfAccountService extends ChartOfAccountUpdateOrCreateService
{
    protected $chartOfAccountDbFields = [

    ];

    public function chartOfAccountDropDownList()
    {
        return $this->TryCatch(function () {
            $req = request();
            // return $req->account_type;
            $query = DB::table('chart_of_accounts as rl')
                ->where('rl.is_active', 1)
                ->whereNull('rl.deleted_at');

            // Handle postable filter - default to true unless is_parent is requested
            $isParent = $req->boolean('is_parent');
            if ($isParent) {
                $query->where('rl.is_postable', 0);
            } else if ($req->has('is_postable')) {
                $query->where('rl.is_postable', $req->boolean('is_postable'));
            } else {
                $query->where('rl.is_postable', 1);
            }

            if ($req->has('account_type') || $req->has('type')) {
                $type = strtoupper($req->account_type ?? $req->type);
                $query->whereRaw('rl.account_type = ?', [$type]);
            }

            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], ['rl.gl_code', 'rl.name']);
            }

            return $query->select(['rl.id AS id', DB::raw("CONCAT(rl.gl_code, ' - ', rl.name) AS name")])
                ->orderBy('rl.gl_code', 'ASC')
                ->paginate($req->input('per_page', 600));
        });
    }
}
