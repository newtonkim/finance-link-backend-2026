<?php

namespace App\Tenant\Services;

use App\Http\Globals\GlobalHelpers;
use App\Tenant\Services\MemebersSettingSevices\MemberHelpers;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MemberUpdateOrCreateService extends GlobalHelpers
{
    public function unarchiveMember()
    {
        request()->validate([
            'id' => 'required|numeric|exists:members,id',
        ]);
        $this->UpdateOrCreateRecord('members', ['dormant_date' => null], ['id' => request('id')], nullOverride: true);
        request()->merge(['status' => 'dormant']);
        $membersList = app(MemberService::class);
        return $membersList->membersList();
    }

    public function mememberUOrCFields($req)
    {
        return $this->removeAllNullValues([
            'member_type' => $req['member_type'] ?? null,
            'name' => $req['full_name'] ?? null,
            'salutation' => $req['salutation'] ?? null,
            'gender' => $req['gender'] ?? null,
            'phone' => $req['primary_contact'] ?? null,
            'other_contact' => $req['other_contacts'] ?? null,
            'email' => $req['email'] ?? null,
            'status' => $req['status'] ?? null,
            'national_id_number' => $req['national_id'] ?? null,
            // 'payment_mode' => $req['payment_mode'] ?? null,
            'mobile_money_number' => $req['mobile_money_number'] ?? null,
            'marital_status' => $req['marital_status'] ?? null,
            'nationality' => $req['nationality'] ?? null,
            'address' => $req['address'] ?? null,
            'next_of_kin' => $req['next_of_kin'] ?? null,
            'next_of_kin_contact' => $req['next_of_kin_contact'] ?? null,
            'initial_deposit' => $req['inital_deposit'] ?? null,
            'joined_date' => $this->normalizeDate($req['joined_date'] ?? null),
            'referred_by' => $req['referred_by'] ?? null,
            'opening_balance' => $req['opening_balance'] ?? null,
            'shares_quantity' => $req['shares_quantity'] ?? null,
            'branch_id' => $req['branch_id'] ?? null,
            'profile_path' => $req['profile_path'] ?? null,
            // 'dob' =>   trim($req['date_of_birth']?? '', '"')  ?? null,
            'dob' => isset($req['date_of_birth']) ? Carbon::parse(trim($req['date_of_birth'], '"'))->format('Y-m-d') : null,
            'created_from' => 'normal',
        ]);
    }

    public function dormantMemebers()
    {
        // $this->DeleteRecord('members', request());
        $this->UpdateOrCreateRecord('members', ['dormant_date' => now()],);
        $membersList = app(MemberService::class);
        return $membersList->membersList();
    }


    public function importMemberViaExcel()
    {
        $branch_id = request('branch_id');
        $req = request()->except(['file', 'index', 'branch_id']);
        if (request()->has('id')) {
            request()->request->remove('id');
        }

        $procucts = DB::table('savings_products')->get(['id', 'code', 'name']);
        foreach ($procucts as $key2 => $value2) {
            $Productlist[$value2->code] = $value2->id;
        }

        $collection = [];
        // Step 1: Normalize keys (remove [0], [1], etc.)
        $normalized = [];
        foreach ($req as $key => $values) {
            $cleanKey = preg_replace('/\[\d+\]/', '', $key);
            $normalized[$cleanKey] = $values;
        }

        // Step 2: Convert column-based input to row-based
        foreach ($normalized as $field => $values) {
            foreach ($values as $index => $value) {
                $collection[$index][$field] = $value;
            }
        }

        // Step 3: Process rows
        $memberService = new MemberHelpers;
        $failed = [];
        $passed = 0;
        // return $collection;
        foreach ($collection as $index => $row) {

            $memebrFields = [
                'member_type' => 'existing_member',
                // 'code' => $row['member_number'],
                'name' => $row['name'] ?? null,
                'phone' => $row['phone'] ?? null,
                'email' => $row['email'] ?? null,
                'status' => $row['status'] ?? null,
                'national_id_number' => $row['national_id_number'] ?? null,
                'mobile_money_number' => $row['mobile_money_number'] ?? null,
                'marital_status' => $row['marital_status'] ?? null,
                'nationality' => $row['nationality'] ?? null,
                'address' => $row['address'] ?? null,
                'next_of_kin' => $row['next_of_kin'] ?? null,
                'next_of_kin_contact' => $row['next_of_kin_contact'] ?? null,
                'initial_deposit' => $row['initial_deposit'] ?? null,
                'joined_date' => $this->normalizeDate($row['joined_date'] ?? null),
                'referred_by' => $row['referred_by'] ?? null,
                'opening_balance' => $row['opening_balance'] ?? null,
                'shares_quantity' => $row['shares_quantity'] ?? null,
                'branch_id' => $row['branch_id'] ?? null,
                'dob' => ($row['dob'] ?? null),
                'created_from' => 'migration',
                'branch_id' => $branch_id,
            ];





            $result = $memberService->createNewSaccoMemebers($memebrFields, [
                ...$row,
                'code' => $row['member_number'] ?? null,
                'product_id' => isset($row['product_code']) ? $Productlist[$row['product_code']] : null,
                'branch_id' => $row['branch_id'] ?? null,
                'account_number' => isset($row['account_number']) && strlen($row['account_number']) > 2 && $row['account_number'] != 'null' ? $row['account_number'] : null,
                "opening_balance" => $row['opening_balance'] ?? null,
                "payment_mode" => $row['payment_mode'] ?? null,

            ]);
            if (!isset($result['error'])) {
                $passed++;
            } else {
                // $row['error'] = $result['error'];
                $failed[] = [...$row, 'error' => $result];
                // $failed[] = [
                //     'row_index' => $index,
                //     'data' => $row,
                //     'reason' => $result['error'],
                // ];
            }
        }

        // Optional: return structured response
        return [
            'total' => count($collection),
            'passed' => $passed,
            'failed' => $failed,
        ];
    }

    public function chargeMemberStatus()
    {
        request()->validate([
            'code' => 'required',
            'id' => 'nullable',
        ]);
        request('status');
        $req = request()->all();
        $condition = $req['id'] ? ['id' => $req['id']] : ['code' => $req['code']];

        return $accountDetails = $this->UpdateOrCreateRecord('members', ['status' => request('status')], $condition);
    }

    public function createNewSaccoMemebers()
    {
        request()->validate([
            'full_name' => 'required|string|max:255',
            'primary_contact' => 'nullable|string|max:255',
            'other_contacts' => 'nullable|string|max:255',
            'mobile_money_number' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'national_id' => 'nullable|string|max:255',
            'marital_status' => 'nullable|string|max:255',
            'nationality' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'profile_picture' => 'nullable',
            'next_of_kin' => 'nullable|string|max:255',
            'next_of_kin_contact' => 'nullable|string|max:255',
            'initial_deposit' => 'nullable|string|max:255',
            'joined_date' => 'nullable|string|max:255',
            'referred_by' => 'nullable|string|max:255',
            'member_type' => 'nullable|in:existing_member,new_member',
            'payment_method' => 'nullable|string|max:255',

        ]);
        $req = request()->all();
        $memberService = new MemberHelpers;

        $prifileString = $this->saveFile('profile_picture', 'members-profile');
        $req['profile_path'] = $prifileString;
        $fields = $this->mememberUOrCFields($req);

        $checker = $memberService->createNewSaccoMemebers($fields, $req);



        if (isset($checker) && ! isset($checker['error'])) {
            $membersList = app(MemberService::class);

            return $membersList->membersList();
        }

        return $checker;
        // throw new \Exception('share quantity must be greater than or equal to '.$checker['error'].' shares', 400);
    }
}
