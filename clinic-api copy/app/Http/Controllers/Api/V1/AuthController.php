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
        $existing = $this->findUserByPhone($data['phone']);

        if ($existing) {
            /*
             * A password means the account has already completed registration.
             */
            if (! empty($existing->password)) {
                return response()->json([
                    'message' => 'An account with this phone already exists. Please log in or reset your password.',
                    'code' => 'PHONE_ALREADY_REGISTERED',
                ], 409);
            }

            /*
             * Upgrade the admin-created client into a registered account.
             */
            $existing->name = $data['name'];
            $existing->phone = $data['phone'];
            $existing->email = $data['email'] ?? $existing->email;
            $existing->password = $data['password'];
            $existing->save();

            $user = $existing;
        } else {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'password' => $data['password'],
            ]);
        }

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
