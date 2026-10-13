<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CrmGatewaySetting;
use App\Models\CrmReminderSetting;
use App\Services\Crm\Sales\CrmDocumentService;
use App\Services\Crm\Sales\CrmGatewayService;
use App\Services\Crm\Sales\CrmReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM Sales Phases 2, 3, 5 — the tenant's sales settings:
 *   GET|PUT /api/crm/settings/profile    seller details (manage-crm-documents)
 *   GET|PUT /api/crm/settings/gateway    the tenant's own Razorpay / Stripe (manage-crm-payments);
 *                                        secrets are write-only: never returned, blank keeps them
 *   GET|PUT /api/crm/settings/reminders  payment-reminder rules (manage-crm-payments)
 */
class CrmSalesSettingsController extends Controller
{
    use ResolvesTenantAccount;

    public function profile(Request $request, CrmDocumentService $documents): JsonResponse
    {
        $account = $this->requireAccount($request);

        return response()->json(['data' => $this->profileData($documents->profileFor($account), $account->company_name)]);
    }

    public function saveProfile(Request $request, CrmDocumentService $documents): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate([
            'legal_name' => ['nullable', 'string', 'max:191'],
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Za-z0-9]{13}$/'],
            'state' => ['nullable', 'string', 'max:100'],
            'state_code' => ['nullable', 'string', 'regex:/^[0-9]{2}$/'],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'address_line2' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:100'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:191'],
            'bank_details' => ['nullable', 'string', 'max:1000'],
            'upi_id' => ['nullable', 'string', 'max:100'],
            'default_terms' => ['nullable', 'string', 'max:2000'],
            'round_off' => ['sometimes', 'boolean'],
            'default_due_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ]);
        if (! empty($data['gstin'])) {
            $data['gstin'] = strtoupper($data['gstin']);
            $data['state_code'] ??= substr($data['gstin'], 0, 2);
        }

        $profile = $documents->profileFor($account);
        $profile->fill($data)->save();

        return response()->json(['message' => 'Business details saved.', 'data' => $this->profileData($profile->fresh(), $account->company_name)]);
    }

    public function gateway(Request $request, CrmGatewayService $gateways): JsonResponse
    {
        $account = $this->requireAccount($request);

        return response()->json(['data' => $this->gatewayData($gateways->settingsFor($account))]);
    }

    public function saveGateway(Request $request, CrmGatewayService $gateways): JsonResponse
    {
        $account = $this->requireAccount($request);
        $settings = $gateways->saveSettings($account, $request->validate([
            'gateway' => ['required', Rule::in(CrmGatewaySetting::GATEWAYS)],
            'mode' => ['required', Rule::in(['test', 'live'])],
            'key_id' => ['nullable', 'string', 'max:191'],
            'key_secret' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['required', 'boolean'],
        ]));

        return response()->json(['message' => 'Payment gateway saved.', 'data' => $this->gatewayData($settings)]);
    }

    public function reminders(Request $request, CrmReminderService $reminders): JsonResponse
    {
        $account = $this->requireAccount($request);

        return response()->json(['data' => $this->reminderData($reminders->settingsFor($account->id))]);
    }

    public function saveReminders(Request $request, CrmReminderService $reminders): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'days_before' => ['present', 'array', 'max:5'],
            'days_before.*' => ['integer', 'min:1', 'max:60', 'distinct'],
            'on_due_date' => ['required', 'boolean'],
            'days_after' => ['present', 'array', 'max:6'],
            'days_after.*' => ['integer', 'min:1', 'max:120', 'distinct'],
            'channel' => ['required', Rule::in(CrmReminderSetting::CHANNELS)],
            'auto_send' => ['required', 'boolean'],
            'email_fallback' => ['required', 'boolean'],
            'quiet_start_hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'quiet_end_hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:5'],
        ]);
        sort($data['days_before']);
        sort($data['days_after']);

        $settings = $reminders->settingsFor($account->id);
        $settings->fill($data)->save();

        return response()->json(['message' => 'Reminder settings saved.', 'data' => $this->reminderData($settings->fresh())]);
    }

    /** @return array<string, mixed> */
    private function profileData($p, ?string $companyName): array
    {
        return [
            'legal_name' => $p->legal_name, 'default_legal_name' => $companyName, 'gstin' => $p->gstin, 'state' => $p->state,
            'state_code' => $p->stateCode(), 'address_line1' => $p->address_line1, 'address_line2' => $p->address_line2, 'city' => $p->city,
            'pincode' => $p->pincode, 'phone' => $p->phone, 'email' => $p->email, 'bank_details' => $p->bank_details, 'upi_id' => $p->upi_id,
            'default_terms' => $p->default_terms, 'round_off' => (bool) ($p->round_off ?? true), 'default_due_days' => (int) ($p->default_due_days ?? 15),
        ];
    }

    /** @return array<string, mixed> */
    private function gatewayData(CrmGatewaySetting $s): array
    {
        return [
            'gateway' => $s->gateway, 'mode' => $s->mode, 'key_id' => $s->key_id, 'is_enabled' => (bool) $s->is_enabled,
            'has_key_secret' => filled($s->key_secret), 'has_webhook_secret' => filled($s->webhook_secret),
            'webhook_url' => $s->webhook_token ? rtrim((string) config('app.url'), '/').'/api/webhooks/crm-payments/'.$s->webhook_token : null,
        ];
    }

    /** @return array<string, mixed> */
    private function reminderData(CrmReminderSetting $s): array
    {
        return [
            'enabled' => (bool) $s->enabled, 'days_before' => array_values((array) $s->days_before), 'on_due_date' => (bool) $s->on_due_date,
            'days_after' => array_values((array) $s->days_after), 'channel' => $s->channel, 'auto_send' => (bool) $s->auto_send,
            'email_fallback' => (bool) $s->email_fallback, 'quiet_start_hour' => $s->quiet_start_hour, 'quiet_end_hour' => $s->quiet_end_hour,
            'max_attempts' => (int) $s->max_attempts,
        ];
    }
}
