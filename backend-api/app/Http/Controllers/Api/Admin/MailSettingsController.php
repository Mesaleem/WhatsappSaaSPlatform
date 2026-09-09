<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Dynamic System & Mail Configuration — Super Admin SMTP setup, lives
 * under the same "Admin Gateway Settings" screen and
 * permission:manage-billing-settings gate as GatewaySettingsController
 * (see that controller's docblock for why platform-level credential
 * management sits on its own permission rather than manage-accounts).
 *
 * The whole point of this controller is changing mail delivery at
 * runtime WITHOUT touching .env or config/mail.php: settings are
 * persisted to the mail_settings table, and sendTest() applies them via
 * Config::set('mail.mailers.<name>', ...) for exactly one outgoing
 * message rather than mutating the app's boot-time config permanently.
 */
class MailSettingsController extends Controller
{
    private const TEST_MAILER_NAME = 'dynamic_test';

    /** GET /api/admin/mail-settings — password is NEVER returned, only password_set. */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->present($this->current())]);
    }

    /**
     * PUT /api/admin/mail-settings — partial update; omitting `password`
     * leaves the previously saved one untouched (same "leave blank to
     * keep saved secret" contract as GatewaySettingsController::update()).
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mailer' => ['sometimes', Rule::in(['smtp'])],
            'host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'encryption' => ['sometimes', 'nullable', Rule::in(['tls', 'ssl'])],
            'from_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $settings = $this->current();
        $settings->fill($data);
        $settings->save();

        return response()->json([
            'message' => 'Mail settings saved.',
            'data' => $this->present($settings),
        ]);
    }

    /**
     * POST /api/admin/mail-settings/test — "Send Test Mail" button, sends
     * to the calling Super Admin's own address (never an arbitrary
     * address from the request — this is a config-verification tool, not
     * a general mail-send endpoint). Accepts the same optional fields as
     * update() and merges them ON TOP OF the saved settings, so a Super
     * Admin can test values they've typed but not saved yet — "verify
     * SMTP setup before saving", per spec.
     */
    public function sendTest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mailer' => ['sometimes', 'nullable', Rule::in(['smtp'])],
            'host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'encryption' => ['sometimes', 'nullable', Rule::in(['tls', 'ssl'])],
            'from_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $saved = $this->current();
        $merged = new MailSetting(array_merge($saved->only([
            'mailer', 'host', 'port', 'username', 'password', 'encryption', 'from_address', 'from_name',
        ]), $data));

        if (! $merged->host || ! $merged->port) {
            return response()->json([
                'message' => 'Enter at least the SMTP Host and Port before sending a test email.',
            ], 422);
        }

        Config::set('mail.mailers.'.self::TEST_MAILER_NAME, $merged->toMailerConfig());

        $to = $request->user()->email;
        $fromAddress = $merged->from_address ?: $to;
        $fromName = $merged->from_name ?: 'WhatsApp SaaS Platform';

        try {
            Mail::mailer(self::TEST_MAILER_NAME)->raw(
                "This is a test email from your WhatsApp SaaS Platform.\n\n".
                "If you're reading this, your SMTP configuration is working correctly.",
                function ($message) use ($to, $fromAddress, $fromName) {
                    $message->to($to)
                        ->from($fromAddress, $fromName)
                        ->subject('Test Email — SMTP Configuration Verified');
                }
            );
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Could not send the test email: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => "Test email sent to {$to}. Check your inbox (and spam folder).",
        ]);
    }

    private function current(): MailSetting
    {
        return MailSetting::currentCached() ?? new MailSetting();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MailSetting $settings): array
    {
        return [
            'mailer' => $settings->mailer ?? 'smtp',
            'host' => $settings->host,
            'port' => $settings->port,
            'username' => $settings->username,
            'password_set' => (bool) $settings->password,
            'encryption' => $settings->encryption,
            'from_address' => $settings->from_address,
            'from_name' => $settings->from_name,
            'is_configured' => $settings->exists ? $settings->isConfigured() : false,
        ];
    }
}
