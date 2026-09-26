<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/register
     */
    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        $data['name'] = trim($data['name']);
        $data['phone'] = trim($data['phone']);

        if (! empty($data['email'])) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }

        /*
         * Find a pre-created client even if the phone was saved with
         * spaces, dashes, brackets, or a leading plus sign.
         */
        // Never allow a phone-only match to claim a clinic record.
        // Lock the record and its one-time code while consuming it.
        $result = DB::transaction(function () use ($data) {
            $existing = $this->findUserByPhone($data['phone']);
            if ($existing) {
                $existing = User::query()->lockForUpdate()->findOrFail($existing->id);
                if (! $existing->hasRole('client') || ! empty($existing->password)) {
                    return ['error' => 'PHONE_ALREADY_REGISTERED', 'message' => 'This phone is already registered. Please log in or contact the clinic.', 'status' => 409];
                }
                $entry = DB::table('client_link_codes')->where('user_id', $existing->id)->lockForUpdate()->first();
                if (! $entry || now()->greaterThan($entry->expires_at) || $entry->failed_attempts >= 5) {
                    return ['error' => 'LINK_CODE_REQUIRED', 'message' => 'Ask the clinic for a new verification code to link your existing record.', 'status' => 409];
                }
                if (! Hash::check((string) ($data['link_code'] ?? ''), $entry->code_hash)) {
                    DB::table('client_link_codes')->where('user_id', $existing->id)->increment('failed_attempts');
                    return ['error' => 'INVALID_LINK_CODE', 'message' => 'Invalid verification code. Ask the clinic for a new code if needed.', 'status' => 422];
                }
                if (! empty($data['email']) && User::query()->where('email', $data['email'])->where('id', '!=', $existing->id)->exists()) {
                    return ['error' => 'EMAIL_ALREADY_USED', 'message' => 'This email is already in use.', 'status' => 422];
                }
                $existing->name = $data['name'];
                $existing->phone = $data['phone'];
                $existing->email = $data['email'] ?? $existing->email;
                $existing->password = $data['password'];
                $existing->save();
                DB::table('client_link_codes')->where('user_id', $existing->id)->delete();
                return ['user' => $existing];
            }
            if (! empty($data['email']) && User::query()->where('email', $data['email'])->exists()) {
                return ['error' => 'EMAIL_ALREADY_USED', 'message' => 'This email is already in use.', 'status' => 422];
            }
            return ['user' => User::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'password' => $data['password'],
            ])];
        });
        if (isset($result['error'])) {
            return response()->json(['code' => $result['error'], 'message' => $result['message']], $result['status']);
        }
        $user = $result['user'];

        if (! $user->hasRole('client')) {
            $user->assignRole('client');
        }

        $user->loadMissing('roles');

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'tokenType' => 'Bearer',
            'user' => new UserResource($user),
        ], 201);
    }

    /**
     * POST /api/v1/auth/login
     */
    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        $identifier = trim($data['identifier']);
        $password = $data['password'];

        if (str_contains($identifier, '@')) {
            $user = User::query()
                ->whereRaw(
                    'LOWER(email) = ?',
                    [mb_strtolower($identifier)]
                )
                ->first();
        } else {
            $user = $this->findUserByPhone($identifier);
        }

        /*
         * Admin-created clients have a null password until registration.
         */
        if (
            ! $user ||
            empty($user->password) ||
            ! Hash::check($password, $user->password)
        ) {
            return response()->json([
                'message' => 'Invalid credentials',
                'code' => 'INVALID_CREDENTIALS',
            ], 401);
        }

        $user->loadMissing('roles');

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'tokenType' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request)
    {
        $user = $request->user()->loadMissing('roles');

        return response()->json([
            'user' => new UserResource($user),
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    /**
     * Find a user by phone while ignoring formatting characters.
     */
    private function findUserByPhone(string $phone): ?User
    {
        $raw = trim($phone);
        $digits = $this->phoneDigits($raw);

        return User::query()
            ->where(function (Builder $query) use ($raw, $digits) {
                $query->where('phone', $raw);

                if ($digits !== '') {
                    $query
                        ->orWhere('phone', $digits)
                        ->orWhere('phone', '+' . $digits)
                        ->orWhereRaw(
                            "REGEXP_REPLACE(phone, '[^0-9]', '') = ?",
                            [$digits]
                        );
                }
            })
            ->first();
    }

    /**
     * Remove every non-numeric character from a phone number.
     */
    private function phoneDigits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
