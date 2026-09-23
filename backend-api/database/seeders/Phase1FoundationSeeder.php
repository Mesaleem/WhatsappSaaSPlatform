<?php

namespace Database\Seeders;

use App\Models\Capability;
use App\Models\Plan;
use App\Models\Provider;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 1 Foundation — seeds the Capability/Provider/ProviderCapability/
 * Plan/PlanEntitlement tables. Idempotent (updateOrCreate throughout,
 * matching RolePermissionSeeder's own firstOrCreate convention) — safe
 * to re-run.
 *
 * Plan rows are value-identical to App\Support\PlanCatalog::PLANS,
 * which is NOT removed or modified by this seeder — the live checkout
 * flow keeps reading PlanCatalog unchanged in Phase 1. See the Phase 1
 * plan, Step 5, Task 4.
 */
class Phase1FoundationSeeder extends Seeder
{
    private const CAPABILITIES = [
        'whatsapp_send' => ['label' => 'WhatsApp Messaging', 'category' => 'whatsapp'],
        'whatsapp_groups' => ['label' => 'WhatsApp Groups', 'category' => 'whatsapp'],
        'crm' => ['label' => 'CRM', 'category' => 'growth'],
        'journey_automation' => ['label' => 'Journey Automation', 'category' => 'growth'],
        'ads' => ['label' => 'Ads', 'category' => 'growth'],
        'social' => ['label' => 'Social Media', 'category' => 'growth'],
        'ai' => ['label' => 'AI', 'category' => 'growth'],
        /*
         * Phase 5 Task 7 -- Journey Node Capability & Provider Entitlement
         * Foundation. Five capabilities Task 6 identified as having no
         * seeded equivalent. Named by PRODUCT DOMAIN, matching every slug
         * above ('crm', 'ads', 'social', 'ai', 'whatsapp_groups') -- NOT
         * namespaced by the surface that happens to use them first. A
         * capability is what a tenant is entitled to, not where it is
         * configured, so a future Chatbot or Public-API caller gates on
         * the same 'external_api' row rather than a parallel
         * 'journey_external_api'.
         *
         * NOT added, deliberately: Agent and RAG. Both are already
         * expressed by the existing 'ai' capability, which the Journey
         * registry's `agent`/`rag`/`prompt` nodes already declare. Adding
         * 'ai_agent'/'ai_rag' would be the duplicate capability this
         * architecture exists to prevent. If product later sells them
         * separately, that is a deliberate split, not a gap.
         */
        'commerce' => ['label' => 'Commerce Catalog', 'category' => 'growth'],
        'payments' => ['label' => 'Payments', 'category' => 'growth'],
        'external_api' => ['label' => 'External API Calls', 'category' => 'platform'],
        'custom_code' => ['label' => 'Custom Code', 'category' => 'platform'],
        'email' => ['label' => 'Email', 'category' => 'platform'],
    ];

    private const PROVIDERS = [
        'qr' => ['label' => 'WhatsApp QR (Baileys)', 'driver_class' => 'App\\Services\\WhatsApp\\BaileysDriver'],
        'meta' => ['label' => 'WhatsApp Meta Cloud API', 'driver_class' => 'App\\Services\\WhatsApp\\MetaCloudApiDriver'],
        'none' => ['label' => 'No WhatsApp Provider', 'driver_class' => null],
    ];

    /**
     * [provider_slug, capability_slug, supported, reason]. `reason`
     * states plainly whether a false is a genuine technical limitation
     * or a deliberate business/product-tier restriction — never left
     * implicit. See the Phase 1 plan, Step 6.
     */
    private const PROVIDER_CAPABILITIES = [
        ['qr', 'whatsapp_send', true, 'QR/Baileys sends text and template messages — existing, working, untouched.'],
        ['qr', 'whatsapp_groups', true, 'QR/Baileys group messaging is a real, maintained feature.'],
        /*
         * Phase 5 Task 9 follow-up — CORRECTED, by product-owner decision.
         *
         * This row was `false` ("QR is messaging-only"). The confirmed
         * plan matrix bundles crm into `growth`, which is a QR-engine
         * plan, so the two statements contradicted each other: the plan
         * sold CRM and grantPlanEntitlements() then refused it as
         * provider-incompatible, making that matrix cell inert.
         *
         * The contradiction is resolved in favour of the matrix, which is
         * authoritative: CRM is a platform/business capability with no
         * technical dependency on the WhatsApp engine at all (the Phase 1
         * plan says exactly that of the meta row -- "no technical
         * dependency on WhatsApp engine; CRM is engine-agnostic"), so
         * blocking it on the engine was a tier rule, not a fact. It is
         * the kind of rule this table exists to let the business change
         * by editing a row instead of shipping code.
         */
        ['qr', 'crm', true, 'CRM is engine-agnostic: it has no technical dependency on the WhatsApp provider, and the confirmed plan matrix bundles it into the QR-engine Growth plan.'],
        /*
         * Phase 7 Task 1.5 — OWNER DECISION (2026-09-24): Journey
         * automation is supported on QR. The journey engine sends through
         * the unified WhatsAppEngineFactory driver contract, so it runs on
         * either engine, and the Journey API is now gated on this
         * capability (capability.guard:journey_automation). With the old
         * `false` row every QR tenant — including Growth, whose plan sells
         * journey_automation — would have lost the Journey builder. Same
         * kind of change as qr -> crm above. Existing installs get this row
         * from migration 2026_09_24_110000; existing Growth accounts get
         * the entitlement from `php artisan entitlements:backfill-plan`.
         */
        ['qr', 'journey_automation', true, 'Journey automation is engine-agnostic: journeys send through the unified WhatsApp driver contract, and the confirmed plan matrix bundles it into the QR-engine Growth plan.'],
        ['qr', 'ads', false, 'Business/product-tier restriction: QR does not get Ads; CTWA ad-lead capture is also Meta-webhook-specific.'],
        ['meta', 'whatsapp_send', true, 'MetaCloudApiDriver — existing.'],
        ['meta', 'whatsapp_groups', false, 'Technical limitation: Meta Cloud API cannot do WhatsApp Groups.'],
        ['meta', 'crm', true, 'Meta-tier customers may be entitled to CRM.'],
        ['meta', 'journey_automation', true, 'Meta-tier customers may be entitled to Journey automation.'],
        ['meta', 'ads', true, 'CTWA ad-lead capture is Meta-webhook-native.'],
        ['none', 'social', true, 'Social/AI do not require any WhatsApp engine.'],
        ['none', 'ai', true, 'Social/AI do not require any WhatsApp engine.'],
        /*
         * Phase 5 Task 7. Every pairing below is STATED rather than left
         * to the "no row = no restriction" default, because a Journey
         * capability gate must fail safe: see
         * ProviderCapabilityService::supportsNativeWhatsAppGroups()'s
         * docblock for why an unstated pairing cannot be trusted by a
         * gate, only by a grant.
         */
        ['meta', 'commerce', true, 'WhatsApp Commerce catalog/product messages are Meta Cloud API features.'],
        ['qr', 'commerce', false, 'Technical limitation: this platform\'s Baileys driver implements no WhatsApp Commerce catalog/product message.'],
        ['none', 'commerce', false, 'Commerce messages are delivered over WhatsApp; an account with no WhatsApp provider cannot send one.'],
        ['none', 'payments', true, 'Payments run through PaymentGatewayFactory (Razorpay/Stripe), which is provider-independent -- no WhatsApp engine is required to create a payment request.'],
        ['none', 'external_api', true, 'An outbound HTTP call is a platform capability and needs no WhatsApp engine.'],
        ['none', 'custom_code', true, 'Server-side code execution is a platform capability and needs no WhatsApp engine.'],
        ['none', 'email', true, 'Email is delivered through the platform mail configuration (MailSetting), not a WhatsApp engine.'],
    ];

    /**
     * Phase 5 Task 9 — the CONFIRMED plan -> capability matrix, supplied
     * by the product owner. Not inferred, not expanded, not
     * reinterpreted: only cells marked `include` appear here.
     * `exclude` and `manual` cells are absent by construction — a
     * `manual` capability is still reachable through
     * AccountController::grantEntitlement(), it is simply not bundled.
     *
     * A PLAN ENTITLEMENT IS NOT PROVIDER SUPPORT. Listing a capability
     * here means the plan SELLS it; whether the account's engine can
     * actually run it stays a separate question answered by
     * provider_capabilities, and InvoiceCreditService::grantPlanEntitlements()
     * skips an incompatible pairing at grant time. growth -> crm and
     * growth -> journey_automation were once in exactly that position (a
     * QR-engine plan whose provider rows said `qr => false`); both rows
     * have since been changed by owner decision (crm in Phase 6,
     * journey_automation in Phase 7 Task 1.5), so Growth now receives
     * both. Any future such cell still shows in the Super Admin panel as
     * "In plan - provider unsupported" rather than being hidden.
     *
     * @var array<string, array<int, string>>
     */
    private const PLAN_CAPABILITIES = [
        'starter' => [
            'whatsapp_send',
            'whatsapp_groups',
            'social',
            'external_api',
        ],
        'growth' => [
            'whatsapp_send',
            'whatsapp_groups',
            'crm',
            'journey_automation',
            'social',
            'ai',
            'payments',
            'external_api',
            'email',
        ],
        'business' => [
            'whatsapp_send',
            'crm',
            'journey_automation',
            'ads',
            'social',
            'ai',
            'commerce',
            'payments',
            'external_api',
            'custom_code',
            'email',
        ],
    ];

    /**
     * Value-identical to App\Support\PlanCatalog::PLANS — including the
     * billing dimensions Phase 5 Task 11 promoted into the table, so the
     * cutover from PlanCatalog to `plans` is behaviour-preserving.
     */
    private const PLANS = [
        'starter' => ['label' => 'Starter', 'price' => 499.00, 'duration_days' => 30, 'description' => '500 messages/month over the QR (Baileys) engine.', 'capability' => 'whatsapp_send', 'usage_limit' => 500, 'engine_type' => 'qr', 'billing_model' => 'flat_quota', 'rate_per_message' => null, 'total_allocated_messages' => 500],
        'growth' => ['label' => 'Growth', 'price' => 1999.00, 'duration_days' => 30, 'description' => '2,500 messages/month over the QR (Baileys) engine.', 'capability' => 'whatsapp_send', 'usage_limit' => 2500, 'engine_type' => 'qr', 'billing_model' => 'flat_quota', 'rate_per_message' => null, 'total_allocated_messages' => 2500],
        'business' => ['label' => 'Business', 'price' => 7999.00, 'duration_days' => 30, 'description' => '10,000 messages/month over the official Meta Cloud API.', 'capability' => 'whatsapp_send', 'usage_limit' => 10000, 'engine_type' => 'meta', 'billing_model' => 'flat_quota', 'rate_per_message' => null, 'total_allocated_messages' => 10000],
    ];

    public function run(): void
    {
        $capabilities = collect(self::CAPABILITIES)->mapWithKeys(
            fn (array $attrs, string $slug) => [$slug => Capability::updateOrCreate(['slug' => $slug], $attrs)]
        );

        $providers = collect(self::PROVIDERS)->mapWithKeys(
            fn (array $attrs, string $slug) => [$slug => Provider::updateOrCreate(['slug' => $slug], $attrs)]
        );

        foreach (self::PROVIDER_CAPABILITIES as [$providerSlug, $capabilitySlug, $supported, $reason]) {
            $providers[$providerSlug]->capabilities()->syncWithoutDetaching([
                $capabilities[$capabilitySlug]->id => ['supported' => $supported, 'reason' => $reason],
            ]);

            /*
             * Phase 5 Task 9 follow-up — ProviderCapabilityService::supports()
             * memoizes each (provider, capability) pairing for an hour under
             * this exact key. Without this line, CHANGING a seeded boolean
             * (which is precisely what this follow-up does to qr/crm) would
             * keep being answered from cache for up to an hour after the
             * seeder ran, on any environment using a persistent cache store.
             * Re-seeding is the moment that cache is known to be wrong.
             */
            Cache::forget("provider_capability:{$providerSlug}:{$capabilitySlug}");
        }

        foreach (self::PLANS as $slug => $attrs) {
            /*
             * Phase 5 Task 11 — the seeder now writes the billing
             * dimensions too, because `plans` became the runtime source
             * of truth for checkout. Still value-identical to
             * PlanCatalog, so re-seeding an existing environment cannot
             * change any plan's price, engine or quota.
             *
             * is_active is NOT written here on purpose: it defaults true
             * on insert, and re-seeding must never silently re-activate
             * a plan a Super Admin deliberately retired.
             */
            $plan = Plan::updateOrCreate(['slug' => $slug], [
                'label' => $attrs['label'],
                'price' => $attrs['price'],
                'duration_days' => $attrs['duration_days'],
                'description' => $attrs['description'],
                'engine_type' => $attrs['engine_type'],
                'billing_model' => $attrs['billing_model'],
                'rate_per_message' => $attrs['rate_per_message'],
                'total_allocated_messages' => $attrs['total_allocated_messages'],
            ]);

            /*
             * The plan's message quota rides on its own capability row's
             * usage_limit, exactly as before — unchanged, and the reason
             * whatsapp_send is written separately from the bundle below.
             * Pricing, quota and engine_type are NOT touched by Task 9.
             */
            $plan->capabilities()->syncWithoutDetaching([
                $capabilities[$attrs['capability']]->id => ['usage_limit' => $attrs['usage_limit']],
            ]);

            /*
             * Phase 5 Task 9 — the confirmed bundle.
             * syncWithoutDetaching, never sync(): it adds what is missing
             * and leaves everything else alone, so re-running this seeder
             * cannot duplicate a row (unique(plan_id, capability_id) backs
             * that up) and cannot silently drop a capability some other
             * process attached. usage_limit is left NULL for these —
             * "unbounded / not applicable", per the plan_entitlements
             * migration — since the only metered capability today is
             * whatsapp_send, already written above.
             */
            $bundle = collect(self::PLAN_CAPABILITIES[$slug] ?? [])
                ->reject(fn (string $capabilitySlug) => $capabilitySlug === $attrs['capability'])
                ->mapWithKeys(fn (string $capabilitySlug) => [$capabilities[$capabilitySlug]->id => ['usage_limit' => null]])
                ->all();

            if ($bundle !== []) {
                $plan->capabilities()->syncWithoutDetaching($bundle);
            }
        }
    }
}
