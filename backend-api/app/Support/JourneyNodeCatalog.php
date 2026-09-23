<?php

namespace App\Support;

/**
 * Phase 5 Task 7 — Journey Node Capability & Provider Entitlement
 * Foundation. The SERVER-SIDE statement of what each of the 27 palette
 * node types requires, in the two dimensions the Phase 1 architecture
 * already has:
 *
 *     PLAN -> ENTITLEMENT -> CAPABILITY -> PERMISSION -> USAGE/QUOTA
 *     Provider (qr | meta | none) — separate dimension
 *
 * WHY THIS FILE EXISTS AT ALL, given Task 6's "one canonical registry"
 * rule: the frontend registry cannot be the authorization source,
 * because the frontend is not a security boundary. Task 6 already
 * accepted one mirrored list (WhatsAppFlow::PALETTE_NODE_TYPES) under
 * the same reasoning. This adds the capability/provider columns to that
 * same mirror rather than starting a second one — and
 * JourneyNodeEntitlementTest reads the actual frontend registry file
 * and fails on ANY disagreement in either direction, so the mirror
 * cannot drift silently. The frontend remains the place a node is
 * DEFINED; this is the place it is ENFORCED.
 *
 * LEGACY TYPES ARE ABSENT ON PURPOSE. trigger/message/question/
 * condition/save_lead are the five types WhatsAppJourneyEngine has
 * shipped and executed since Module 5, on accounts that were never
 * required to hold an account_entitlements row for them. Adding a
 * capability requirement here would make an existing, working journey
 * un-saveable the moment its owner opened and re-saved it — a
 * regression, not an entitlement. They stay gated exactly as they are
 * today: module.guard:chatbot + the journey route permissions +
 * subscription.guard. See JourneyNodeAuthorizer.
 *
 * NO CREDENTIALS. This catalog contains capability slugs and provider
 * slugs only — no key, token, secret, gateway id or account identifier
 * of any kind, and nothing tenant-specific. It is a static product
 * fact, identical for every tenant.
 */
final class JourneyNodeCatalog
{
    /**
     * Provider slug for "this node needs no WhatsApp engine at all".
     * Matches the seeded `providers.slug` — the platform itself, not
     * "unknown" and not a placeholder.
     *
     * READ THIS BEFORE CHANGING A `providers` LIST. The slug means two
     * different things depending on which side of the check it sits on,
     * and conflating them is a real bug (it was one, mid-Task-7):
     *
     *   - In a node's `providers` list it is a REQUIREMENT of "none":
     *     the node is engine-independent, so it is available to every
     *     account, whatever engine that account runs — including an
     *     account with no engine at all.
     *   - As an ACCOUNT's resolved provider it means that account has no
     *     WhatsApp engine configured.
     *
     * So `providers: ['none']` does NOT mean "only accounts with no
     * engine". A node that genuinely needs WhatsApp lists 'qr' and/or
     * 'meta' and never 'none'.
     */
    public const PLATFORM_PROVIDER = 'none';

    /**
     * node type => ['capabilities' => string[], 'providers' => string[]].
     *
     * `capabilities` is an AND: every slug listed must be held.
     * `providers` is an OR: the account's engine must be one of them —
     * unless the list contains PLATFORM_PROVIDER, which waives the
     * engine requirement entirely (see that constant's docblock).
     * An empty `capabilities` means the node is pure control flow and
     * needs no entitlement of its own (it is still behind the Journey
     * route's module/permission/subscription guards).
     *
     * @var array<string, array{capabilities: array<int, string>, providers: array<int, string>}>
     */
    public const NODES = [
        // ---- Message (7) ----
        // An AI system prompt seeds the conversation; nothing is sent to
        // WhatsApp by the node itself, so it is a platform + 'ai' node.
        'prompt' => ['capabilities' => ['ai'], 'providers' => ['none']],
        // Plain and media sends: both engines genuinely do these today
        // (BaileysDriver and MetaCloudApiDriver are both wired for them).
        'text' => ['capabilities' => ['whatsapp_send'], 'providers' => ['qr', 'meta']],
        'image' => ['capabilities' => ['whatsapp_send'], 'providers' => ['qr', 'meta']],
        'video' => ['capabilities' => ['whatsapp_send'], 'providers' => ['qr', 'meta']],
        'document' => ['capabilities' => ['whatsapp_send'], 'providers' => ['qr', 'meta']],
        'audio' => ['capabilities' => ['whatsapp_send'], 'providers' => ['qr', 'meta']],
        'sticker' => ['capabilities' => ['whatsapp_send'], 'providers' => ['qr', 'meta']],

        // ---- Interactive (6) ----
        // WhatsApp interactive messages (list / quick-reply / CTA URL /
        // location & address requests) are Cloud API features. This
        // platform's Baileys driver implements none of them, so claiming
        // 'qr' here would be claiming provider support that does not
        // exist — exactly what §4 of this task forbids.
        'list' => ['capabilities' => ['whatsapp_send'], 'providers' => ['meta']],
        'external_url' => ['capabilities' => ['whatsapp_send'], 'providers' => ['meta']],
        'reply_button' => ['capabilities' => ['whatsapp_send'], 'providers' => ['meta']],
        // A static location message is an ordinary message type both
        // engines send — unlike the location REQUEST below, which is
        // interactive.
        'location' => ['capabilities' => ['whatsapp_send'], 'providers' => ['qr', 'meta']],
        'location_request' => ['capabilities' => ['whatsapp_send'], 'providers' => ['meta']],
        'address_request' => ['capabilities' => ['whatsapp_send'], 'providers' => ['meta']],

        // ---- Advanced (10) ----
        'flow' => ['capabilities' => ['whatsapp_send'], 'providers' => ['meta']],
        // An outbound HTTP call has no WhatsApp provider at all.
        'api' => ['capabilities' => ['external_api'], 'providers' => ['none']],
        // Payments run through PaymentGatewayFactory (Razorpay/Stripe),
        // which is provider-independent by construction — see
        // PaymentNodeConfig's docblock in the frontend registry.
        'payment' => ['capabilities' => ['payments'], 'providers' => ['none']],
        // Resolved against the existing Meta template system
        // (message_templates + TemplateMessageDispatcher's `!== 'meta'`
        // guard), so Meta-only is a statement of fact, not a tier rule.
        'template' => ['capabilities' => ['whatsapp_send'], 'providers' => ['meta']],
        // Pure control flow: branch identity only, nothing sent, nothing
        // called. No entitlement and no engine of its own.
        'conditional' => ['capabilities' => [], 'providers' => ['none']],
        // Commerce needs BOTH: the commerce entitlement AND the ability
        // to send a WhatsApp message to deliver it.
        'catalog' => ['capabilities' => ['whatsapp_send', 'commerce'], 'providers' => ['meta']],
        'product' => ['capabilities' => ['whatsapp_send', 'commerce'], 'providers' => ['meta']],
        // Agent and RAG reuse the existing 'ai' capability — see
        // Phase1FoundationSeeder::CAPABILITIES for why no separate slug
        // was invented.
        'agent' => ['capabilities' => ['ai'], 'providers' => ['none']],
        'rag' => ['capabilities' => ['ai'], 'providers' => ['none']],
        // Handing a conversation to a human is a CRM/inbox function.
        'human_intervention' => ['capabilities' => ['crm'], 'providers' => ['none']],

        // ---- Utility (4) ----
        'code' => ['capabilities' => ['custom_code'], 'providers' => ['none']],
        'email' => ['capabilities' => ['email'], 'providers' => ['none']],
        // Chaining into another journey is the Journey product itself.
        'journey' => ['capabilities' => ['journey_automation'], 'providers' => ['none']],
        // Waiting is control flow. The queue/delay worker that will
        // eventually run it is a later task's concern.
        'delay' => ['capabilities' => [], 'providers' => ['none']],
    ];

    /**
     * Requirements for a node type, or null for a type this catalog does
     * not govern — an unknown type (rejected 422 by
     * WhatsAppFlowController::validateFlow()) or one of the five legacy
     * executable types (grandfathered; see this class's docblock).
     *
     * @return array{capabilities: array<int, string>, providers: array<int, string>}|null
     */
    public static function requirementsFor(string $nodeType): ?array
    {
        return self::NODES[$nodeType] ?? null;
    }

    /** @return array<int, string> */
    public static function types(): array
    {
        return array_keys(self::NODES);
    }
}
