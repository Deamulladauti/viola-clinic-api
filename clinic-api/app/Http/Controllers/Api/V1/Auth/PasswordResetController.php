<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class PasswordResetController extends Controller
{
    /**
     * POST /api/v1/auth/forgot-password
     */
    public function forgot(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = strtolower(trim($request->input('email')));
        $ip    = $request->ip();

        // 1) Rate limiting: max 5 attempts per 10 minutes per (email+IP)
        $key = 'password-reset:' . sha1($ip . '|' . $email);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            Log::warning('password_reset.throttled', [
                'email' => $email,
                'ip'    => $ip,
            ]);

            return response()->json([
                'message' => 'Too many attempts. Please try again later.',
            ], 429);
        }

        // Each attempt counts; decay (in seconds) = 600 (10 minutes)
        RateLimiter::hit($key, 600);

        /** @var \App\Models\User|null $user */
        $user = User::where('email', $email)->first();

        // 2) Log unknown emails but don't reveal anything to client
        if (!$user) {
            Log::info('password_reset.request_unknown_email', [
                'email' => $email,
                'ip'    => $ip,
            ]);

            return response()->json([
                'message' => 'If that email exists, a reset link has been sent.',
            ]);
        }

        // 3) Create token using Laravel's Password Broker
        $token = Password::broker()->createToken($user);

        // 4) Build frontend reset URL
        $baseUrl = config('app.frontend_password_reset_url')
            ?? env('FRONTEND_PASSWORD_RESET_URL')
            ?? env('FRONTEND_URL', 'https://example.com');

        $resetUrl = rtrim($baseUrl, '/') .
            '/reset-password?token=' . urlencode($token) .
            '&email=' . urlencode($user->email);

        // 5) Send a simple email (works with MAIL_MAILER=log or SMTP)
        Mail::raw(
            "Hello {$user->name},\n\n" .
            "You requested to reset your password for your Viola Clinic account.\n\n" .
            "Click the link below to set a new password:\n{$resetUrl}\n\n" .
            "If you did not request this, you can safely ignore this email.\n",
            function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Reset your Viola Clinic password');
            }
        );

        // 6) Log success (no token in production logs)
        if (!app()->isProduction()) {
            Log::info('password_reset.email_sent', [
                'email' => $user->email,
                'ip'    => $ip,
                'reset_url' => $resetUrl, // helpful in dev
            ]);
        } else {
            Log::info('password_reset.email_sent', [
                'email' => $user->email,
                'ip'    => $ip,
            ]);
        }

        return response()->json([
            'message' => 'If that email exists, a reset link has been sent.',
        ]);
    }

    /**
     * POST /api/v1/auth/reset-password
     */
    public function reset(Request $request)
    {
        $request->validate([
            'token'                => ['required'],
            'email'                => ['required', 'email'],
            'password'             => ['required', 'confirmed', 'min:8'],
        ]);

        $email = strtolower(trim($request->input('email')));

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, $password) {
                $user->password = Hash::make($password);
                $user->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            Log::info('password_reset.success', [
                'email' => $email,
                'ip'    => $request->ip(),
            ]);

            return response()->json(['message' => 'Password has been reset']);
        }

        Log::warning('password_reset.failed', [
            'email'  => $email,
            'ip'     => $request->ip(),
            'status' => $status,
        ]);

        return response()->json([
            'message' => 'Invalid token or email',
            'status'  => $status,
        ], 422);
    }
}
