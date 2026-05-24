<?php

namespace App\Http\Globals;

use App\Http\Controllers\Controller;

class BaseController extends Controller
{
    public function Response($c = ['data' => [], 'code' => 200, 'status' => 'OK', 'msg' => ''])
    {

        $req = request();
        $res = ['code' => 200, 'status' => 'OK'];
        extract($res);
        extract($c);

        if (@$status) {
            $res['status'] = $status;
        }
        if (@$msg) {
            $res['msg'] = $msg;
        }
        if (@$data) {
            $res['payload'] = $data;
        }
        if (@$code) {
            $res['code'] = $code;
        }

        if (is_object($data) && (isset($data->error) || isset($data->error))) {
            $code = 500;
            $res['error'] = $data['errors'] ?? $data['error'];
        }

        if (is_array($data) && (isset($data['error']) || isset($data['errors']))) {
            $code = 500;
            // $code = $data['code'] ?? 500;
            $res['error'] = $data['errors'] ?? $data['error'];
        }

        return response()->json(
            $res,
            $code
        );
    }
}
