<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PresentsCrmLeads;
use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignCrmLeadRequest;
use App\Http\Requests\ChangeCrmLeadStatusRequest;
use App\Http\Requests\StoreCrmLeadRequest;
use App\Http\Requests\UpdateCrmLeadRequest;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\CrmTag;
use App\Services\Crm\CrmLeadService;
use App\Services\Crm\CrmTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 6 — CRM, Task 2. The tenant-facing CRM lead API.
 *
 * NOT to be confused with LeadController, which is the read-only view
 * over the existing `leads` table (Meta Lead Ads / journey captures).
 * That controller, that table and that model are untouched by this
 * phase; the two surfaces coexist deliberately until a later task
 * decides how a captured Lead becomes a CrmLead.
 *
 * Same ResolvesTenantAccount / never-trust-a-client-supplied-account_id
 * security model as WhatsAppFlowController and ChatbotRuleController.
 * Business logic lives in CrmLeadService and ContactResolver; this class
 * resolves the tenant, delegates, and shapes the response.
 *
 * NOT-FOUND IS THE SAME FOR "DOES NOT EXIST" AND "BELONGS TO SOMEONE
 * ELSE". Every lookup is `CrmLead::forAccount($account->id)->find($id)`
 * followed by an identical 404, so a sequential scan of ids tells a
 * caller nothing about another tenant's data. This is the pattern
 * already used by WhatsAppFlowController and LeadController, not a new
 * one.
 *
 * Response shapes follow the sibling lead API (LeadController): index
 * returns the paginator as-is with a transformed collection, show
 * returns the presented row, and writes return the
 * {message, data} envelope WhatsAppFlowController uses.
 */
class CrmLeadController extends Controller
{
    use PresentsCrmLeads;
    use ResolvesTenantAccount;

    private const PER_PAGE_MAX = 100;

    public function __construct(
        private readonly CrmLeadService $leads,
        private readonly CrmTagService $tags,
    ) {
    }

    /**
     * GET /api/crm/leads — this tenant's leads, newest first.
     *
     * Optional filters: ?status=, ?source=, ?assigned_user_id= (or
     * ?assigned_user_id=none for unassigned), ?search= against the
     * contact's name/phone. Filters are validated against the same
     * constants the model enforces, so an unknown status is a 422 rather
     * than a silently empty list.
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        /*
         * Task 6 — the filter vocabulary and the filter itself now live
         * on CrmLead (filterRules() / scopeFilter() / scopeOrderedForList()),
         * shared with /api/crm/pipeline and /api/crm/contacts/{id}/leads
         * so the three surfaces cannot accept different values or order
         * results differently. Behaviour here is unchanged: same rules,
         * same predicates, newest-first — with `id DESC` added as a
         * tiebreak so a paginated list is stable when timestamps collide.
         */
        // Task 7 — tag_id / tag_ids[] join the shared vocabulary; the
        // account id makes a foreign tag id fail validation exactly like
        // a nonexistent one.
        $filters = $request->validate(CrmLead::filterRules($account->id), CrmLead::filterMessages());

        $perPage = min((int) $request->integer('per_page', 20), self::PER_PAGE_MAX);

        $leads = CrmLead::query()
            ->forAccount($account->id)
            ->with($this->crmLeadRelations())
            ->filter($filters)
            ->orderedForList()
            ->paginate($perPage);

        $leads->getCollection()->transform(fn (CrmLead $lead) => $this->presentCrmLead($lead));

        return response()->json($leads);
    }

    /**
     * POST /api/crm/leads — create a lead, reusing this account's
     * existing contact for the phone number when there is one.
     */
    public function store(StoreCrmLeadRequest $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        // Manual Add lead is always source=manual — overwritten, not
        // defaulted, so no payload can label it meta_ad/journey/api.
        $lead = $this->leads->create($account, ['source' => CrmLead::SOURCE_MANUAL] + $request->validated());

        return response()->json([
            'message' => 'Lead created.',
            'data' => $this->presentCrmLead($lead->load($this->crmLeadRelations())),
        ], 201);
    }

    /** GET /api/crm/leads/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        return response()->json(['data' => $this->presentCrmLead($this->findOrFail($account->id, $id))]);
    }

    /** PUT|PATCH /api/crm/leads/{id} — status, assignment, outcome reason, contact name. */
    public function update(UpdateCrmLeadRequest $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        $lead = $this->leads->update(
            $this->findOrFail($account->id, $id),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Lead updated.',
            'data' => $this->presentCrmLead($lead->load($this->crmLeadRelations())),
        ]);
    }

    /**
     * PATCH /api/crm/leads/{id}/status — Task 5, the explicit lifecycle
     * action.
     *
     *   {"status": "contacted"}
     *   {"status": "not_converted", "not_converted_reason": "Budget."}
     *   {}                          422 — this endpoint exists to move
     *                               the lifecycle, so an omitted status
     *                               is a mistake, not a no-op
     *
     * The general PATCH /api/crm/leads/{id} still accepts `status` and
     * is unchanged for existing clients; both routes call
     * CrmLeadService::changeStatus()/applyStatus(), so neither can
     * acquire transition, validation or audit behaviour the other
     * lacks. This one is the preferred, explicit API.
     *
     * TRANSITIONS are checked against CrmLead::STATUS_TRANSITIONS. Every
     * move between the four canonical statuses is currently permitted —
     * a mistaken outcome must be correctable — but the check is real and
     * identical on both routes, so restricting the matrix later
     * restricts both at once.
     *
     * ONLY THE LIFECYCLE MOVES. status and the outcome columns that must
     * agree with it change; the tenant, contact, capture origin, source
     * and owner do not. Setting the status to its current value writes
     * nothing and audits nothing.
     *
     * TENANT SAFETY: the lead is resolved through forAccount(), so a
     * foreign id is the same 404 as a missing one and no account_id from
     * the request is ever read.
     *
     * AUDIT: the service writes through the model, so LogsActivity's
     * `updated` hook records the actor, the timestamp and the old/new
     * status.
     */
    public function status(ChangeCrmLeadStatusRequest $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validated();

        $lead = $this->leads->changeStatus(
            $this->findOrFail($account->id, $id),
            (string) $data['status'],
            $data['not_converted_reason'] ?? null,
        );

        return response()->json([
            'message' => 'Lead status updated.',
            'data' => $this->presentCrmLead($lead->load($this->crmLeadRelations())),
        ]);
    }

    /**
     * PATCH /api/crm/leads/{id}/assignee — Task 4, the explicit
     * ownership action. Assign, reassign and unassign are one endpoint
     * because they are one operation with a different argument:
     *
     *   {"assigned_user_id": 123}   assign / reassign
     *   {"assigned_user_id": null}  unassign
     *   {}                          422 — this endpoint exists to
     *                               change ownership, so an omitted
     *                               field is a mistake, not a no-op
     *
     * The general PATCH /api/crm/leads/{id} still accepts
     * assigned_user_id and is unchanged for existing clients; both
     * routes call CrmLeadService::changeAssignee(), so neither can
     * acquire authorization, validation or audit behaviour the other
     * lacks. This one is the preferred, explicit API.
     *
     * TENANT SAFETY: the lead is resolved through forAccount() — a
     * foreign id is the same 404 as a missing one — and the assignee is
     * checked against that lead's own account by CrmLead's saving
     * guard, with one identical message for foreign, missing, inactive
     * and non-CRM-capable targets so it cannot be used to enumerate
     * another account's users. No account_id is read from the request.
     *
     * AUDIT: the service writes through the model, so LogsActivity's
     * `updated` hook records the actor, the timestamp and the old/new
     * assigned_user_id. Reassigning to the current owner changes
     * nothing, fires no event, and writes no phantom history.
     */
    public function assignee(AssignCrmLeadRequest $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        $lead = $this->leads->changeAssignee(
            $this->findOrFail($account->id, $id),
            $request->validated()['assigned_user_id'] === null
                ? null
                : (int) $request->validated()['assigned_user_id'],
        );

        return response()->json([
            'message' => $lead->assigned_user_id === null ? 'Lead unassigned.' : 'Lead assigned.',
            'data' => $this->presentCrmLead($lead->load($this->crmLeadRelations())),
        ]);
    }

    /**
     * PATCH /api/crm/leads/{id}/contact — Task 3, explicit Contact
     * reassignment.
     *
     * WHY THIS IS ITS OWN ENDPOINT rather than a field on update():
     * UpdateCrmLeadRequest deliberately omits contact_id (and account_id)
     * so a general edit can never re-point a lead at another person by
     * accident, or move it between tenants. Reassignment is a distinct,
     * deliberate act, so it gets a distinct, deliberate route — the same
     * action-endpoint style this codebase already uses for
     * /groups/{id}/recreate, /whatsapp/flows/{id}/toggle and
     * /contacts/{source}/merge/{target}.
     *
     * WHAT IT CHANGES: contact_id. Nothing else. Not the account, not
     * the capture origin, not status, source, assignment or the terminal
     * timestamps — so a reassigned lead keeps its whole history and its
     * capture row stays attached through capture_lead_id.
     *
     * TENANT SAFETY, three layers deep: the lead is found through
     * forAccount(), the target contact is found through forAccount(),
     * and CrmLead's saving guard re-checks the pair. On MySQL/MariaDB
     * the composite (contact_id, account_id) foreign key makes a
     * cross-tenant result unstorable even if all three were bypassed.
     * A contact in another account produces the same "Contact not
     * found." 404 as one that does not exist, so this cannot be used to
     * probe another tenant's ids.
     *
     * AUDIT: the write goes through the model, so LogsActivity's
     * `updated` hook records actor, timestamp, and old/new contact_id
     * automatically. No second audit path was introduced.
     */
    public function reassignContact(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            'contact_id' => ['required', 'integer'],
        ]);

        $lead = $this->findOrFail($account->id, $id);

        $contact = Contact::query()->forAccount($account->id)->find($data['contact_id']);
        abort_if(! $contact, 404, 'Contact not found.');

        $lead = $this->leads->reassignContact($lead, $contact);

        return response()->json([
            'message' => 'Lead reassigned.',
            'data' => $this->presentCrmLead($lead->load($this->crmLeadRelations())),
        ]);
    }

    /**
     * DELETE /api/crm/leads/{id} — Phase 6 Hardening, Issue 9.
     *
     * HARD DELETE, because that is this project's convention: SoftDeletes
     * appears in zero models and zero migrations here, and every other
     * destroy() in this codebase (WhatsAppFlowController,
     * ContactGroupController, TeamController) deletes outright. Adding a
     * soft-delete concept for one endpoint would be a new project-wide
     * convention invented for a single feature.
     *
     * THE CONTACT IS NEVER TOUCHED. The foreign key runs the other way —
     * crm_leads.contact_id references contacts, so deleting a lead
     * cannot reach the person, and a contact with several opportunities
     * keeps the rest of them. Deleting the CONTACT is the operation that
     * is guarded, and it is guarded hard (see
     * CrmContactController::destroy).
     *
     * A capture row linked to this lead survives too: leads.crm_lead_id
     * is ON DELETE SET NULL, so the Meta/Journey record keeps its
     * history and simply becomes unlinked again.
     *
     * Audit: LogsActivity is on CrmLead and hooks Eloquent's `deleted`
     * event, so this writes an activity_logs row for the acting user
     * automatically — the project's standard mechanism, used rather
     * than a bespoke log line.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        $this->findOrFail($account->id, $id)->delete();

        return response()->json(['message' => 'Lead deleted.']);
    }

    /**
     * POST /api/crm/leads/{id}/tags/{tag} — Task 7, attach one tag.
     *
     * IDEMPOTENT: attaching a tag the lead already carries is a 200 no-op
     * (message "Tag already attached.", nothing written, nothing
     * audited). Never detaches any other tag. Never writes to crm_leads:
     * status, lifecycle timestamps, contact, owner and updated_at are
     * unchanged.
     *
     * TENANT SAFETY: both the lead and the tag are resolved through
     * forAccount(), so a foreign or missing lead is "Lead not found." and
     * a foreign or missing tag is "Tag not found." — identical 404s, no
     * enumeration channel. CrmTagService, the CrmLeadTag model guard and
     * the composite foreign keys re-check the pair below that.
     */
    public function attachTag(Request $request, int $id, int $tag): JsonResponse
    {
        $account = $this->requireAccount($request);

        $lead = $this->findOrFail($account->id, $id);
        $crmTag = $this->findTagOrFail($account->id, $tag);

        $attached = $this->tags->attach($lead, $crmTag);

        return response()->json([
            'message' => $attached ? 'Tag attached.' : 'Tag already attached.',
            'data' => $this->presentCrmLead($lead->load($this->crmLeadRelations())),
        ]);
    }

    /**
     * DELETE /api/crm/leads/{id}/tags/{tag} — Task 7, detach one tag.
     * Idempotent in the same way: a tag the lead does not carry is a 200
     * no-op ("Tag was not attached."). Same 404 rules as attachTag().
     */
    public function detachTag(Request $request, int $id, int $tag): JsonResponse
    {
        $account = $this->requireAccount($request);

        $lead = $this->findOrFail($account->id, $id);
        $crmTag = $this->findTagOrFail($account->id, $tag);

        $detached = $this->tags->detach($lead, $crmTag);

        return response()->json([
            'message' => $detached ? 'Tag detached.' : 'Tag was not attached.',
            'data' => $this->presentCrmLead($lead->load($this->crmLeadRelations())),
        ]);
    }

    private function findTagOrFail(int $accountId, int $id): CrmTag
    {
        $tag = CrmTag::query()->forAccount($accountId)->find($id);

        abort_if(! $tag, 404, 'Tag not found.');

        return $tag;
    }

    /**
     * The single lookup every action uses. Scoped to the account first,
     * so a foreign id and a nonexistent id produce byte-identical 404s.
     */
    private function findOrFail(int $accountId, int $id): CrmLead
    {
        $lead = CrmLead::query()
            ->forAccount($accountId)
            ->with($this->crmLeadRelations())
            ->find($id);

        abort_if(! $lead, 404, 'Lead not found.');

        return $lead;
    }

}
