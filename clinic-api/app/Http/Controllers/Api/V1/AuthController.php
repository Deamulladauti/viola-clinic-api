<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // POST /api/v1/auth/register
    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        // Normalize email to lowercase if provided
        if (!empty($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }

        // Check if a user with this phone already exists (pre-created user logic)
        $existing = User::where('phone', $data['phone'])->first();

        if ($existing) {
            // If the existing user already has a password, treat as fully registered
            if (!empty($existing->password)) {
                return response()->json([
                    'message' => 'An account with this phone already exists. Please log in or reset your password.',
                    'code'    => 'PHONE_ALREADY_REGISTERED',
                ], 409);
            }

            // Upgrade pre-created user
            $existing->name     = $data['name'];
            $existing->email    = $data['email'] ?? $existing->email;
            $existing->password = Hash::make($data['password']);
            $existing->save();

            $user = $existing;
        } else {
            // Create a brand new user
            $user = User::create([
                'name'     => $data['name'],
                'email'    => $data['email'] ?? null,
                'phone'    => $data['phone'],
                'password' => Hash::make($data['password']),
            ]);
        }

        // Ensure client role
        if (method_exists($user, 'assignRole') && ! $user->hasRole('client')) {
            $user->assignRole('client');
        }

        $user->loadMissing('roles');

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'token'     => $token,
            'tokenType' => 'Bearer',
            'user'      => new UserResource($user),
        ], 201);
    }

    // POST /api/v1/auth/login
    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        $identifier = $data['identifier'];
        $password   = $data['password'];

        // Allow login by phone OR email (case-insensitive for email)
        if (str_contains($identifier, '@')) {
            // Treat as email
            $user = User::whereRaw('LOWER(email) = ?', [strtolower($identifier)])
                        ->first();
        } else {
            // Treat as phone
            $user = User::where('phone', $identifier)->first();
        }

        if (! $user || ! Hash::check($password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials',
                'code'    => 'INVALID_CREDENTIALS',
            ], 401);
        }

        $user->loadMissing('roles');

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'token'     => $token,
            'tokenType' => 'Bearer',
            'user'      => new UserResource($user),
        ]);
    }

    // GET /api/v1/auth/me
    public function me(Request $request)
    {
        $user = $request->user()->loadMissing('roles');

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    // POST /api/v1/auth/logout
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }
}
