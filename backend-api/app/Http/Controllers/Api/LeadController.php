<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Social Media Marketing & Meta Ads Automation Expansion — Final Phase.
 *
 * Instant Lead CRM — read-only. NOT part of the literal Phase 1-4 spec
 * text; this final phase's production-polish audit item 3 explicitly
 * lists "/social/leads" as a page social_marketer must be able to reach,
 * but no backend route or frontend page for it existed anywhere before
 * this (verified: grep across app/Http/Controllers for any Lead-related
 * controller found nothing; Phase 2's Lead rows were only ever readable
 * indirectly, as synthetic 'lead:' threads inside SocialInboxController).
 * This surfaces the SAME Lead rows directly, as their own dedicated list/
 * detail view, gated on the same manage-social-leads permission tier as
 * the Inbox.
 *
 * Deliberately read-only (no store/update/destroy): a Lead Ad submission
 * is an immutable record of what Meta sent this app, and every mutable
 * part of its lifecycle (tenant_notified_at/lead_welcomed_at and their
 * error columns) is already written exclusively by
 * MetaLeadWebhookHandler at ingestion time — there is no legitimate
 * reason for a tenant to edit a lead row by hand, and adding write
 * endpoints here was not asked for.
 */
class LeadController extends Controller
{
    use ResolvesTenantAccount;

    private const PER_PAGE_MAX = 100;

    /**
     * GET /api/social/leads — paginated, newest first. ?search matches
     * lead_name/lead_phone/lead_email (mirrors AccountController::index()'s
     * search convention).
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $perPage = min((int) $request->integer('per_page', 20), self::PER_PAGE_MAX);

        $leads = Lead::query()
            ->forAccount($account->id)
            ->with('socialAccount:id,asset_type,provider_id')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();
                $query->where(function ($q) use ($search) {
                    $q->where('lead_name', 'like', "%{$search}%")
                        ->orWhere('lead_phone', 'like', "%{$search}%")
                        ->orWhere('lead_email', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($perPage);

        $leads->getCollection()->transform(fn (Lead $lead) => $this->present($lead));

        return response()->json($leads);
    }

    /** GET /api/social/leads/{id} — adds the full raw_field_data payload. */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $lead = Lead::query()->forAccount($account->id)->with('socialAccount:id,asset_type,provider_id')->findOrFail($id);

        return response()->json([
            ...$this->present($lead),
            'raw_field_data' => $lead->raw_field_data,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'platform' => $lead->socialAccount?->asset_type === 'instagram' ? 'instagram' : 'facebook',
            'form_id' => $lead->form_id,
            'ad_id' => $lead->ad_id,
            'lead_name' => $lead->lead_name,
            'lead_phone' => $lead->lead_phone,
            'lead_email' => $lead->lead_email,
            'tenant_notified_at' => $lead->tenant_notified_at?->toIso8601String(),
            'tenant_notify_error' => $lead->tenant_notify_error,
            'lead_welcomed_at' => $lead->lead_welcomed_at?->toIso8601String(),
            'lead_welcome_error' => $lead->lead_welcome_error,
            'created_at' => $lead->created_at?->toIso8601String(),
        ];
    }
}
