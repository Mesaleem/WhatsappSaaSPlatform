<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\PresentsCrmLeads;
use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCrmContactRequest;
use App\Http\Requests\UpdateCrmContactRequest;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Services\Crm\ContactService;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 6 — CRM Hardening, Issues 2 and 10. The tenant-facing Contacts
 * API, closing the "no contacts API" limitation Task 2 disclosed: until
 * now a contact could only be reached through one of its leads, and a
 * name could only be corrected as a side effect of editing a lead.
 *
 * Same security model, response shapes and 404 convention as
 * CrmLeadController — every lookup is account-scoped first and then
 * aborts with an identical "Contact not found.", so ids reveal nothing
 * about another tenant.
 */
class CrmContactController extends Controller
{
    use PresentsCrmLeads;
    use ResolvesTenantAccount;

    private const PER_PAGE_MAX = 100;

    public function __construct(private readonly ContactService $contacts)
    {
    }

    /**
     * GET /api/crm/contacts — this tenant's contacts, newest first.
     *
     * ?search= matches name or phone; ?phone_number= is an exact match
     * on the NORMALIZED form, so a caller can look someone up with
     * whatever formatting they have to hand; ?name= is a partial match.
     * Every filter is applied on top of forAccount(), so no query shape
     * can reach another tenant's rows.
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) $request->integer('per_page', 20), self::PER_PAGE_MAX);

        $contacts = Contact::query()
            ->forAccount($account->id)
            ->withCount('crmLeads')
            ->when(isset($filters['phone_number']), function ($q) use ($filters) {
                // Normalized, so "+91 98765 43210" finds "919876543210".
                $q->where('phone_number', PhoneNumberNormalizer::normalize($filters['phone_number']));
            })
            /*
             * Round 2, Limitation 4 — a PREFIX match, deliberately.
             * `name LIKE 'ada%'` is a range scan on the new
             * (account_id, name) index; `LIKE '%ada%'` is not, and no
             * B-tree can make it one. ?name= is the filter for "I know
             * how it starts"; ?search= below keeps full substring
             * semantics as the fallback, so nothing a user could do
             * before stopped working.
             */
            ->when(isset($filters['name']), fn ($q) => $q->where('name', 'like', $filters['name'].'%'))
            ->when(isset($filters['search']), function ($q) use ($filters) {
                $search = $filters['search'];
                /*
                 * Round 2, Limitation 4. A free-text box must keep
                 * substring semantics, so the leading wildcard stays —
                 * but the phone leg is normalized first, which turns the
                 * overwhelmingly common case (someone pasting a phone
                 * number in any format) into an exact hit on
                 * unique(account_id, phone_number) instead of a scan.
                 * The whole predicate is still bounded by forAccount(),
                 * which is the part an index can and does accelerate.
                 */
                $normalized = PhoneNumberNormalizer::normalize($search);

                $q->where(function ($inner) use ($search, $normalized) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");

                    if ($normalized !== '') {
                        $inner->orWhere('phone_number', $normalized)
                            ->orWhere('phone_number', 'like', "{$normalized}%");
                    } else {
                        $inner->orWhere('phone_number', 'like', "%{$search}%");
                    }
                });
            })
            ->latest()
            ->paginate($perPage);

        $contacts->getCollection()->transform(fn (Contact $contact) => $this->present($contact));

        return response()->json($contacts);
    }

    /**
     * POST /api/crm/contacts.
     *
     * 201 for a contact that did not exist, 200 for one that did — the
     * brief requires that a repeat phone number must not create a
     * duplicate and must return the existing contact instead, and the
     * status code is how a caller tells which happened.
     */
    public function store(StoreCrmContactRequest $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        ['contact' => $contact, 'created' => $created] = $this->contacts->create($account, $request->validated());

        return response()->json([
            'message' => $created ? 'Contact created.' : 'Contact already exists.',
            'data' => $this->present($contact->loadCount('crmLeads')),
        ], $created ? 201 : 200);
    }

    /** GET /api/crm/contacts/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        return response()->json(['data' => $this->present($this->findOrFail($account->id, $id))]);
    }

    /** PUT|PATCH /api/crm/contacts/{id} — explicit edit of name, email and phone number. */
    public function update(UpdateCrmContactRequest $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        $contact = $this->contacts->update(
            $this->findOrFail($account->id, $id),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Contact updated.',
            'data' => $this->present($contact->loadCount('crmLeads')),
        ]);
    }

    /**
     * DELETE /api/crm/contacts/{id} — Issue 10.
     *
     * REFUSED WHILE ANYTHING DEPENDS ON THIS PERSON. A contact is the
     * root of a customer's history: their CRM opportunities, their
     * broadcast-list memberships, and (through those opportunities) the
     * original Meta/Journey capture records. Deleting it would cascade
     * crm_leads away and null out group memberships' contact_id, quietly
     * destroying customer data on a single request. So this returns 409
     * and names what is in the way, and the operator removes the
     * dependants deliberately.
     *
     * NO SOFT DELETE was introduced. Verified again by inspection:
     * SoftDeletes appears in zero models and zero migrations in this
     * repository, so adding it for one table would be a new
     * project-wide convention invented for a single endpoint. A
     * dependency-free contact is hard-deleted, which is what every other
     * destroy() in this codebase does — and LogsActivity records it
     * automatically, which is the project's standard audit mechanism.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        $contact = $this->findOrFail($account->id, $id);

        $blocking = $contact->blockingDependencies();

        if ($blocking !== []) {
            return response()->json([
                'success' => false,
                'message' => 'This contact still has related records. Remove them before deleting the contact.',
                'error_code' => 'CONTACT_HAS_DEPENDENTS',
                'dependents' => $blocking,
            ], 409);
        }

        $contact->delete();

        return response()->json(['message' => 'Contact deleted.']);
    }

    /**
     * POST /api/crm/contacts/{source}/merge/{target} — Round 2,
     * Limitation 6.
     *
     * Both contacts are resolved through the same account-scoped lookup
     * every other action uses, so a caller can neither merge across
     * tenants nor learn whether an id in another account exists: a
     * foreign id is an ordinary 404, identical to a nonexistent one.
     *
     * The merge itself is transactional and lives in ContactService —
     * see that method for the field rules and for why nothing is
     * deleted except the now-unreferenced source contact.
     */
    public function merge(Request $request, int $source, int $target): JsonResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            // Acknowledges that the source contact's phone number is
            // discarded; the surviving contact keeps its own.
            'confirm_phone_discard' => ['sometimes', 'boolean'],
        ]);

        $merged = $this->contacts->merge(
            $this->findOrFail($account->id, $source),
            $this->findOrFail($account->id, $target),
            (bool) ($data['confirm_phone_discard'] ?? false),
        );

        return response()->json([
            'message' => 'Contacts merged.',
            'data' => $this->present($merged->loadCount('crmLeads')),
        ]);
    }

    /**
     * GET /api/crm/contacts/{id}/leads — Task 3, the Contact -> Leads
     * half of the relationship.
     *
     * RETURNS CRM LEADS, NOT CAPTURE ROWS. A capture row appears only as
     * the `capture_lead` identifier block on the opportunity it was
     * promoted into; raw `leads` records are never served here. Those
     * remain the read-only /api/social/leads surface, which is a
     * different domain with a different permission.
     *
     * Same presenter as /api/crm/leads (PresentsCrmLeads), same
     * paginator envelope, same ?status= / ?source= filters — so a
     * frontend can render this list with the component it already has.
     *
     * Scoped twice: the contact is resolved through forAccount() (a
     * foreign or nonexistent id is the same 404), and the lead query is
     * filtered by both contact_id AND account_id. The second is
     * redundant while the composite foreign key holds, and is kept
     * anyway so the isolation does not depend on a constraint SQLite
     * cannot express.
     */
    public function leads(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        $contact = $this->findOrFail($account->id, $id);

        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(CrmLead::STATUSES)],
            'source' => ['nullable', 'string', Rule::in(CrmLead::SOURCES)],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = min((int) $request->integer('per_page', 20), self::PER_PAGE_MAX);

        $leads = CrmLead::query()
            ->forAccount($account->id)
            ->where('contact_id', $contact->getKey())
            ->with($this->crmLeadRelations())
            // Task 6 — the shared filter/order scopes, so this column of
            // a contact's leads sorts and filters exactly like
            // /api/crm/leads and /api/crm/pipeline.
            ->filter($filters)
            ->orderedForList()
            ->paginate($perPage);

        $leads->getCollection()->transform(fn (CrmLead $lead) => $this->presentCrmLead($lead));

        return response()->json($leads);
    }

    private function findOrFail(int $accountId, int $id): Contact
    {
        $contact = Contact::query()->forAccount($accountId)->find($id);

        abort_if(! $contact, 404, 'Contact not found.');

        return $contact;
    }

    /**
     * account_id is deliberately not exposed — it is always the caller's
     * own, so printing it adds nothing.
     *
     * @return array<string, mixed>
     */
    private function present(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'name' => $contact->name,
            'phone_number' => $contact->phone_number,
            'email' => $contact->email,
            'crm_leads_count' => $contact->crm_leads_count,
            'created_at' => $contact->created_at?->toIso8601String(),
            'updated_at' => $contact->updated_at?->toIso8601String(),
        ];
    }
}
