<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\AccountMailer;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * Self-service "Forgot password". Tokens come from Laravel's password broker
 * (hashed in password_reset_tokens, expiring after auth.passwords.users.expire
 * minutes, default 60). Emails go through the platform's Mail Settings
 * (AccountMailer).
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly AccountMailer $mailer)
    {
    }

    /**
     * POST /api/auth/forgot-password — always the same answer, so the form can
     * never be used to find out which emails have an account.
     */
    public function sendLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $user = User::where('email', $data['email'])->first();

        if ($user && $user->is_active) {
            $token = Password::broker()->createToken($user);
            $this->email($user, $token);
        }

        return response()->json([
            'message' => 'If an account exists for that email, we have sent a link to reset the password.',
        ]);
    }

    /** POST /api/auth/reset-password — set a new password with the emailed token. */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::broker()->reset($data, function (User $user, string $password) use ($request) {
            $user->forceFill(['password' => $password])->save();
            // Sign out every existing session: whoever had the old password loses access.
            $user->tokens()->delete();
            event(new PasswordReset($user));
            AuthController::sendPasswordChangedEmail($user, $request->ip());
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This reset link is invalid or has expired. Please request a new one.',
                'errors' => ['token' => ['This reset link is invalid or has expired. Please request a new one.']],
            ], 422);
        }

        return response()->json(['message' => 'Your password has been changed. You can log in now.']);
    }

    private function email(User $user, string $token): void
    {
        $link = $this->mailer->frontendUrl('/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]));
        $minutes = (int) config('auth.passwords.users.expire', 60);

        $this->mailer->send(
            $user->email,
            "Reset your {$this->mailer->brand()} password",
            'Reset your password',
            '<p>Hi '.e($user->name).',</p>'
            .'<p>We received a request to reset the password for your account. Click the button below to choose a new one.</p>'
            .'<p style="color:#64748b;font-size:13px">This link expires in '.$minutes.' minutes. If you did not ask for this, ignore this email — your password stays the same.</p>'
            .'<p style="color:#94a3b8;font-size:12px;word-break:break-all">'.e($link).'</p>',
            ['label' => 'Reset password', 'url' => $link],
        );
    }
}
