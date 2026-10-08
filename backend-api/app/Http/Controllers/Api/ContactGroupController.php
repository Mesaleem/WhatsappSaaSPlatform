<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Jobs\CreateNativeWhatsAppGroupJob;
use App\Models\Account;
use App\Models\ScheduledMessage;
use App\Services\Scheduling\ScheduledMessageException;
use App\Services\Scheduling\ScheduledMessageService;
use App\Models\ContactGroup;
use App\Models\ContactGroupMember;
use App\Services\Crm\ContactGroupContactLinker;
use App\Services\Groups\GroupMessageDispatcher;
use App\Services\Groups\NativeGroupCreationService;
use App\Services\Groups\NativeGroupEntitlement;
use App\Services\Groups\NativeWhatsAppGroupService;
use App\Support\PhoneNumberNormalizer;
use App\Services\Access\ProviderCapabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Group Messaging Phase 2, extended by the Native WhatsApp Group
 * Re-Architecture — Contact Group Management APIs.
 *
 * [Re-scoped 2026-10-07, disclosed]: `internal_segment` ("Custom Contact
 * Groups" — a DB broadcast list; each member gets their own DM) is now a
 * free, always-on baseline feature for every account holding
 * `send-messages` — unlimited, no paid add-on, no module gate (see
 * Account::effectiveModules(), which now always includes
 * 'contact_groups'). A `native_wa_group` (a real WhatsApp group chat)
 * remains the chargeable, plan-limited one — gated by the `whatsapp_groups`
 * capability (NativeGroupEntitlement, on/off) AND, new in this pass, a
 * numeric count against the SAME paid add-on this whole route group used
 * to gate everything with (CustomGroupAccessService, now re-scoped to
 * count native groups instead of internal ones — see its own docblock).
 *
 * Every action uses requireAccount() (422 if no tenant is resolvable),
 * not resolveAccount()'s nullable "Super Admin, no client selected"
 * mode — a contact group always belongs to exactly one tenant, and a
 * combined cross-tenant group listing was not requested.
 */
class ContactGroupController extends Controller
{
    use ResolvesTenantAccount;

    /** The body addContacts()/removeContact() answer with for a Native WhatsApp Group. */
    private const NATIVE_MEMBERSHIP_DENIAL = [
        'success' => false,
        'error_code' => 'NATIVE_GROUP_MEMBERSHIP_VIA_WHATSAPP_ONLY',
        'message' => 'Add or remove members of a Native WhatsApp Group from WhatsApp itself, on your phone — this app only reflects the group, it cannot change who is in it.',
    ];

    /** GET /api/groups — every contact group for the active account, with members_count. */
    /**
     * POST /api/groups/keep  body: { keep_ids: [..] } — after a downgrade, the client chooses which
     * of its Native WhatsApp Groups stay open for the term. The others stay locked until the next
     * term starts. Internal segment groups are never locked (they are unlimited and free).
     */
    public function keep(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);
        $data = $request->validate(['keep_ids' => ['required', 'array'], 'keep_ids.*' => ['integer']]);

        try {
            app(\App\Services\Billing\CustomGroupAccessService::class)->keep($account, $data['keep_ids']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => 'invalid_selection'], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Your groups are set. The others stay locked until your next term starts.',
        ]);
    }

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
            // "usage" is about Native WhatsApp Groups only -- internal segment groups are unlimited,
            // so they have nothing to report here (see this controller's own re-scoping docblock).
            'usage' => app(\App\Services\Billing\CustomGroupAccessService::class)->usage($account)
                + ['selection_required' => app(\App\Services\Billing\CustomGroupAccessService::class)->selectionRequired($account)],
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
            // Required for a native group (Baileys' groupCreate() has no empty-group concept); optional for an
            // internal segment -- it can start empty and get members later via "Import Contacts"/add-contacts.
            'contacts' => ['required_if:group_type,'.ContactGroup::GROUP_TYPE_NATIVE, 'array', 'min:1'],
            'contacts.*.phone_number' => ['required_with:contacts', 'string', 'max:32'],
            'contacts.*.name' => ['nullable', 'string', 'max:255'],
            // Which linked number this group belongs to; omitted = the default number. Native groups still use the
            // account's one connected session (see storeNativeGroup()), so this applies to a Custom Contact Group only.
            'whatsapp_number_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $groupType = $data['group_type'] ?? ContactGroup::GROUP_TYPE_INTERNAL;

        if ($groupType === ContactGroup::GROUP_TYPE_NATIVE) {
            // Phase 5 P5-C — a Native WhatsApp Group needs the
            // `whatsapp_groups` capability (contact lists do not).
            if (! NativeGroupEntitlement::allows($account, 'groups.create_native', 'api', (bool) $request->attributes->get('is_super_admin'))) {
                return response()->json(NativeGroupEntitlement::denialBody(), 403);
            }

            // A paid Native WhatsApp Groups term allows its included number of groups (the offer's units).
            if (app(\App\Services\Billing\CustomGroupAccessService::class)->limitReached($account)) {
                $limit = app(\App\Services\Billing\ModuleAddonService::class)->activeUnitLimit($account, 'contact_groups');

                return response()->json([
                    'success' => false,
                    'message' => "Your plan includes {$limit} native WhatsApp group".($limit === 1 ? '' : 's').'. Renew or upgrade to add more.',
                    'error_code' => 'group_limit_reached',
                ], 422);
            }

            return $this->storeNativeGroup($account, $data);
        }

        // Internal segment groups are a free, always-on feature -- no limit, no paid term required.
        try {
            $numberId = app(\App\Services\WhatsApp\SenderNumberResolver::class)->resolve($account, $data['whatsapp_number_id'] ?? null);
        } catch (\App\Services\WhatsApp\SenderNumberException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
        }

        $group = ContactGroup::create([
            'account_id' => $account->id,
            'name' => $data['name'],
            'group_code' => ContactGroup::generateGroupCode($account->id, $data['name']),
            'whatsapp_number_id' => $numberId,
            'is_default' => false,
            'group_type' => ContactGroup::GROUP_TYPE_INTERNAL,
        ]);

        // Optional starting members -- a custom/internal group needs no minimum (unlike a native
        // group, which Baileys cannot create empty), but letting the caller seed it right away saves
        // a separate add-contacts round trip for the common "I already have the list" case.
        if (! empty($data['contacts'])) {
            $this->insertMembers($group, $data['contacts']);
        }

        return response()->json([
            'success' => true,
            'data' => $group->loadCount('members'),
        ], 201);
    }

    /**
     * @param  list<array{phone_number: string, name?: string|null}>  $contacts
     */
    private function insertMembers(ContactGroup $group, array $contacts): void
    {
        $now = now();
        $rows = collect($contacts)
            ->map(fn (array $c) => [
                'group_id' => $group->id,
                'account_id' => $group->account_id,
                'phone_number' => PhoneNumberNormalizer::normalize($c['phone_number']),
                'name' => $c['name'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        ContactGroupMember::upsert($rows, ['group_id', 'phone_number'], ['name', 'updated_at']);

        // Phase 6 CRM Hardening (Issue 8) -- same reconciliation addContacts() already does for a
        // later add; a group created with its starting members gets the exact same treatment.
        app(ContactGroupContactLinker::class)->linkGroupQuietly($group);
    }

    private function storeNativeGroup(Account $account, array $data): JsonResponse
    {
        $engineType = $account->currentSubscription?->engine_type;

        // Phase 5 Task 2 -- provider_capabilities is authoritative for
        // this rule when it states it; the 'qr' literal now lives only
        // in ProviderCapabilityService::supportsNativeWhatsAppGroups().
        if (! app(ProviderCapabilityService::class)->supportsNativeWhatsAppGroups($engineType)) {
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

        // Developer API Platform for WhatsApp Group Creation & Unified
        // Messaging — extracted to NativeGroupCreationService so this
        // exact transaction + upsert + queue-after-commit logic is
        // shared, unchanged, with the new external
        // POST /api/v1/whatsapp/groups/create endpoint
        // (Api\V1\GroupController) rather than duplicated. Behavior is
        // identical to before this extraction.
        $group = NativeGroupCreationService::create($account, $data['name'], $data['contacts']);

        return response()->json([
            'success' => true,
            'data' => $group,
        ], 201);
    }

    /**
     * GET /api/groups/available-native -- "select an existing group"
     * extension. Lists every REAL WhatsApp group the tenant's connected
     * number already belongs to, MINUS the ones already imported as a
     * ContactGroup (matched by wa_group_jid) -- so a group only ever
     * shows up here until it's been picked once via importNative()
     * below. Same two preconditions storeNativeGroup() enforces (qr
     * engine, connected session) and the same error_code contract, since
     * the frontend already knows how to render those two cases for the
     * "Create Group" modal's Native WhatsApp Group option.
     */
    public function availableNativeGroups(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $engineType = $account->currentSubscription?->engine_type;

        // Phase 5 Task 2 -- provider_capabilities is authoritative for
        // this rule when it states it; the 'qr' literal now lives only
        // in ProviderCapabilityService::supportsNativeWhatsAppGroups().
        if (! app(ProviderCapabilityService::class)->supportsNativeWhatsAppGroups($engineType)) {
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
                'message' => 'Connect your WhatsApp device first -- a live session is required to list your existing WhatsApp groups.',
            ], 422);
        }

        $result = (new NativeWhatsAppGroupService)->listGroups($account->id);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Could not list your WhatsApp groups.',
            ], 422);
        }

        $alreadyImported = ContactGroup::query()
            ->where('account_id', $account->id)
            ->where('group_type', ContactGroup::GROUP_TYPE_NATIVE)
            ->whereNotNull('wa_group_jid')
            ->pluck('wa_group_jid')
            ->all();

        $available = collect($result['groups'])
            ->reject(fn (array $g) => in_array($g['jid'] ?? null, $alreadyImported, true))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $available,
        ]);
    }

    /**
     * POST /api/groups/import-native -- { "group_jid": "...", "name"?: "..." }.
     * "Select an existing group" extension: adopts an already-live
     * WhatsApp group (one returned by availableNativeGroups() above) as
     * a ContactGroup row, pulling in its real current member list --
     * see NativeGroupCreationService::importExisting()'s docblock for
     * why this never dispatches CreateNativeWhatsAppGroupJob the way
     * storeNativeGroup() does.
     *
     * group_jid is NOT separately whitelisted against
     * availableNativeGroups()'s output here: NativeWhatsAppGroupService::
     * groupMetadata() below reaches qr-engine-service, which resolves
     * the group through THIS account's own Baileys session
     * (session.sock.groupMetadata(jid)) -- Baileys itself rejects a jid
     * the connected number has no relationship to, so a tenant can never
     * import another tenant's group by guessing/incrementing a jid; the
     * qr-engine-service call failing IS the authorization check.
     */
    public function importNative(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            'group_jid' => ['required', 'string', 'max:255'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $engineType = $account->currentSubscription?->engine_type;

        // Phase 5 Task 2 -- provider_capabilities is authoritative for
        // this rule when it states it; the 'qr' literal now lives only
        // in ProviderCapabilityService::supportsNativeWhatsAppGroups().
        if (! app(ProviderCapabilityService::class)->supportsNativeWhatsAppGroups($engineType)) {
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
                'message' => 'Connect your WhatsApp device first -- a live session is required to import an existing WhatsApp group.',
            ], 422);
        }

        $alreadyImported = ContactGroup::query()
            ->where('account_id', $account->id)
            ->where('wa_group_jid', $data['group_jid'])
            ->exists();

        if ($alreadyImported) {
            return response()->json([
                'success' => false,
                'message' => 'This WhatsApp group has already been imported.',
            ], 422);
        }

        $metadata = (new NativeWhatsAppGroupService)->groupMetadata($account->id, $data['group_jid']);

        if (! ($metadata['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $metadata['error'] ?? 'Could not fetch this WhatsApp group\'s details.',
            ], 422);
        }

        // A paid Native WhatsApp Groups term allows its included number of groups (the offer's units)
        // -- importing an existing group still adds one more native group row, same as creating one.
        if (app(\App\Services\Billing\CustomGroupAccessService::class)->limitReached($account)) {
            $limit = app(\App\Services\Billing\ModuleAddonService::class)->activeUnitLimit($account, 'contact_groups');

            return response()->json([
                'success' => false,
                'message' => "Your plan includes {$limit} native WhatsApp group".($limit === 1 ? '' : 's').'. Renew or upgrade to add more.',
                'error_code' => 'group_limit_reached',
            ], 422);
        }

        $name = $data['name'] ?? $metadata['subject'] ?? 'Imported WhatsApp Group';

        $group = NativeGroupCreationService::importExisting(
            $account,
            $metadata['jid'],
            $name,
            $metadata['participants'] ?? [],
        );

        return response()->json([
            'success' => true,
            'data' => $group,
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
     * [Re-scoped 2026-10-07, disclosed]: internal segment groups only.
     * A Native WhatsApp Group's membership is no longer editable from
     * this dashboard at all — adding (or removing) someone from a real
     * WhatsApp group is now always done from WhatsApp itself, on the
     * tenant's own phone, the same way any other WhatsApp group works.
     * This previously also pushed the same numbers into the real group
     * via SyncNativeWhatsAppGroupParticipantsJob (Phase 5 P5-C); that
     * path is removed, not merely gated, per the owner's explicit
     * instruction that a native group's membership should never be
     * written to from the app, only read (sync_status/participant count
     * still come FROM WhatsApp, at creation/import time).
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

        if ($group->isNative()) {
            return response()->json(self::NATIVE_MEMBERSHIP_DENIAL, 422);
        }

        $this->insertMembers($group, $data['contacts']);

        return response()->json([
            'success' => true,
            'data' => [
                'group_id' => $group->id,
                'members_count' => $group->members()->count(),
            ],
        ]);
    }

    /**
     * GET /api/groups/{id}/contacts — the group's members, newest first, paginated (50/page). Used
     * by the "Manage Members" view so a member can be picked for removal; a native group's real
     * member list is shown read-only here too (see removeContact()'s own docblock for why removal
     * is refused for one).
     */
    public function listContacts(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);
        $group = ContactGroup::where('account_id', $account->id)->find($id);
        abort_if(! $group, 404, 'Contact group not found.');

        $paginator = $group->members()->orderByDesc('id')->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    /**
     * DELETE /api/groups/{id}/contacts/{memberId} — removes one member from an internal segment
     * group. Refused for a Native WhatsApp Group: its real membership is only ever changed from
     * WhatsApp itself (see addContacts()'s own re-scoping docblock) — removing someone here would
     * only desync this app's shadow copy from the real group, not actually remove them from it.
     */
    public function removeContact(Request $request, int $id, int $memberId): JsonResponse
    {
        $account = $this->requireAccount($request);
        $group = ContactGroup::where('account_id', $account->id)->find($id);
        abort_if(! $group, 404, 'Contact group not found.');

        if ($group->isNative()) {
            return response()->json(self::NATIVE_MEMBERSHIP_DENIAL, 422);
        }

        $deleted = $group->members()->whereKey($memberId)->delete();
        abort_if(! $deleted, 404, 'This member is not in the group.');

        return response()->json([
            'success' => true,
            'data' => ['group_id' => $group->id, 'members_count' => $group->members()->count()],
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

    /**
     * POST /api/groups/{id}/send-template — internal, Sanctum-authenticated
     * counterpart to Api\V1\TemplateMessageController::sendMessage()'s
     * recipient_type=group branch. Added so the web app's own Send Alert
     * screen can send an approved template straight to a Contact Group,
     * not only via the external Developer API (which, until this action
     * existed, was the ONLY way to trigger a group send at all — there
     * was no session-authenticated route for it).
     *
     * Delegates to the exact same GroupMessageDispatcher::dispatch() the
     * external API path already uses — identical quota reservation,
     * template-approval, and module-guard rules, nothing forked or
     * duplicated. Tagged source: 'web_template', the SAME
     * MessageDispatchLog source label MessageTemplateController::send()'s
     * individual-recipient path already uses for this same screen — so
     * Analytics/Message Logs need no new source badge or filter entry
     * for a group send made from here (SOURCE_BADGE/SOURCE_OPTIONS in
     * AnalyticsPage.tsx/MessageLogsPage.tsx already cover 'web_template').
     *
     * No apiKeyId is passed (stays null) — this request is Sanctum
     * session-authenticated, not API-key authenticated, so there is no
     * ApiKey row to attribute the send to.
     *
     * {id} is the ContactGroup id. Not separately looked up here before
     * calling dispatch() — GroupMessageDispatcher::dispatch() itself
     * re-queries ContactGroup::where('account_id', $accountId)->find(),
     * the same tenant-scoped lookup every other action in this
     * controller relies on, so a tenant can never target another
     * tenant's group by guessing/incrementing an id.
     */
    public function sendTemplate(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        $data = $request->validate([
            'template_id' => ['required', 'integer', 'exists:message_templates,id'],
            'variables' => ['sometimes', 'array'],
            // Optional: send to the group at a later time instead of now.
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            // The number to send from. Must be the number the group is on; omitted = the default number.
            'sender_number_id' => ['sometimes', 'nullable', 'integer'],
            'media_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        $group = ContactGroup::query()->where('account_id', $account->id)->find($id);
        if (! $group) {
            return response()->json(['success' => false, 'message' => 'Group not found.'], 404);
        }

        try {
            $senderId = app(\App\Services\WhatsApp\SenderNumberResolver::class)->resolve(
                $account,
                isset($data['sender_number_id']) ? (int) $data['sender_number_id'] : null,
                $group,
            );
        } catch (\App\Services\WhatsApp\SenderNumberException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
        }

        $sendAt = ScheduledMessageService::parseSendAt($data['scheduled_at'] ?? null);
        if ($sendAt !== null) {
            try {
                $row = app(ScheduledMessageService::class)->schedule(
                    $account,
                    ScheduledMessage::KIND_TEMPLATE_GROUP,
                    ['group_id' => $id, 'template_id' => (int) $data['template_id'], 'variables' => $data['variables'] ?? [], 'sender_number_id' => $senderId, 'media_url' => $data['media_url'] ?? null],
                    $sendAt,
                    'web_template',
                );
            } catch (ScheduledMessageException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
            }

            return response()->json([
                'success' => true,
                'message' => 'Group message scheduled.',
                'scheduled' => ['id' => $row->id, 'send_at' => $row->send_at->toIso8601String()],
            ], 202);
        }

        $result = GroupMessageDispatcher::dispatch(
            $account->id,
            $id,
            $data['template_id'],
            $data['variables'] ?? [],
            source: 'web_template',
            superAdminBypass: (bool) $request->attributes->get('is_super_admin'),
            senderNumberId: $senderId,
            mediaUrl: $data['media_url'] ?? null,
        );

        return match ($result['status']) {
            'queued' => response()->json([
                'success' => true,
                'message' => 'Group message queued.',
                'dispatch_id' => $result['dispatch_id'],
                'queued_recipients_count' => $result['queued_recipients_count'],
            ]),
            'group_access_denied' => response()->json(['success' => false, 'message' => $result['message']], 403),
            'empty_group' => response()->json(['success' => false, 'message' => $result['message']], 422),
            'template_not_approved' => response()->json(['success' => false, 'message' => $result['message']], 422),
            'not_found' => response()->json(['success' => false, 'message' => $result['message']], 404),
            'disconnected' => response()->json(['success' => false, 'message' => $result['message'], 'error_code' => 'WHATSAPP_DISCONNECTED'], 422),
            'quota_exhausted' => response()->json(['success' => false, 'message' => $result['message']], 402),
            'insufficient_quota' => response()->json([
                'success' => false,
                'message' => "This group dispatch requires {$result['required']} credits, but your account only has {$result['remaining']} remaining credits.",
            ], 402),
            'group_not_synced' => response()->json(['success' => false, 'message' => $result['message'] ?? 'This native WhatsApp group has not finished syncing yet.'], 422),
            'unsupported_engine' => response()->json(['success' => false, 'message' => $result['message'] ?? 'This WhatsApp engine does not support group messaging.'], 422),
            default => response()->json(['success' => false, 'message' => $result['message'] ?? 'Could not queue this group dispatch.'], 422),
        };
    }

    /**
     * POST /api/groups/{id}/recreate — one-click response to a
     * native_wa_group whose sync_status is 'failed', whether that
     * happened at initial creation (CreateNativeWhatsAppGroupJob) or
     * later, when a send to it failed (ProcessGroupDispatchJob's new
     * sync_status flip — see that method's docblock). Re-runs group
     * creation for the SAME ContactGroup row, reusing its existing name
     * and member list: resets wa_group_jid/invite_link/sync_error and
     * sync_status back to 'pending', then re-dispatches
     * CreateNativeWhatsAppGroupJob — the exact same job
     * ContactGroupController::storeNativeGroup() dispatches for a brand
     * new group, which is why resetting to 'pending' first is required:
     * that job no-ops unless sync_status === SYNC_STATUS_PENDING
     * (see its own docblock/guard).
     *
     * [Disclosed, important, must be surfaced to the user before they
     * click this — see contactGroupsService.ts/ContactGroupsPage.tsx]:
     * this ALWAYS creates a brand-new WhatsApp group with a new JID via
     * Baileys' groupCreate() — it can never detect or reuse the old
     * group even if that old group still actually exists on WhatsApp
     * (e.g. the failure that triggered this was a transient error, not
     * an actual deletion — see ProcessGroupDispatchJob's own disclosed
     * uncertainty about what failures are even detectable). In that
     * case this produces a genuine duplicate: the old group keeps
     * existing on WhatsApp with its members, orphaned from this app,
     * while this row now points at a second, new, empty-until-synced
     * group. There is no way to avoid this risk from the backend alone
     * — only the person clicking Recreate can know whether the original
     * group is actually gone.
     */
    public function recreate(Request $request, int $id): JsonResponse
    {
        $account = $this->requireAccount($request);

        $group = ContactGroup::where('account_id', $account->id)->find($id);
        abort_if(! $group, 404, 'Contact group not found.');

        if (! $group->isNative()) {
            return response()->json([
                'success' => false,
                'message' => 'Only native WhatsApp groups can be recreated.',
            ], 422);
        }

        if ($group->sync_status === ContactGroup::SYNC_STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'This group is already syncing.',
            ], 409);
        }

        // Same two preconditions storeNativeGroup() enforces for a brand
        // new native group — re-checked here since the account's engine/
        // connection state can have changed since this group was first
        // created.
        $engineType = $account->currentSubscription?->engine_type;

        // Phase 5 Task 2 -- provider_capabilities is authoritative for
        // this rule when it states it; the 'qr' literal now lives only
        // in ProviderCapabilityService::supportsNativeWhatsAppGroups().
        if (! app(ProviderCapabilityService::class)->supportsNativeWhatsAppGroups($engineType)) {
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

        $group->forceFill([
            'wa_group_jid' => null,
            'invite_link' => null,
            'sync_status' => ContactGroup::SYNC_STATUS_PENDING,
            'sync_error' => null,
        ])->save();

        CreateNativeWhatsAppGroupJob::dispatch($group->id);

        return response()->json([
            'success' => true,
            'data' => $group->fresh()->loadCount('members'),
        ]);
    }
}
