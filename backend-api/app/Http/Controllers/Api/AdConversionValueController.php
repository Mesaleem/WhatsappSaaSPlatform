<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AdAttribution;
use App\Models\CrmLead;
use App\Services\Ads\AdAttributionService;
use App\Services\Ads\ConversionValueException;
use App\Services\Social\Publishing\Exceptions\PublishingDenied;
use App\Services\Social\SocialTargetGate;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phase 10 Task 5 — the manual conversion-value path for a converted, ad-attributed CRM lead.
 *
 *   GET   /api/crm/leads/{id}/conversion-value
 *   PATCH /api/crm/leads/{id}/conversion-value   {"value": 1500.5|0|null, "currency": "INR"}
 *
 * Gates (all must pass): the CRM group's own (crm.target, module lead_crm,
 * permission manage-crm, capability crm, tenant isolation, subscription guard)
 * PLUS the route's Ads permission (launch-meta-ads | social_ads.view) and, in
 * here, the target account's Ads entitlement (SocialTargetGate: suspended,
 * subscription, `meta_ads` module, `ads` capability). A Super Admin must name the
 * client with ?account_id= — the CRM group's platform-account fallback is NOT used.
 *
 * The lead's origin (manual / meta_ad / ...) is never consulted for authorization
 * and a value never changes `crm_leads.source`. The value belongs to the lead's
 * credited attribution row (AdAttributionService::recordConversionValue()).
 */
class AdConversionValueController extends Controller
{
    use ResolvesTenantAccount;

    public function __construct(private readonly AdAttributionService $attribution)
    {
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->targetAccount($request, 'view a conversion value', true);
        $lead = $this->lead($account, $id);

        return response()->json(['data' => $this->present($lead, $this->credited($lead))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = $this->targetAccount($request, 'record a conversion value');
        $lead = $this->lead($account, $id);

        $input = $request->all();
        if (! array_key_exists('value', $input)) {
            throw ValidationException::withMessages(['value' => 'Send "value" — a number (0 is valid) or null to clear it.']);
        }

        $data = $request->validate([
            'value' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
        ]);

        $value = $data['value'] === null ? null : number_format((float) $data['value'], 2, '.', '');
        $currency = isset($data['currency']) ? strtoupper($data['currency']) : null;

        if ($value !== null && $currency === null) {
            throw ValidationException::withMessages(['currency' => 'A currency is required when a value is recorded.']);
        }

        try {
            $result = $this->attribution->recordConversionValue($lead, $value, $currency);
        } catch (ConversionValueException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->httpStatus);
        }

        return response()->json([
            'message' => $result['changed'] ? 'Conversion value saved.' : 'Conversion value unchanged.',
            'changed' => $result['changed'],
            'data' => $this->present($lead, $result['attribution']),
        ]);
    }

    private function targetAccount(Request $request, string $action, bool $read = false): Account
    {
        // A Super Admin must NAME the client; the CRM group's platform fallback never applies here.
        if ($request->user()?->isSuperAdmin() && ! is_numeric($request->query('account_id'))) {
            abort(422, 'Select a client/tenant account first (pass ?account_id=).');
        }

        $account = $this->requireTargetAccount($request);

        if ($denial = app(SocialTargetGate::class)->denialFor($account, 'meta_ads', 'ads', $action, [
            'MODULE_DISABLED' => 'Meta Ads is switched off for this account.',
            'CAPABILITY_NOT_ENTITLED' => 'Your current plan does not include Ads. Please upgrade your subscription to unlock it.',
        ], null, $read)) {
            throw new HttpResponseException(PublishingDenied::target($denial)->render());
        }

        return $account;
    }

    private function lead(Account $account, int $id): CrmLead
    {
        $lead = CrmLead::query()->forAccount($account->id)->find($id);
        abort_if(! $lead, 404, 'Lead not found.');

        return $lead;
    }

    private function credited(CrmLead $lead): ?AdAttribution
    {
        return AdAttribution::query()->forAccount((int) $lead->account_id)->where('crm_lead_id', $lead->id)
            ->whereNotNull('converted_at')->orderBy('referral_received_at')->orderBy('id')->first();
    }

    /** @return array<string, mixed> */
    private function present(CrmLead $lead, ?AdAttribution $credited): array
    {
        $attributed = $credited !== null || AdAttribution::query()->forAccount((int) $lead->account_id)->where('crm_lead_id', $lead->id)->exists();

        return [
            'lead_id' => $lead->id,
            'source' => $lead->source,
            'attributed' => $attributed,
            'converted' => $credited !== null,
            'attribution_id' => $credited?->id,
            'converted_at' => $credited?->converted_at?->toIso8601String(),
            // null = unknown; 0 is a recorded value.
            'conversion_value' => $credited?->conversion_value !== null ? (float) $credited->conversion_value : null,
            'conversion_currency' => $credited?->conversion_currency,
        ];
    }
}
