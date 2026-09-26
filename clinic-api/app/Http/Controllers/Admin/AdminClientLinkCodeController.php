<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminClientLinkCodeController extends Controller
{
    public function store(Request $request, User $client)
    {
        abort_unless($client->hasRole('client'), 404);
        if (! empty($client->password)) {
            return response()->json(['message' => 'This client already has a mobile login.'], 409);
        }
        if (empty($client->phone)) {
            return response()->json(['message' => 'Add the client’s verified phone number before issuing a code.'], 422);
        }
        $code = (string) random_int(10000000, 99999999);
        DB::table('client_link_codes')->updateOrInsert(['user_id' => $client->id], [
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(15),
            'failed_attempts' => 0,
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json([
            'code' => $code,
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
            'message' => 'Give this one-time code privately to the verified client. It is shown only now.',
        ]);
    }
}
