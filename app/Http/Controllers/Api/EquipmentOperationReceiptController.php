<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};

class EquipmentOperationReceiptController extends Controller
{
    public function show(Request $request, string $operation)
    {
        $receipt = DB::table('integrator_equipment_operations')->where('integrator_id', $request->attributes->get('integrator')->id)->where('actor_id', $request->user()->id)->where('operation_id', $operation)->first();
        abort_unless($receipt, 404);

        return response(Crypt::decryptString($receipt->response), 200, ['Content-Type' => 'application/json']);
    }
}
