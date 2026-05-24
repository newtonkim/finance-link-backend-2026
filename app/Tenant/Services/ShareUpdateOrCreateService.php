<?php

namespace App\Tenant\Services;

use App\Http\Globals\GlobalHelpers;
use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ShareUpdateOrCreateService extends GlobalHelpers
{
    public function saccoSellingSharescUOrCFields($req)
    {
        return $this->removeAllNullValues([
            'member_id' => $req['member_id'] ?? null,
            'buyer_payment_mode' => $req['payment_mode'] ?? null,
            'share_value' => $req['price'] ?? null,
            'share_no' => $req['share_no'] ?? null,
            'total_value' => $req['amount'] ?? null,
            'branch_id' => $req['branch_id'] ?? null,
            'purchased_at' => $req['trans_date'] ?? null,
        ]);
    }

    public function saccoTransferingSharescUOrCFields($req)
    {
        return $this->removeAllNullValues([
            'member_id' => $req['receiving_member_id'] ?? null,
            'transferring_member_id' => $req['transferring_member_id'] ?? null,
            'buyer_payment_mode' => 'transfer' ?? null,
            'share_value' => $req['share_price'] ?? null,
            'share_no' => $req['share_no'] ?? null,
            'total_value' => $req['amount'] ?? null,
            'branch_id' => $req['branch_id'] ?? null,
            'purchased_at' => $req['trans_date'] ?? null,
            'branch_id' => $req['branch_id'] ?? null,
        ]);
    }

    public function capitalizeCheck($req)
    {

        $capitalize = DB::table('share_capitalization')
            ->where('branch_id', $req['branch_id'])
            ->where('status', 'active')
            ->orderBy('created_at', 'DESC')
            ->first(['opening_balance', 'status', 'id', 'share_price']);
        if (isset($capitalize->opening_balance) && $capitalize->opening_balance <= 0) {
            throw new \Exception('Share Capitalization blc is empty/its not active', 409);
        }
        if (! isset($capitalize)) {
            throw new \Exception('Share Capitalization is empty / create one to continue', 409);
        }

        return $capitalize;
    }

    function transferAction($req, $shareNo, $codeSequence, $fields)
    {
        $doesMemberExist = DB::table('shares')->where('member_id', $req['receiving_member_id'])->first(['id', 'share_no', 'total_value']);
        $SharePrice = DB::table('system_settings')->where('settings_name', 'sacco-share-price-value')->first('settings_action');
        $getSenderShares = DB::table('shares')->where('member_id', $req['transferring_member_id'])->first();
        if ($getSenderShares->share_no < $req['share_no']) {
            return "You cannot transfer more shares than you have";
            // throw new \Exception('You cannot transfer more shares than you have', 409);
        }
        $priceNowForShare = json_decode($SharePrice->settings_action, true)['action'];
        $culculatedAmount = $shareNo * $priceNowForShare;
        if (isset($doesMemberExist->id)) {
            $shareTableDetails = $this->UpdateOrCreateRecord('shares', [
                "share_no" => $doesMemberExist->share_no + $shareNo,
                "share_value" => $priceNowForShare,
                "total_value" => $doesMemberExist->total_value + $culculatedAmount
            ], [
                'member_id' => $req['receiving_member_id'],
            ]);
        } else {
            $code = $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares');
            $fields['code'] = $code;
            $shareTableDetails = $this->UpdateOrCreateRecord('shares', [
                "share_no" => $shareNo,
                "share_value" => $priceNowForShare,
                "total_value" => $culculatedAmount,
                ...$fields

            ]); // create it
        }
        // update the sender shares
        $total = $getSenderShares->share_no - $req['share_no'];
        $this->UpdateOrCreateRecord('shares', ['share_no' => $total, 'total_value' => ($getSenderShares->total_value - $culculatedAmount) * $priceNowForShare], ['id' => $getSenderShares->id]);
        return $shareTableDetails;
    }


    public function createShare($hasTosharesOf, $fields, $codeSequence, $req, $capitalize, $type = null)
    {
        $currentShareNo = $fields['share_no'];
        if ($type == 'transfer') {
            return   $this->transferAction($req, $currentShareNo, $codeSequence, $fields);
        } else {
            $doesMemberExist = DB::table('shares')->where('member_id', $fields['member_id'])->first('id');

            if (isset($doesMemberExist->id)) {
                // $fields['share_no'] =;// save the shares
                $shareTableDetails = $this->UpdateOrCreateRecord('shares', [...$fields, "share_no" => $hasTosharesOf + $currentShareNo], [
                    'member_id' => $fields['member_id'],
                ]);
                if (isset($shareTableDetails->erro)) {
                    throw new \Exception($shareTableDetails->erro, 409);
                }
            } else {
                $code = $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares');
                $fields['code'] = $code;
                $shareTableDetails = $this->UpdateOrCreateRecord('shares', $fields); // create it
                if (($shareTableDetails->erro)) {
                    throw new \Exception($shareTableDetails->erro, 409);
                }
            }

            if ($capitalize->opening_balance >= $fields['share_no']) {
                $shares = DB::table('shares')->where('member_id', $fields['member_id'])->first();
                $SharePrice = DB::table('system_settings')->where('settings_name', 'sacco-share-price-value')->first('settings_action');
                $priceNowForShare = json_decode($SharePrice->settings_action, true)['action'];
                $culculatedAmount = $currentShareNo * $priceNowForShare;
                // DB::table('shares')->where('id', $shareTableDetails->id)->update(['total_value' => $shares->total_value + $culculatedAmount]);
                // DB::table('share_capitalization')->where('id', $capitalize->id)->update(['opening_balance' => $capitalize->opening_balance - $currentShareNo]);


                $checker =   $this->UpdateOrCreateRecord(
                    'shares',
                    ['total_value' => $culculatedAmount],
                    // ['total_value' => $shares->total_value + $culculatedAmount],
                    ['id' => $shareTableDetails->id]
                );

                if (isset($checker->error)) {
                    throw new \Exception($checker->error, 409);
                }

                $checker = $this->UpdateOrCreateRecord(
                    'share_capitalization',
                    ['opening_balance' => $capitalize->opening_balance - $currentShareNo],
                    ['id' => $capitalize->id]
                );
                if (isset($checker->error)) {
                    throw new \Exception($checker->error, 409);
                }

                return $shareTableDetails;
            } else {
                throw new \Exception('Share Capitalization Available blc is ' . $capitalize->opening_balance, 409);
            }
        }
    }



    public function saveShareTransactionV2($transactionList)
    {
        $user = auth()->user();
        $deposited_by = $user->name . ' (CODE:' . ($user->code ?: $user->id) . ')';
        foreach ($transactionList as $key => $value) {
            $value['deposited_by'] = $deposited_by;
            $ShareTransactionData = $this->removeAllNullValues($value);
            $this->UpdateOrCreateRecord('transactions', $ShareTransactionData);
        }

        // create a shared transaction
    }

    public function saccoSharesTransfer()
    {

        request()->validate([
            'transferring_member_id' => 'required',
            'receiving_member_id' => 'required',
            'share_no' => 'required',
            'share_price' => 'required',
            'amount' => 'required',
            'trans_date' => 'required',

        ]);

        return $this->TryCatch(function () {
            return $this->transaction(function () {
                $req = request();
                $codeSequence = new CodeSequence;
                $fields = $this->saccoTransferingSharescUOrCFields($req);
                $capitalize = $this->capitalizeCheck($req);
                $umbrella_code = $this->umbrella_code();

                $hasTosharesOf = DB::table('shares')->where('member_id', $fields['member_id'])->sum('share_no');
                $prveiousShares = DB::table('shares')->where('member_id', $fields['member_id'])->first();
                $sendingAcountBefore = DB::table('shares')->where('member_id', $fields['transferring_member_id'])->first();

                // $setting = DB::table('system_settings')->where('settings_name', 'sacco-share-maximum-share-numbers-one-should-have')->first('settings_action');

                // if ($hasTosharesOf >= $setting->settings_action) {
                //     throw new \Exception('You can not transfer more than ' . $setting->settings_action . ' shares at a time', 409);
                // }

                $fields['purchased_at'] = Carbon::parse($req['trans_date'])->format('Y-m-d H:i:s');
                $prveiousShares->ref = 'details about the share before transaction';
                if (! isset($capitalize->share_price)) {
                    $SharePrice = DB::table('system_settings')->where('settings_name', 'sacco-share-price-value')->first('settings_action');
                    $priceNowForShare = json_decode($SharePrice->settings_action, true)['action'];
                } else {
                    $priceNowForShare = $capitalize->share_price;
                }

                $referid = DB::table('members')->where('id', $fields['transferring_member_id'])->first();
                $prveiousShares->share_price_durring_transaction = $priceNowForShare;
                $prveiousShares->transacted_share_points = $req['share_no'];
                $prveiousShares->transfering_member_before_transaction_completed = $sendingAcountBefore;
                $prveiousShares->receiving_member_id = $fields['member_id'];
                $memberDetails = DB::table('members')->where('id', $fields['member_id'])->first(['name', 'code']);
                $narration = 'Share Transfer from ' . $referid->name . '(' . $referid->code . ') to ' . $memberDetails->name . '(' . $memberDetails->code . ') Capital blc ' . $capitalize->opening_balance - $fields['share_no'];
                $shareTableDetails = $this->createShare($hasTosharesOf, $fields, $codeSequence, $req, $capitalize, 'transfer');

                if (isset($shareTableDetails->error)) {
                    throw new \Exception($shareTableDetails->error, 409);
                }

                if (!isset($shareTableDetails->id)) {
                    $narration = 'Share Transfer from ' . $referid->name . '(' . $referid->code . ') to ' . $memberDetails->name . '(' . $memberDetails->code . ') Capital blc ' . $capitalize->opening_balance - $fields['share_no'] . " Failed";
                }

                $fields['meta_details'] = (array) ($prveiousShares);

                $transactionList['shares'] = [
                    'member_id_transferring_shares' => $fields['transferring_member_id'] ?? null,
                    'umbrella_code' => $umbrella_code ?? null,
                    'reference' =>  $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares'),
                    'code' =>  $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares'),
                    'member_id' => $shareTableDetails->member_id,
                    'amount' => ((float) $fields['total_value']),
                    'payment_mode' => $fields['buyer_payment_mode'],
                    'transaction_date' => $fields['purchased_at'] ?? Carbon::now()->toDateString(),
                    'account_id' => $shareTableDetails->id,
                    'type' => 'share-transaction',
                    'meta_details_before_transaction' =>  $fields['meta_details'],
                    'narration' => $narration,
                    'amount_before_transactions' => $prveiousShares->share_no,
                    'branch_id' => $fields['branch_id'],
                    'account_type' => "share transfer",
                ];
                if (isset($fields['charges_amount']))
                    $transactionList['selling-charge'] = [
                        'umbrella_code' => $umbrella_code ?? null,
                        ...$transactionList['shares'],
                        'member_id_transferring_shares' => $fields['transferring_member_id'] ?? null,

                        'reference' =>  $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares'),
                        'code' =>  $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares'),
                        'charge_amount' => $fields['charges_amount'] ?? 0,
                        'amount' => 0,
                        'type' => 'share-transaction-transfer-charge',
                    ];
                // return $transactionList;


                $this->saveShareTransactionV2($transactionList);
                $list = app(SharesService::class);
                return $list->shareHolderTransaction();
            });
        });
    }

    public function saccoSharesWithdrawal()
    {
        request()->validate([
            'member_id' => 'required',
            'amount' => 'required',
            'share_no' => 'required',
            'trans_date' => 'required',
        ]);

        return $this->transaction(function () {
            return $this->TryCatch(function () {
                $req = request();
                $umbrella_code = $this->umbrella_code();

                // $Chargesservice = new ProductChargesservice;
                $currentShareNo = $req['share_no'];
                $capitalize = $this->capitalizeCheck($req);

                $shareTableDetails = DB::table('shares')->where('member_id', $req['member_id'])->first();
                if (! isset($shareTableDetails->share_no)) {
                    throw new \Exception('You have no shares to withdraw', 409);
                }
                $AfterWithdrawal = $shareTableDetails->share_no - $currentShareNo;
                if ($AfterWithdrawal < 0) {
                    throw new \Exception('You have no shares to withdraw', 409);
                }
                // $SharePrice = DB::table('system_settings')->where('settings_name', 'sacco-share-price-value')->first('settings_action');
                // $priceNowForShare = json_decode($SharePrice->settings_action, true)['action'];
                if (! isset($capitalize->share_price)) {
                    $SharePrice = DB::table('system_settings')->where('settings_name', 'sacco-share-price-value')->first('settings_action');
                    $priceNowForShare = json_decode($SharePrice->settings_action, true)['action'];
                } else {
                    $priceNowForShare = $capitalize->share_price;
                }
                $culculatedAmount = $currentShareNo * $priceNowForShare;
                $addTotalValue = $shareTableDetails->total_value + $culculatedAmount;
                if ($shareTableDetails->share_no >= $currentShareNo) {
                    DB::table('shares')->where('id', $shareTableDetails->id)->update(['share_no' => $AfterWithdrawal, 'total_value' => $addTotalValue]);
                }

                $fields = [
                    'total_value' => $req['amount'],
                    'buyer_payment_mode' => 'withdrawal',
                    'purchased_at' => $req['trans_date'],
                    'branch_id' => $req['branch_id'],
                    'type' => 'share-withdrawal',

                ];
                //  $chargedAmount = $getTheProductCharges->cost;
                //  $deposit = $deposit - $chargedAmount;
                $narration = "Share withdrawal ($currentShareNo) from " . $shareTableDetails->share_no . " worth " . $fields['total_value'];
                $shareTableDetails->ref = 'details about the share before transaction';
                $codeSequence = new CodeSequence;

                $shareTableDetails->share_price_durring_transaction = $priceNowForShare;
                $shareTableDetails->transacted_share_points = $req['share_no'];

                $fields['meta_details'] = (array) $shareTableDetails;
                $transactionList['shares'] = [
                    'umbrella_code' => $umbrella_code ?? null,
                    'reference' =>  $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares'),
                    'code' =>  $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares'),
                    'member_id' => $shareTableDetails->member_id,
                    'amount' => ((float) $fields['total_value']),
                    'payment_mode' => $fields['buyer_payment_mode'],
                    'transaction_date' => $fields['purchased_at'] ?? Carbon::now()->toDateString(),
                    'account_id' => $shareTableDetails->id,
                    'type' => 'share-transaction',
                    'account_type' => "share withdrawal",
                    'amount_before_transactions' =>  $shareTableDetails->share_no,
                    'narration' => $req['narration'] ?? $narration ?? null,
                    'meta_details_before_transaction' => $fields['meta_details'] ?? null,
                    'branch_id' => $fields['branch_id'],
                ];
                if (isset($fields['charges_amount'])) {

                    $transactionList['selling-charge'] = [
                        'umbrella_code' => $umbrella_code ?? null,
                        ...$transactionList['shares'],
                        'reference' =>  $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares'),
                        'code' =>  $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares'),
                        'charge_amount' => $fields['charges_amount'] ?? 0,
                        'amount' => 0,
                        'type' => 'share-transaction',
                        'account_type' => 'share-withdrawal-charge',
                    ];
                }

                $this->saveShareTransactionV2($transactionList);
                $list = app(SharesService::class);
                return $list->shareHolderTransaction();
            });
        });
    }

    public function saccoSharesTransactionRevert()
    {

        return $this->transaction(function () {
            return $this->TryCatch(function () {
                $req = request();
                $trac = DB::table('transactions')
                    ->where([
                        'id' => $req['id'],
                        'is_reversed' => 0,
                    ])
                    ->first();
                if (! $trac) {
                    throw new \Exception('Transaction not found or already reversed');
                }

                $createdAt = Carbon::parse($trac->created_at);
                if ($createdAt->diffInDays(now()) > 2) {
                    throw new \Exception('You cannot revert this transaction after 2 days.');
                }
                // share purchase company to member

                $capitalize = DB::table('share_capitalization')
                    ->where('branch_id', $req['branch_id'])
                    // ->where('status', 'active')
                    ->orderBy('created_at', 'DESC')
                    ->first(['opening_balance', 'status', 'id', 'share_price']);

                if (isset($trac->meta_details_before_transaction)) {
                    $shareTableDetails = json_decode($trac->meta_details_before_transaction, true);

                    $reversingId = $shareTableDetails['id'];
                    $transacted_share_points = $shareTableDetails['transacted_share_points'] ?? 0;
                    $getShareId = DB::table('shares')
                        ->where('id', $reversingId)
                        ->first();
                    if (! $getShareId) {
                        throw new \Exception('Share not found');
                    }

                    // .......
                    $shareca = $capitalize->share_price * $transacted_share_points;
                    // return $trac;
                    if ($trac->account_type != 'share transfer') {
                        $this->UpdateOrCreateRecord('shares', ['share_no' => $getShareId->share_no - $transacted_share_points, 'total_value' => $shareca], ['id' => $reversingId]);
                        DB::table('share_capitalization')->increment('opening_balance', $transacted_share_points);
                        $beforeTransaction = [
                            'beforeTransactionSenderRevert' => $getShareId,
                        ];
                    } else {
                        $transfering_member_id = $shareTableDetails['transferring_member_id'];
                        $receiving_member_id = $shareTableDetails['receiving_member_id'];
                        $getMemberTransferdeta = DB::table('shares')->where('member_id', $transfering_member_id)->first();
                        $getMemberReceived = DB::table('shares')->where('member_id', $receiving_member_id)->first();

                        $beforeTransaction = [
                            'beforeTransactionReceiverRevert' => $getMemberReceived,
                            'beforeTransactionSenderRevert' => $getMemberTransferdeta,
                        ];

                        $this->UpdateOrCreateRecord('shares', ['share_no' => $getMemberTransferdeta->share_no + $transacted_share_points, 'total_value' => $shareca], ['member_id' => $transfering_member_id]);
                        $this->UpdateOrCreateRecord('shares', ['share_no' => $getMemberReceived->share_no - $transacted_share_points, 'total_value' => $shareca], ['member_id' => $receiving_member_id]);
                    }

                    $this->UpdateOrCreateRecord('transactions', ['is_reversed' => 1, 'system_type' => 'system'], ['id' => $req['id']]);
                    $beforeTransaction['trancation_record_before_revert'] = $trac;

                    if (request()->has('id')) {
                        request()->request->remove('id');
                    }
                    $codeSequence = new CodeSequence;

                    $transactionList['shares'] = [
                        'umbrella_code' => $trac->umbrella_code ?? null,
                        'reference' =>  $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares'),
                        'code' =>  $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares'),
                        'member_id' => $trac->member_id,
                        'amount' => ($trac->amount ?? $trac->charge_amount),
                        'payment_mode' => 'reversed',
                        'transaction_date' => $fields['purchased_at'] ?? Carbon::now()->toDateString(),
                        'account_id' => $trac->account_id,
                        'type' => 'share-transaction',
                        'account_type' => "share reverted",
                        'amount_before_transactions' =>  $getShareId->share_no ?? 0,
                        'narration' => $fields['narration'] ?? 'Revert transaction',
                        'meta_details_before_transaction' => $beforeTransaction ?? null,
                        'branch_id' => $trac->branch_id,
                        "is_reversed" => 1,

                    ];



                    $this->saveShareTransactionV2($transactionList);
                    $list = app(SharesService::class);
                    return $list->shareHolderTransaction();
                }
            });
        });
    }


    public function saccoSellingShares()
    {
        request()->validate([
            'member_id' => 'required',
            'payment_mode' => 'required',
            'amount' => 'required',
            'share_no' => 'required',
            'price' => 'required',
            'trans_date' => 'required',
        ]);

        // return 3;
        return $this->transaction(function () {
            return $this->TryCatch(function () {
                $req = request();
                $capitalize = $this->capitalizeCheck($req);
                $umbrella_code = $this->umbrella_code();

                $codeSequence = new CodeSequence;
                $fields = $this->saccoSellingSharescUOrCFields($req);
                $memberDetails = DB::table('members')->where('id', $fields['member_id'])->first(['name', 'code']);
                $hasTosharesOf = DB::table('shares')->where('member_id', $fields['member_id'])->sum('share_no');
                $prveiousShares = (object) DB::table('shares')->where('member_id', $fields['member_id'])->first();
                $setting = DB::table('system_settings')->where('settings_name', 'sacco-share-maximum-share-numbers-one-should-have')->first('settings_action');
                $limit = json_decode($setting->settings_action, true)['action'];


                if ($limit >= ($hasTosharesOf + $fields['share_no'])) {
                    $fields['purchased_at'] = Carbon::parse($fields['purchased_at'])->format('Y-m-d H:i:s');

                    $shareTableDetails = $this->createShare($hasTosharesOf, $fields, $codeSequence, $req, $capitalize);

                    // save details for before transaction  usefull for future
                    $prveiousShares->ref = 'details about the share before transaction';
                    $SharePrice = DB::table('system_settings')->where('settings_name', 'sacco-share-price-value')->first('settings_action');
                    $priceNowForShare = json_decode($SharePrice->settings_action, true)['action'];
                    $prveiousShares->share_price_durring_transaction = $priceNowForShare;
                    $prveiousShares->transacted_share_points = $req['share_no'];

                    $accounts = $this->paymentModeUsedForTransaction($req['payment_mode'], $req, $req['payment_mode']);

                    if ($accounts['name'] == 'member-account') {
                        $balanceComputations = $accounts['balance'] - $fields['total_value'];
                        if ($balanceComputations < 0) {
                            throw new \Exception('Insufficient balance from this personal account', 400);
                        }
                        $prveiousShares->account_balance_before_transaction = $accounts;;
                        $checker =    $this->UpdateOrCreateRecord('savings_accounts', [
                            'balance' => $balanceComputations
                        ], ['id' => $accounts['id']]);
                        if (isset($checker->error)) {
                            throw new \Exception($checker->error, 400);
                        }
                    }

                    $fields['meta_details'] = (array) ($prveiousShares);
                    $fields['type'] = 'share purchase company to member';

                    $transactionList['shares'] = [
                        'umbrella_code' => $umbrella_code ?? null,
                        'reference' =>  $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares'),
                        'code' =>  $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares'),
                        'member_id' => $shareTableDetails->member_id,
                        'amount' => ((float) $fields['total_value']),
                        'transaction_date' => $fields['purchased_at'] ?? Carbon::now()->toDateString(),
                        'account_id' => $shareTableDetails->id,
                        'type' => 'share-transaction',
                        'account_type' => "share purchase",
                        'payment_mode' => $accounts['name'] ?? null,
                        'payment_mod_account_id' => $accounts['id'] ?? null,
                        'amount_before_transactions' =>  $prveiousShares->share_no,
                        'narration' => $req['narration'] ??  'Share Purchased by ' . $memberDetails->name . '(' . $memberDetails->code . ') Capital blc ' . $capitalize->opening_balance - $fields['share_no'],
                        'meta_details_before_transaction' => $fields['meta_details'] ?? null,
                        'branch_id' => $fields['branch_id'],
                    ];
                    if (isset($fields['charges_amount']))
                        $transactionList['selling-charge'] = [
                            'umbrella_code' => $umbrella_code ?? null,
                            ...$transactionList['shares'],
                            'reference' =>  $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares'),
                            'code' =>  $codeSequence->codeSequence($req['code'] ?? null, 'share', 'share', 'shares'),
                            'charge_amount' => $fields['charges_amount'] ?? 0,
                            'amount' => 0,
                            'account_type' => 'share-transaction-selling-charge',
                            'type' => 'share-transaction',

                        ];

                    $this->saveShareTransactionV2($transactionList);
                    $list = app(SharesService::class);
                    return $list->shareHolderTransaction();
                } else {
                    // hehehe i know am a bad person joking alot in code
                    throw new \Exception('HEY HEY HEY... HOLLAN !, You can not have more than ' . $limit . ' shares');
                }
            });
        });
    }
}
