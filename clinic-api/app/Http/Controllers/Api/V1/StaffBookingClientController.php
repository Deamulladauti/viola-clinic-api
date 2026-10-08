<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Admin\AdminClientController;
use App\Models\User;
use Illuminate\Http\Request;

class StaffBookingClientController extends Controller
{
    public function show(int $client)
    {
        $user = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'client'))->findOrFail($client);
        return response()->json(['data' => [
            'id' => $user->id, 'name' => $user->name,
            'phone' => $user->phone, 'email' => $user->email,
        ]]);
    }

    public function store(Request $request, AdminClientController $controller)
    {
        return $controller->store($request);
    }
}
