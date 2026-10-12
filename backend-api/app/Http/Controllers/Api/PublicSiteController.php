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

/**
 * Unauthenticated endpoints behind the public marketing website.
 * Nothing here exposes tenant data: site content is Super-Admin-authored
 * copy, plans go through PlanRepository::toPublicArray() (the same
 * customer-facing projection the in-app billing page uses), and the contact
 * form only writes.
 */
class PublicSiteController extends Controller
{
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
        $plans = $this->plans->purchasable()
            ->load('capabilities:id,slug,label')
            ->map(fn (Plan $plan) => $this->plans->toPublicArray($plan, PaymentGatewayController::TAX_RATE) + [
                'features' => $plan->capabilities->pluck('label')->filter()->values()->all(),
            ])
            ->values();

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

        return response()->json(['message' => 'Message received.'], 201);
    }

    /**
     * All sections: a saved section replaces its default as a whole, so the
     * editor always shows and saves the complete section it displays.
     *
     * @return array<string, mixed>
     */
    public static function content(): array
    {
        $saved = SiteContent::query()->whereIn('key', SiteContentDefaults::KEYS)->pluck('value', 'key');

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
