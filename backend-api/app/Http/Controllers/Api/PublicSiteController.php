<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Plan;
use App\Models\SiteContent;
use App\Services\Billing\PlanRepository;
use App\Support\SiteContentDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Unauthenticated endpoints behind the public marketing website.
 * Nothing here exposes tenant data: site content is Super-Admin-authored
 * copy, plans go through PlanRepository::toPublicArray() (the same
 * customer-facing projection the in-app billing page uses), and the contact
 * form only writes.
 */
class PublicSiteController extends Controller
{
    /** Cache keys (Super-Admin-authored, read on every website visit). */
    public const CONTENT_CACHE_KEY = 'public_site:content:v1';

    public const PLANS_CACHE_KEY = 'public_site:plans:v1';

    /** Saved content is forgotten the moment it changes; plans also expire after a minute (capability edits fire no Plan event). */
    private const PLANS_TTL_SECONDS = 60;

    /** Drops the cached website content and plans; called whenever either is edited. */
    public static function forgetCache(): void
    {
        Cache::forget(self::CONTENT_CACHE_KEY);
        Cache::forget(self::PLANS_CACHE_KEY);
    }

    public function __construct(private readonly PlanRepository $plans)
    {
    }

    /** GET /api/public/site — every content section, saved values over defaults. */
    public function site(): JsonResponse
    {
        return response()->json(['data' => self::content()]);
    }

    /** GET /api/public/plans — active plans with the features each includes. */
    public function plans(): JsonResponse
    {
        $plans = Cache::remember(self::PLANS_CACHE_KEY, self::PLANS_TTL_SECONDS, fn () => $this->plans->purchasable()
            ->load('capabilities:id,slug,label')
            ->map(fn (Plan $plan) => $this->plans->toPublicArray($plan, PaymentGatewayController::TAX_RATE) + [
                'features' => $plan->capabilities->pluck('label')->filter()->values()->all(),
            ])
            ->values()
            ->all());

        return response()->json([
            'data' => $plans,
            'tax_rate' => PaymentGatewayController::TAX_RATE,
        ]);
    }

    /** POST /api/public/contact — a contact-form message for the Super Admin's inbox. */
    public function contact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'company' => ['nullable', 'string', 'max:255'],
            'topic' => ['nullable', 'string', 'max:50'],
            'message' => ['required', 'string', 'max:5000'],
            // Honeypot: a hidden field real visitors never fill.
            'website' => ['prohibited'],
        ]);

        ContactMessage::create($data + ['ip' => $request->ip()]);

        $this->captureCrmLead($data, $request);

        return response()->json(['message' => 'Message received.'], 201);
    }

    /**
     * CRM Sales Phase 1 — a website enquiry with a phone number also becomes a lead in
     * the Super Admin's own CRM (the Platform account; this is WapHub's own website),
     * source `website`. Best effort: the visitor's message is already saved, so a CRM
     * problem is logged and never fails the form.
     *
     * @param array<string, mixed> $data
     */
    private function captureCrmLead(array $data, Request $request): void
    {
        if (blank($data['phone'] ?? null)) {
            return;
        }

        try {
            $platform = app(\App\Services\Crm\PlatformCrmAccount::class)->find();
            if (! $platform) {
                return;
            }

            app(\App\Services\Crm\CrmLeadService::class)->create($platform, [
                'phone_number' => (string) $data['phone'],
                'name' => $data['name'],
                'email' => $data['email'],
                'source' => \App\Models\CrmLead::SOURCE_WEBSITE,
                'company_name' => $data['company'] ?? null,
                'requirement' => $data['message'],
                'attribution' => array_filter([
                    'channel' => 'website_contact_form',
                    'topic' => $data['topic'] ?? null,
                    'referrer' => mb_substr((string) $request->headers->get('referer', ''), 0, 255) ?: null,
                ]),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('PublicSiteController: the website enquiry was saved but could not be added to the CRM.', [
                'exception' => class_basename($e),
            ]);
        }
    }

    /**
     * All sections: a saved section replaces its default as a whole, so the
     * editor always shows and saves the complete section it displays.
     *
     * @return array<string, mixed>
     */
    public static function content(): array
    {
        // Read on every website page and every signup; cached until the Super Admin edits it.
        $saved = Cache::rememberForever(self::CONTENT_CACHE_KEY, fn () => SiteContent::query()->whereIn('key', SiteContentDefaults::KEYS)->pluck('value', 'key')->all());

        $content = [];
        foreach (SiteContentDefaults::all() as $key => $default) {
            $content[$key] = $saved[$key] ?? $default;
        }

        return $content;
    }

    /** One brand setting (e.g. signup_enabled), saved value over default. */
    public static function brand(string $field, mixed $fallback = null): mixed
    {
        return self::content()['brand'][$field] ?? SiteContentDefaults::get('brand')[$field] ?? $fallback;
    }
}
