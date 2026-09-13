<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Jobs\CreateNativeWhatsAppGroupJob;
use App\Jobs\SyncNativeWhatsAppGroupParticipantsJob;
use App\Models\Account;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Group Messaging Phase 2, extended by the Native WhatsApp Group
 * Re-Architecture — Contact Group Management APIs.
 *
 * Gated the same way /alerts above is: `permission:send-messages` (the
 * same tenant users who can already send WhatsApp messages manage the
 * lists they send to) PLUS `module.guard:contact_groups` on the whole
 * route group (routes/api.php) — a paid addon, so even a user who
 * holds send-messages is blocked unless their tenant's allowed_modules
 * includes it. The module-guard 403 shape (success/error_code/message)
 * is handled centrally by EnsureModuleEnabledMiddleware's
 * CUSTOM_RESPONSES map, not duplicated here.
 *
 * Every action uses requireAccount() (422 if no tenant is resolvable),
 * not resolveAccount()'s nullable "Super Admin, no client selected"
 * mode — a contact group always belongs to exactly one tenant, and a
 * combined cross-tenant group listing was not requested.
 */
class ContactGroupController extends Controller
{
    use ResolvesTenantAccount;

    /** GET /api/groups — every contact group for the active account, with members_count. */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $groups = ContactGroup::query()
            ->where('account_id', $account->id)
            ->withCount('members')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $groups,
        ]);
    }

    /**
     * POST /api/groups/create.
     *
     * `internal_segment` (default, unchanged behavior): { "name": "Vendor Group A" }.
     *
     * Native WhatsApp Group Re-Architecture — `native_wa_group`:
     * { "name": "...", "group_type": "native_wa_group",
     *   "contacts": [{"phone_number", "name"?}, ...] }. `contacts` is
     * REQUIRED (min 1) for this type — Baileys' groupCreate() itself has
     * no concept of a zero-participant group, so there is no meaningful
     * "create empty, add contacts later" path the way internal_segment
     * has. Requires the account to actually be on the 'qr' engine with a
     * connected WhatsApp session — the official Meta Cloud API has no
     * group-messaging capability at all (see
     * NativeWhatsAppGroupService's class docblock), so this is a hard
     * external constraint, not a permission this app could grant.
     *
     * The ContactGroup row + its initial ContactGroupMember rows are
     * created synchronously, in one transaction, with sync_status
     * 'pending' — so the group is immediately visible in the UI (with a
     * "Pending" sync badge) even though the REAL WhatsApp group doesn't
     * exist yet. CreateNativeWhatsAppGroupJob (queued right after the
     * transaction commits, same ordering guarantee every other
     * queue-after-commit call site in this codebase already uses) does
     * the actual qr-engine-service call and resolves sync_status to
     * 'synced' (with wa_group_jid/invite_link) or 'failed'.
     *
     * is_default is never settable from here (always false — only
     * AccountController::store()'s auto-created row is ever a default
     * group), unchanged from before this re-architecture.
     */
    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'group_type' => ['sometimes', 'string', Rule::in(ContactGroup::GROUP_TYPES)],
            'contacts' => ['required_if:group_type,'.ContactGroup::GROUP_TYPE_NATIVE, 'array', 'min:1'],
            'contacts.*.phone_number' => ['required_with:contacts', 'string', 'max:32'],
            'contacts.*.name' => ['nullable', 'string', 'max:255'],
        ]);

        $groupType = $data['group_type'] ?? ContactGroup::GROUP_TYPE_INTERNAL;

        if ($groupType === ContactGroup::GROUP_TYPE_NATIVE) {
            return $this->storeNativeGroup($account, $data);
        }

        $group = ContactGroup::create([
            'account_id' => $account->id,
            'name' => $data['name'],
            'is_default' => false,
            'group_type' => ContactGroup::GROUP_TYPE_INTERNAL,
        ]);

        return response()->json([
            'success' => true,
            'data' => $group,
        ], 201);
    }

    private function storeNativeGroup(Account $account, array $data): JsonResponse
    {
        $engineType = $account->currentSubscription?->engine_type;

        if ($engineType !== 'qr') {
            return response()->json([
                'success' => false,
                'error_code' => 'NATIVE_GROUP_REQUIRES_QR_ENGINE',
                'message' => 'Native WhatsApp Groups require the QR (Baileys) engine. The official Meta Cloud API does not support WhatsApp group messaging.',
            ], 422);
        }

        if ($account->whatsAppSession?->status !== 'connected') {
            return response()->json([
                'success' => false,
                'error_code' => 'WHATSAPP_NOT_CONNECTED',
                'message' => 'Connect your WhatsApp device first — a live session is required to create a real WhatsApp group.',
            ], 422);
        }

        $group = DB::transaction(function () use ($account, $data) {
            $group = ContactGroup::create([
                'account_id' => $account->id,
                'name' => $data['name'],
                'is_default' => false,
                'group_type' => ContactGroup::GROUP_TYPE_NATIVE,
                'sync_status' => ContactGroup::SYNC_STATUS_PENDING,
            ]);

            $now = now();
            $rows = collect($data['contacts'])
                ->map(fn (array $c) => [
                    'group_id' => $group->id,
                    'phone_number' => PhoneNumberNormalizer::normalize($c['phone_number']),
                    'name' => $c['name'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all();

            ContactGroupMember::upsert($rows, ['group_id', 'phone_number'], ['name', 'updated_at']);

            return $group;
        });

        // Dispatched AFTER the transaction commits — same
        // queue-after-commit ordering every other job in this codebase
        // already follows (ProcessGroupDispatchJob, DispatchWebhookJob):
        // a worker can never pick this up before the pending row it acts
        // on is durably saved.
        CreateNativeWhatsAppGroupJob::dispatch($group->id);

        return response()->json([
            'success' => true,
            'data' => $group->fresh()->loadCount('members'),
        ], 201);
    }

    /**
     * POST /api/groups/add-contacts — { "group_id": X, "contacts": [{"phone_number", "name"?}] }.
     *
     * group_id is re-verified against the resolved tenant (never trusted
     * at face value) so one tenant can never add contacts into another
     * tenant's group by guessing/incrementing an id.
     *
     * Phone numbers are normalized with the SAME PhoneNumberNormalizer
     * this app already uses right before WhatsApp dispatch (see its own
     * docblock) before the duplicate check, so "+91 98765 43210" and
     * "919876543210" collide as the same member instead of creating two
     * — this is what actually makes "avoiding duplicates" work; comparing
     * the raw, unnormalized strings would not.
     *
     * Uses Model::upsert() against the unique(group_id, phone_number)
     * index this table's creating migration added: a duplicate
     * phone_number in the same group updates its name instead of
     * erroring or inserting a second row.
     *
     * Native WhatsApp Group Re-Architecture addition: for a synced
     * native group (isSyncedNativeGroup()), the same phone numbers are
     * also queued to be added to the REAL WhatsApp group
     * (SyncNativeWhatsAppGroupParticipantsJob) — see that job's docblock
     * for why this doesn't touch the group's own sync_status. A native
     * group that is still 'pending' or 'failed' only gets its local
     * shadow-copy rows updated here, same as before; there is no live
     * group yet to add participants to.
     */
    public function addContacts(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            'group_id' => ['required', 'integer'],
            'contacts' => ['required', 'array', 'min:1'],
            'contacts.*.phone_number' => ['required', 'string', 'max:32'],
            'contacts.*.name' => ['nullable', 'string', 'max:255'],
        ]);

        $group = ContactGroup::where('account_id', $account->id)->find($data['group_id']);
        abort_if(! $group, 404, 'Contact group not found.');

        $now = now();
        $rows = collect($data['contacts'])
            ->map(fn (array $c) => [
                'group_id' => $group->id,
                'phone_number' => PhoneNumberNormalizer::normalize($c['phone_number']),
                'name' => $c['name'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        ContactGroupMember::upsert($rows, ['group_id', 'phone_number'], ['name', 'updated_at']);

        if ($group->isSyncedNativeGroup()) {
            $phones = collect($data['contacts'])->pluck('phone_number')->all();
            SyncNativeWhatsAppGroupParticipantsJob::dispatch($group->id, $phones);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'group_id' => $group->id,
                'members_count' => $group->members()->count(),
            ],
        ]);
    }

    /**
     * DELETE /api/groups/{id} — blocked for the is_default group;
     * cascades to its members otherwise (see the creating migration's
     * cascadeOnDelete).
     *
     * [Disclosed limitation, native groups]: this only ever deletes this
     * app's own ContactGroup/ContactGroupMember rows. It deliberately
     * does NOT call Baileys' groupLeave() (or any other live action) on
     * a native group's real WhatsApp group — leaving a real group is a
     * one-way, member-visible action ("X left the group") a tenant may
     * not want taken automatically on their behalf, and Baileys has no
     * "delete this group for everyone" capability at all (WhatsApp
     * itself doesn't expose that to a non-owner-removal flow). Not
     * requested by the re-architecture's 3 numbered items either. A
     * tenant who deletes a native group's row here still has the real
     * WhatsApp group on their phone, unchanged.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        $group = ContactGroup::where('account_id', $account->id)->find($id);
        abort_if(! $group, 404, 'Contact group not found.');

        if ($group->is_default) {
            return response()->json([
                'success' => false,
                'error_code' => 'DEFAULT_GROUP_PROTECTED',
                'message' => 'The default contact group cannot be deleted.',
            ], 422);
        }

        $group->delete();

        return response()->json(['success' => true]);
    }
}
