<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\CheckinService;
use Illuminate\Http\Request;

class CheckinController extends Controller
{
    public function status(Request $request, CheckinService $service)
    {
        return response(['data' => $service->status((int) $request->user['id'])]);
    }

    public function claim(Request $request, CheckinService $service)
    {
        return response($service->claimForApp((int) $request->user['id'], $request->input('type', 1), $request->input('input')));
    }
}
