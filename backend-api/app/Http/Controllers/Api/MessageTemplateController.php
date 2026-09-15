<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendTemplateMessageRequest;
use App\Models\Account;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use App\Services\Templates\TemplateMessageDispatcher;
use App\Services\Templates\TemplateService;
use App\Services\WhatsApp\WhatsAppEngineFactory;
use App\Support\PhoneNumberNormalizer;
use App\Support\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Dynamic Templates & Variables System -- Template Designer & Approval
 * Panel. index()/store()/update()/approve()/reject() are reachable by
 * Super Admin (platform-wide, unrestricted) AND, as of the Tiered
 * Template Approval Workflow for 3-Tier Hierarchy feature, by an Agent
 * (Reseller) -- scoped to their own account plus their own Sub-Clients
 * only, via assertAgentOwnsTemplate()/the Agent branches inside each
 * method below. Both are gated by the SAME permission:manage-templates
 * route group (routes/api.php, outside tenant.isolation -- a template
 * isn't tenant-scoped DATA, it's content one Super Admin or Agent
 * manages for the accounts they're allowed to reach), mirroring
 * AccountController's own Super-Admin-vs-Agent scoping pattern
 * (callerAgentScopeId() there / assertAgentOwnsTemplate() here) rather
 * than inventing a second route group.
 *
 * destroy() and test() are deliberately EXCLUDED from the Agent grant
 * despite sharing the same route-level permission -- see each method's
 * own docblock for why.
 *
 * available()/send() are the tenant-scoped actions here -- what a Client
 * Admin's Send Alert page (and, indirectly, the external
 * /v1/messages/send-template endpoint via TemplateMessageDispatcher) is
 * allowed to actually use -- so they alone use ResolvesTenantAccount and
 * sit behind permission:send-messages in routes/api.php, not
 * manage-templates. submitRequest() is the newest tenant-scoped action,
 * added by the Tiered Template Approval Workflow feature: a plain Client
 * Admin/User submitting their OWN template request (see its own
 * docblock).
 */
class MessageTemplateController extends Controller
{
    use ResolvesTenantAccount;

    public function __construct(
        private readonly TemplateService $templateService,
    ) {
    }

    /**
     * GET /api/message-templates -- every template this caller may see,
     * optionally filtered. Super Admin: everything, any status
     * (platform management view, unchanged). Agent: only templates for
     * their own account or one of their own Sub-Clients (Tiered Template
     * Approval Workflow) -- a plain Client/User can never reach this
     * route at all (manage-templates is seeded only onto super_admin and
     * agent, RolePermissionSeeder).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(MessageTemplate::STATUSES)],
            'account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $agentAccount = $this->agentAccountOrNull($user);

        $templates = MessageTemplate::query()
            ->with('creator:id,name')
            ->when($agentAccount, function ($q) use ($agentAccount) {
                // Tiered Template Approval Workflow -- an Agent's own
                // account's templates (Rule 4) plus every Sub-Client's
                // (Rules 1/3). A global (account_id null) or another
                // Agent's tree's template never appears here.
                $q->where(function ($qq) use ($agentAccount) {
                    $qq->where('account_id', $agentAccount->id)
                        ->orWhereHas('account', fn ($qa) => $qa->where('agent_id', $agentAccount->id));
                });
            })
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(array_key_exists('account_id', $filters), fn ($q) => $q->where('account_id', $filters['account_id']))
            ->when(! empty($filters['search']), fn ($q) => $q->where('title', 'like', '%'.$filters['search'].'%'))
            ->latest('id')
            ->get();

        return response()->json(['data' => $templates]);
    }

    /** GET /api/alerts/message-templates -- approved templates this tenant may actually send. Tenant-scoped. */
    public function available(Request $request): JsonResponse
    {
        $account = $this->resolveAccount($request);

        $templates = MessageTemplate::query()
            ->approvedFor($account?->id)
            ->orderBy('title')
            ->get();

        return response()->json([
            'data' => $templates->map(fn (MessageTemplate $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'industry_type' => $t->industry_type,
                'template_body' => $t->template_body,
                'variables' => $t->variableNames(),
                'variables_schema' => $t->effectiveVariablesSchema(),
            ]),
        ]);
    }

    /**
     * POST /api/alerts/send-template -- the Client Admin Dynamic Form
     * Engine's actual submit action (internal, Sanctum-authenticated
     * counterpart to the external Api\V1\TemplateMessageController).
     * Shares TemplateMessageDispatcher with that external endpoint so
     * approval/quota/variable-completeness rules can never drift between
     * the two entry points.
     */
    public function send(SendTemplateMessageRequest $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to send from (pass ?account_id=).');

        $data = $request->validated();

        $result = TemplateMessageDispatcher::dispatch(
            $account->id,
            $data['template_id'],
            $data['recipient_phone'],
            $data['variables'] ?? [],
            mediaUrl: $data['media_url'] ?? null,
        );

        return match ($result['status']) {
            'sent' => response()->json(['message' => 'Message sent.', 'rendered_message' => $result['rendered_message']]),
            'missing_variables' => response()->json(['message' => $result['message'], 'missing' => $result['missing']], 422),
            'not_found' => response()->json(['message' => $result['message']], 404),
            'disconnected' => response()->json(['message' => $result['message'], 'error_code' => 'WHATSAPP_DISCONNECTED'], 422),
            'quota_exhausted' => response()->json(['message' => $result['message']], 403),
            default => response()->json(['message' => $result['message'] ?? 'Could not send this message.'], 422),
        };
    }

    /**
     * POST /api/alerts/message-templates/request -- Tiered Template
     * Approval Workflow for 3-Tier Hierarchy, Rules 1 & 2. A plain
     * Client Admin/User submitting a NEW template request for their OWN
     * account (account_id is always the resolved tenant account, never
     * client-supplied -- same ResolvesTenantAccount pattern every other
     * tenant-scoped action in this codebase already uses). Initial
     * status is decided purely by that account's OWN agent_id via
     * TemplateService::resolveCreationStatus() -- a Sub-Client lands on
     * 'pending_agent_review' (routed to its Parent Agent), a direct
     * client on 'pending_admin_review' (routed straight to Super Admin).
     *
     * Super Admin and Agent users never call this endpoint (they author
     * directly via store() below, which is where Rule 4's "bypass the
     * queues" behavior lives) -- reachable only under permission:
     * send-messages, which manage-templates-holding roles don't need and
     * this route doesn't grant them anything extra even if they did
     * reach it (it would just create ANOTHER 'pending'/'pending_agent_
     * review' row for their own account, same as store() would for a
     * Super Admin).
     */
    public function submitRequest(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to submit this template request from (pass ?account_id=).');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'industry_type' => ['nullable', 'string', 'max:100'],
            'template_body' => ['required', 'string', 'max:4000'],
            // Media Templates (QR/Baileys-only) -- lets a self-service
            // request (e.g. "send this bill as a PDF") specify a media
            // header the same way store() does for an Agent/Super-Admin-
            // authored template. Reviewed the same as the rest of the
            // request -- an Agent/Super Admin can still edit it before
            // approving via update() above.
            'header_type' => ['sometimes', 'string', Rule::in(MessageTemplate::HEADER_TYPES)],
            'header_media_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url'],
            ...$this->variablesSchemaRules(),
        ]);

        // [Removed, disclosed]: this used to call
        // assertSingleApprovedTemplatePerAccount() here, blocking a new
        // request while an approved template was already live for this
        // account. Per explicit product decision, a client may now hold
        // several templates (e.g. one per use case) — 1-template-per-
        // client is no longer enforced anywhere in this controller.
        $status = $this->templateService->resolveCreationStatus($request->user(), $account);

        $template = MessageTemplate::create([
            ...$data,
            'account_id' => $account->id,
            'status' => $status,
            'created_by' => $request->user()->id,
        ]);

        $this->templateService->notifyPendingReview($template->fresh());

        return response()->json(['message' => 'Template request submitted.', 'data' => $template], 201);
    }

    /**
     * GET /api/alerts/message-templates/mine -- every template belonging
     * to the caller's own account, ANY status (unlike available() above,
     * which is approved-only and shaped for the Send Alert form). Gives
     * a plain Client Admin/User -- who can never reach the permission:
     * manage-templates index() below -- visibility into what they've
     * requested and its current review status, closing the "I submitted
     * a request, now where do I see it?" gap submitRequest() alone left
     * open. Same send-messages tier as every other route in this group.
     */
    public function myTemplates(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account to view its templates (pass ?account_id=).');

        $templates = MessageTemplate::query()
            ->where('account_id', $account->id)
            ->orderByDesc('id')
            ->get(['id', 'title', 'industry_type', 'status', 'rejection_reason', 'created_at']);

        return response()->json(['data' => $templates]);
    }

    /**
     * POST /api/admin/templates/{id}/test -- Strict 1-Template-Per-Client &
     * Testing Gate. Fires ONE real WhatsApp send through this template
     * regardless of its approval status (a pending template must be
     * test-fireable -- that is the entire point of a pre-approval gate),
     * and only on a confirmed successful send does it flip
     * is_super_admin_tested = true / tested_at = now(). approve() refuses
     * to run at all until that flag is set.
     *
     * Deliberately does NOT go through TemplateMessageDispatcher::dispatch()
     * -- dispatch() requires MessageTemplate::approvedFor($accountId),
     * which a template being tested (by definition, not yet approved) can
     * never satisfy. This is a parallel, narrower send path:
     *   - skips the approval-status gate entirely,
     *   - skips per-message quota deduction -- a Super Admin's test-fire
     *     is not billable client usage and must never consume a tenant's
     *     paid message quota,
     *   - prefixes the outgoing text with "[TEST] " so the recipient (and
     *     any WhatsApp chat history) can never mistake it for a real
     *     alert,
     *   - ALWAYS sends through Account::platformDevice() -- the Super
     *     Admin's OWN scanned WhatsApp connection.
     *
     * Tiered Template Approval Workflow for 3-Tier Hierarchy --
     * explicitly Super-Admin-only, EVEN THOUGH this route shares the
     * same permission:manage-templates gate an Agent now also holds. An
     * Agent test-firing here would spend a send through the SUPER
     * ADMIN'S OWN connected WhatsApp number, not their own -- a real
     * cross-tenant abuse surface, not a hierarchy permission this
     * feature was asked to grant. Agents review/approve/reject/edit
     * content without a live test-fire in this pass; the mandatory
     * real-world test remains a Super-Admin-only step immediately before
     * 'approved', unchanged.
     */
    public function test(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'Only Super Admin may test-fire a template through the platform device.');

        $template = MessageTemplate::findOrFail($id);
        $account = Account::platformDevice();

        $data = $request->validate([
            'recipient_phone' => ['required', 'string', 'max:20'],
            'variables' => ['sometimes', 'array'],
            ...MessageTemplate::variableValidationRules($template->effectiveVariablesSchema()),
        ]);

        if (PaymentAlertDispatcher::isWhatsAppDisconnected($account)) {
            return response()->json([
                'message' => 'Your WhatsApp test device is disconnected. Please scan WhatsApp and send again.',
                'error_code' => 'WHATSAPP_DISCONNECTED',
            ], 422);
        }

        $renderVariables = $data['variables'] ?? [];
        foreach ($template->effectiveVariablesSchema() as $field) {
            if (! array_key_exists($field['key'], $renderVariables)) {
                $renderVariables[$field['key']] = '';
            }
        }

        $renderedMessage = '[TEST] '.TemplateRenderer::render($template->template_body, $renderVariables);

        $normalizedPhone = PhoneNumberNormalizer::normalize($data['recipient_phone']);
        if ($normalizedPhone === '') {
            return response()->json(['message' => "Recipient phone number '{$data['recipient_phone']}' is not a valid number after normalization."], 422);
        }

        try {
            $driver = WhatsAppEngineFactory::make($account);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Media Templates (QR/Baileys-only) -- the Testing Gate's whole
        // point is proving a template actually sends correctly before it
        // can be approved (is_super_admin_tested below); test-firing a
        // media template as plain text would rubber-stamp it without
        // ever exercising the media path. Reuses TemplateMessageDispatcher::
        // resolveMediaMetaData() (no send-time override here -- a test-fire
        // only ever exercises the template's own configured
        // header_media_url) so "is there usable media" is answered
        // identically to a real send.
        $metaData = [
            'template_id' => $template->id,
            'test' => true,
            ...TemplateMessageDispatcher::resolveMediaMetaData($template, $account, mediaUrl: null),
        ];

        $result = $driver->sendMessage($normalizedPhone, $renderedMessage, $metaData);

        if (empty($result['success'])) {
            return response()->json(['message' => $result['error'] ?? 'The WhatsApp engine rejected the test message.'], 422);
        }

        $template->forceFill(['is_super_admin_tested' => true, 'tested_at' => now()])->save();

        return response()->json([
            'message' => 'Test message sent.',
            'rendered_message' => $renderedMessage,
            'data' => $template->fresh(),
        ]);
    }

    /**
     * POST /api/message-templates -- Super Admin (any account, including
     * global/null) or, as of the Tiered Template Approval Workflow
     * feature, an Agent authoring directly for their own account or one
     * of their own Sub-Clients (Rule 4). Status is decided by
     * TemplateService::resolveCreationStatus() -- always 'pending' for
     * both of those callers (see that method's docblock); it is never
     * called with a plain-Client creator from this action, since a plain
     * Client can't reach this route (manage-templates isn't seeded onto
     * 'admin'/'user' -- see submitRequest() for that caller's own entry
     * point).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'industry_type' => ['nullable', 'string', 'max:100'],
            'template_body' => ['required', 'string', 'max:4000'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            // Media Templates (QR/Baileys-only) -- see MessageTemplate::
            // requiresHeaderMedia()/TemplateMessageDispatcher for how
            // these are used at send time. header_media_url is only
            // actually required when header_type is image/document;
            // omitting both keeps the pre-existing plain-text behavior
            // (header_type defaults to 'text' at the DB level).
            'header_type' => ['sometimes', 'string', Rule::in(MessageTemplate::HEADER_TYPES)],
            'header_media_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url'],
            ...$this->variablesSchemaRules(),
        ]);

        $user = $request->user();
        $agentAccount = $this->agentAccountOrNull($user);
        $targetAccount = ! empty($data['account_id']) ? Account::findOrFail($data['account_id']) : null;

        if ($agentAccount) {
            // Tiered Template Approval Workflow, Rule 4 -- an Agent may
            // only author directly for its own account or one of its own
            // Sub-Clients, and (unlike Super Admin) can never create a
            // global template -- account_id is required for this caller.
            abort_if($targetAccount === null, 422, 'Select an account to create this template for -- global templates remain Super-Admin-only.');
            abort_unless(
                $targetAccount->id === $agentAccount->id || $targetAccount->agent_id === $agentAccount->id,
                404
            );
        }

        // [Removed, disclosed]: creation here used to be blocked when the
        // target client already had an approved template live
        // (assertSingleApprovedTemplatePerAccount()). Multiple templates
        // per client are now allowed — see submitRequest()'s own note.
        $status = $targetAccount
            ? $this->templateService->resolveCreationStatus($user, $targetAccount)
            : 'pending'; // global -- Super Admin only, per the Agent guard above.

        $template = MessageTemplate::create([
            ...$data,
            'status' => $status,
            'created_by' => $user->id,
        ]);

        return response()->json(['message' => 'Template created.', 'data' => $template], 201);
    }

    /**
     * PUT /api/message-templates/{id} -- also how a template is
     * "assigned" to (or unassigned/globalized from) a specific client:
     * account_id is just another editable field, not a separate
     * sub-resource -- the same shape store() already accepts.
     *
     * Tiered Template Approval Workflow, Rule 1 ("the assigned Agent
     * can ... edit the template") -- an Agent may edit any template
     * already scoped to their own account or one of their own
     * Sub-Clients, at any status (this predates and is independent of
     * approve()/reject()'s own 'pending_agent_review'-only gate below --
     * editing content is not itself a review decision). Re-assigning
     * account_id away from their own tree is blocked the same way
     * store() blocks creating outside it.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $template = MessageTemplate::findOrFail($id);
        $user = $request->user();
        $agentAccount = $this->agentAccountOrNull($user);

        if ($agentAccount) {
            $this->assertAgentOwnsTemplate($agentAccount, $template);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'industry_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'template_body' => ['sometimes', 'string', 'max:4000'],
            'account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            // Media Templates (QR/Baileys-only) -- same rule shape as
            // store() above.
            'header_type' => ['sometimes', 'string', Rule::in(MessageTemplate::HEADER_TYPES)],
            'header_media_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url'],
            ...$this->variablesSchemaRules(prefix: 'sometimes'),
        ]);

        // Switching a template back to 'text' drops any previously-set
        // media URL rather than leaving it stale -- MessageTemplate::
        // requiresHeaderMedia() already guards on header_type too, so a
        // leftover URL is harmless either way, but this keeps the two
        // fields from silently disagreeing once an admin can see both in
        // the edit form.
        if (($data['header_type'] ?? null) === 'text') {
            $data['header_media_url'] = null;
        }

        if (array_key_exists('account_id', $data) && $data['account_id'] !== $template->account_id) {
            if ($agentAccount) {
                $newTarget = $data['account_id'] ? Account::findOrFail($data['account_id']) : null;
                abort_if(
                    $newTarget === null || ! ($newTarget->id === $agentAccount->id || $newTarget->agent_id === $agentAccount->id),
                    404
                );
            }

            // [Removed, disclosed]: (re)assigning a template to a client
            // used to be blocked the same way store() was when that
            // client already had an approved template live. No longer
            // enforced — see submitRequest()'s own note.
        }

        $template->fill($data)->save();

        return response()->json(['message' => 'Template updated.', 'data' => $template->fresh()]);
    }

    /**
     * PATCH /api/message-templates/{id}/approve
     *
     * Super Admin: unchanged -- retains override permission to approve
     * ANY template directly regardless of its current status (Tiered
     * Template Approval Workflow, Rule 3), still gated by the
     * pre-existing Strict 1-Template-Per-Client & Testing Gate
     * (is_super_admin_tested) and the single-approved-template-per-
     * account check.
     *
     * Agent: a NEW branch (Rule 1) -- may only "approve" a
     * 'pending_agent_review' template belonging to one of their own
     * Sub-Clients, and doing so never sets 'approved' directly (see
     * TemplateService's docblock for why) -- it forwards to
     * routeAfterAgentApproval()'s result ('pending' or
     * 'pending_meta_approval', by that Sub-Client's engine type) and
     * notifies Super Admin that it's now awaiting their final review.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $template = MessageTemplate::findOrFail($id);
        $user = $request->user();
        $agentAccount = $this->agentAccountOrNull($user);

        if (! $agentAccount) {
            if (! $template->is_super_admin_tested) {
                abort(422, 'This template has not been successfully test-fired yet. Use POST /admin/templates/{id}/test before approving it.');
            }

            // [Removed, disclosed]: approving used to be blocked when the
            // account already had a DIFFERENT approved template live.
            // Multiple approved templates per client are now allowed —
            // see submitRequest()'s own note; the Testing Gate above
            // (is_super_admin_tested) is unaffected and still enforced.
            $template->forceFill(['status' => 'approved'])->save();

            return response()->json(['message' => 'Template approved.', 'data' => $template->fresh()]);
        }

        $this->assertAgentOwnsTemplate($agentAccount, $template);
        abort_unless($template->status === 'pending_agent_review', 422, 'This template is not awaiting your review.');

        $nextStatus = $this->templateService->routeAfterAgentApproval($template->account);
        $template->forceFill(['status' => $nextStatus])->save();
        $this->templateService->notifyPendingReview($template->fresh());

        return response()->json(['message' => 'Template approved and forwarded for final review.', 'data' => $template->fresh()]);
    }

    /**
     * PATCH /api/message-templates/{id}/reject -- Super Admin: any
     * status, unchanged reach (Rule 3). Agent: only their own
     * 'pending_agent_review' Sub-Client submissions (Rule 1). Both
     * branches now accept an optional rejection_reason -- the column has
     * existed, unused, since the header_type/rejection_reason migration;
     * this feature is what finally wires it, and notifies the
     * submitting account's owner either way.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $template = MessageTemplate::findOrFail($id);
        $user = $request->user();
        $agentAccount = $this->agentAccountOrNull($user);

        if ($agentAccount) {
            $this->assertAgentOwnsTemplate($agentAccount, $template);
            abort_unless($template->status === 'pending_agent_review', 422, 'This template is not awaiting your review.');
        }

        $data = $request->validate([
            'rejection_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $template->forceFill([
            'status' => 'rejected',
            'rejection_reason' => array_key_exists('rejection_reason', $data) ? $data['rejection_reason'] : $template->rejection_reason,
        ])->save();

        $this->templateService->notifyRejected($template->fresh());

        return response()->json(['message' => 'Template rejected.', 'data' => $template->fresh()]);
    }

    /**
     * DELETE /api/message-templates/{id} -- deliberately Super-Admin-only,
     * unlike index/store/update/approve/reject above. Nothing in the
     * Tiered Template Approval Workflow spec grants an Agent delete
     * rights (Rule 1 lists only "approve, reject, or edit"); disclosed
     * here rather than silently extended.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        $template = MessageTemplate::findOrFail($id);
        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    /**
     * Tiered Template Approval Workflow -- the caller's own Account when
     * (and only when) they are an Agent (Reseller), else null (Super
     * Admin, or -- unreachable via routing today, see each call site --
     * anyone else). Mirrors AccountController::callerAgentScopeId()
     * exactly, kept as a small local helper rather than a shared trait
     * since it returns the Account itself (needed here for
     * ->id/->agent_id checks and relation loads), not just an id.
     */
    private function agentAccountOrNull(User $user): ?Account
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        $account = $user->account;

        return $account?->account_type === 'agent' ? $account : null;
    }

    /**
     * Guards every Agent-reachable {id} action so a caller gets the SAME
     * 404 for "doesn't exist" and "exists but isn't yours" -- same
     * non-disclosure precedent AccountController::assertCallerCanAccessAccount()
     * already applies. A global (account_id null) template is never
     * owned by any Agent.
     */
    private function assertAgentOwnsTemplate(Account $agentAccount, MessageTemplate $template): void
    {
        abort_if($template->account_id === null, 404);
        abort_unless(
            $template->account_id === $agentAccount->id || $template->account?->agent_id === $agentAccount->id,
            404
        );
    }

    /**
     * Variable Configurator Panel -- shared validation rules for the
     * variables_schema array a Super Admin/Agent submits from
     * store()/update(), or a Client submits from submitRequest().
     * `key` isn't cross-checked against template_body's {{tokens}} here
     * (a template_body edit and a variables_schema edit can arrive in
     * the same request in either order) -- MessageTemplate::effectiveVariablesSchema()
     * is what actually matters at send-time, and it already tolerates a
     * schema entry whose key has no matching {{token}} (simply unused)
     * or a {{token}} with no schema entry (falls back to the auto-derived
     * string/required default for that one key -- see that method).
     *
     * `$prefix` is 'required' on create, 'sometimes' on update, matching
     * every other field in store()/update()/submitRequest() above.
     *
     * @return array<string, array<int, mixed>>
     */
    private function variablesSchemaRules(string $prefix = 'nullable'): array
    {
        return [
            'variables_schema' => [$prefix, 'array'],
            'variables_schema.*.key' => ['required_with:variables_schema', 'string', 'max:100'],
            'variables_schema.*.label' => ['required_with:variables_schema', 'string', 'max:255'],
            'variables_schema.*.type' => ['required_with:variables_schema', 'string', Rule::in(MessageTemplate::VARIABLE_TYPES)],
            'variables_schema.*.required' => ['required_with:variables_schema', 'boolean'],
            'variables_schema.*.options' => ['required_if:variables_schema.*.type,select', 'array'],
            'variables_schema.*.options.*' => ['string', 'max:255'],
        ];
    }

    // [Removed, disclosed]: assertSingleApprovedTemplatePerAccount() used
    // to live here, blocking a client from ever having more than one
    // APPROVED template at a time (called from store()/submitRequest()/
    // update()/approve()). Per explicit product decision, clients may now
    // hold multiple approved templates simultaneously -- e.g. one per use
    // case (payment confirmation, property inquiry, ...) -- selected by
    // template_id on Send Alert exactly as MessageTemplate::scopeApprovedFor()
    // and TemplateMessageDispatcher already supported natively (neither
    // ever assumed "the one" template for an account; this method was the
    // only place that limit was actually enforced). Removed rather than
    // left dead, so a future reader isn't left wondering why it's unused.
}
