<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\PresentsCrmLeads;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignCrmLeadRequest;
use App\Http\Requests\ChangeCrmLeadStatusRequest;
use App\Models\Account;
use App\Models\CrmLead;
use App\Models\CrmTag;
use App\Services\Crm\CrmLeadService;
use App\Services\Crm\CrmTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6 — CRM Hardening Round 2, Issue 1. The external Developer API's
 * CRM lead intake.
 *
 * ============================== API DOCS ==============================
 * POST /api/v1/crm/leads
 *
 * AUTH
 *   X-API-KEY: <client api key>        (or Authorization: Bearer <key>)
 *   The key identifies the tenant. There is no user on this request.
 *
 * REQUIRES
 *   The key's account must hold the `crm` capability (i.e. its plan must
 *   include CRM). Without it: 403 CAPABILITY_NOT_ENTITLED.
 *   Since Task 11 also: the `lead_crm` module (403 MODULE_DISABLED) and,
 *   for every write including this one, an active subscription
 *   (403 SUBSCRIPTION_EXPIRED) — the same gates as /api/crm/*.
 *
 * BODY
 *   phone_number          required, string, max 32. Normalized server
 *                         side with the platform's PhoneNumberNormalizer;
 *                         an existing contact with the same normalized
 *                         number is reused, never duplicated.
 *   name                  optional, string, max 255. Fills the contact's
 *                         name only when it is currently blank.
 *   email                 optional, email, max 255. Same fill-if-blank rule.
 *   status                optional, one of: new | contacted | converted |
 *                         not_converted. Defaults to `new`.
 *   not_converted_reason  optional, string, max 255.
 *
 * NOT ACCEPTED — sending these changes nothing:
 *   account_id   The tenant comes from the API key. Always.
 *   source       Forced to `api` server side; a caller cannot label its
 *                leads as meta_ad, journey or whatsapp and so cannot
 *                pollute source attribution.
 *   assigned_user_id  Not read on create. Since Task 11, assign with
 *                PATCH /api/v1/crm/leads/{id}/assignee (below).
 *
 * RESPONSES
 *   201 { success, message, data: { id, status, source, contact:{...},
 *         created_at } }
 *   401 invalid/missing/revoked/expired key — AuthenticateApiKey's
 *       existing contract, unchanged.
 *   403 CAPABILITY_NOT_ENTITLED — the plan does not include CRM.
 *   422 validation errors, standard { message, errors:{field:[...]} }.
 *   429 throttle:external-api, unchanged.
 *   Idempotency-Key is honoured exactly as on every other /v1 route.
 * ======================================================================
 *
 * ======================= Phase 6 Task 11 additions =====================
 * Same group, same auth (X-API-KEY), same gates as POST above plus
 * module.apikey:lead_crm and subscription.apikey (writes need an active
 * subscription; GET does not — the tenant UI's rule). {id}/{tag} are
 * numeric; anything else is 404. A lead or tag of another account is the
 * same 404 as a missing one ("Lead not found." / "Tag not found.").
 *
 *   GET    /api/v1/crm/leads/{id}
 *   PATCH  /api/v1/crm/leads/{id}/status     { status, not_converted_reason? }
 *   PATCH  /api/v1/crm/leads/{id}/assignee   { assigned_user_id: int|null }
 *   POST   /api/v1/crm/leads/{id}/tags/{tag} idempotent (200 no-op if attached)
 *   DELETE /api/v1/crm/leads/{id}/tags/{tag} idempotent (200 no-op if absent)
 *
 * Each returns 200 { success, message, data: <lead> } where <lead> is the
 * tenant API's own representation (PresentsCrmLeads). Status goes through
 * CrmLeadService::changeStatus() (transition matrix, outcome bookkeeping,
 * audit); assignment through changeAssignee() (same eligibility guard: the
 * target must be an active user of THIS account holding manage-crm — one
 * identical 422 otherwise); tags through CrmTagService. Only the listed
 * fields are read: account_id, source, contact_id, capture_lead_id or any
 * other key in a body is ignored.
 *
 * DELIBERATELY NOT EXPOSED: list/search, delete, general update, contact
 * reassignment, contacts, pipeline, tag CRUD, assignee listing and every
 * bulk operation. The tenant-authenticated /api/crm/* API keeps those.
 *
 * ORDINARY OUTBOUND MESSAGE ENDPOINTS ARE UNTOUCHED. Sending a message
 * through /api/v1/messages/* still creates no CRM lead — a CRM lead is
 * created only by this explicit intake operation.
 */
class CrmLeadController extends Controller
{
    use PresentsCrmLeads;

    public function __construct(
        private readonly CrmLeadService $leads,
        private readonly CrmTagService $tags,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $accountId = $request->attributes->get('api_account_id');

        // Defensive only — AuthenticateApiKey guarantees this for any
        // request that reaches this controller. Same shape
        // ExternalAlertController already uses.
        abort_if(! $accountId, 401, 'Unauthenticated.');

        $account = Account::find($accountId);
        abort_if(! $account, 401, 'Unauthenticated.');

        $data = $request->validate([
            'phone_number' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['nullable', 'string', \Illuminate\Validation\Rule::in(CrmLead::STATUSES)],
            'not_converted_reason' => ['nullable', 'string', 'max:255'],
        ]);

        /*
         * source is OVERWRITTEN, not defaulted. Building the service
         * payload from $data by key rather than merging means a body
         * containing "source": "meta_ad" cannot reach CrmLeadService at
         * all — there is no path by which a caller-supplied source
         * survives. Same for account_id and assigned_user_id, which are
         * not in the validated set and are therefore never read.
         */
        $lead = $this->leads->create($account, [
            'phone_number' => $data['phone_number'],
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'status' => $data['status'] ?? CrmLead::STATUS_NEW,
            'not_converted_reason' => $data['not_converted_reason'] ?? null,
            'source' => CrmLead::SOURCE_API,
        ]);

        $lead->load('contact');

        return response()->json([
            'success' => true,
            'message' => 'Lead created.',
            'data' => [
                'id' => $lead->id,
                'status' => $lead->status,
                'source' => $lead->source,
                'contact' => [
                    'id' => $lead->contact->id,
                    'name' => $lead->contact->name,
                    'phone_number' => $lead->contact->phone_number,
                    'email' => $lead->contact->email,
                ],
                'created_at' => $lead->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /** GET /api/v1/crm/leads/{id} — Task 11. */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->keyAccount($request);

        return $this->leadResponse('Lead retrieved.', $this->findOrFail($account->id, $id));
    }

    /** PATCH /api/v1/crm/leads/{id}/status — Task 11. Same service step as the tenant API. */
    public function status(ChangeCrmLeadStatusRequest $request, int $id): JsonResponse
    {
        $account = $this->keyAccount($request);
        $data = $request->validated();

        $lead = $this->leads->changeStatus(
            $this->findOrFail($account->id, $id),
            (string) $data['status'],
            $data['not_converted_reason'] ?? null,
        );

        return $this->leadResponse('Lead status updated.', $lead);
    }

    /** PATCH /api/v1/crm/leads/{id}/assignee — Task 11. null unassigns. */
    public function assignee(AssignCrmLeadRequest $request, int $id): JsonResponse
    {
        $account = $this->keyAccount($request);
        $assignee = $request->validated()['assigned_user_id'];

        $lead = $this->leads->changeAssignee(
            $this->findOrFail($account->id, $id),
            $assignee === null ? null : (int) $assignee,
        );

        return $this->leadResponse($lead->assigned_user_id === null ? 'Lead unassigned.' : 'Lead assigned.', $lead);
    }

    /** POST /api/v1/crm/leads/{id}/tags/{tag} — Task 11. Idempotent. */
    public function attachTag(Request $request, int $id, int $tag): JsonResponse
    {
        $account = $this->keyAccount($request);
        $lead = $this->findOrFail($account->id, $id);

        $attached = $this->tags->attach($lead, $this->findTagOrFail($account->id, $tag));

        return $this->leadResponse($attached ? 'Tag attached.' : 'Tag already attached.', $lead);
    }

    /** DELETE /api/v1/crm/leads/{id}/tags/{tag} — Task 11. Idempotent. */
    public function detachTag(Request $request, int $id, int $tag): JsonResponse
    {
        $account = $this->keyAccount($request);
        $lead = $this->findOrFail($account->id, $id);

        $detached = $this->tags->detach($lead, $this->findTagOrFail($account->id, $tag));

        return $this->leadResponse($detached ? 'Tag detached.' : 'Tag was not attached.', $lead);
    }

    /** The account the API key resolved to — never a request field. Fails closed. */
    private function keyAccount(Request $request): Account
    {
        $accountId = $request->attributes->get('api_account_id');
        abort_if(! $accountId, 401, 'Unauthenticated.');

        $account = Account::find($accountId);
        abort_if(! $account, 401, 'Unauthenticated.');

        return $account;
    }

    /** Account first, so a foreign id and a missing id are byte-identical 404s. */
    private function findOrFail(int $accountId, int $id): CrmLead
    {
        $lead = CrmLead::query()->forAccount($accountId)->with($this->crmLeadRelations())->find($id);
        abort_if(! $lead, 404, 'Lead not found.');

        return $lead;
    }

    private function findTagOrFail(int $accountId, int $id): CrmTag
    {
        $tag = CrmTag::query()->forAccount($accountId)->find($id);
        abort_if(! $tag, 404, 'Tag not found.');

        return $tag;
    }

    private function leadResponse(string $message, CrmLead $lead): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $this->presentCrmLead($lead->load($this->crmLeadRelations())),
        ]);
    }
}
