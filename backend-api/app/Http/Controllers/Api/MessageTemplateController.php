<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendTemplateMessageRequest;
use App\Models\Account;
use App\Models\MessageTemplate;
use App\Services\PaymentAlerts\PaymentAlertDispatcher;
use App\Services\Templates\TemplateMessageDispatcher;
use App\Services\WhatsApp\WhatsAppEngineFactory;
use App\Support\PhoneNumberNormalizer;
use App\Support\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Dynamic Templates & Variables System — Super Admin Template Designer &
 * Approval Panel. index()/store()/update()/approve()/reject()/destroy()
 * are platform-wide management (permission:manage-templates,
 * Super-Admin-only per RolePermissionSeeder — mirrors AccountController's
 * "sits outside tenant.isolation, one permission is the whole gate"
 * pattern, since a template isn't tenant-scoped DATA, it's platform
 * content one Super Admin manages for every/any tenant).
 *
 * available() is the one tenant-scoped action here — what a Client Admin's
 * Send Alert page (and, indirectly, the external /v1/messages/send-template
 * endpoint via TemplateMessageDispatcher) is allowed to actually use — so
 * it alone uses ResolvesTenantAccount and sits behind permission:send-messages
 * in routes/api.php, not manage-templates.
 */
class MessageTemplateController extends Controller
{
    use ResolvesTenantAccount;

    /** GET /api/message-templates — every template, any status, optionally filtered. Super Admin management view. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(MessageTemplate::STATUSES)],
            'account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $templates = MessageTemplate::query()
            ->with('creator:id,name')
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(array_key_exists('account_id', $filters), fn ($q) => $q->where('account_id', $filters['account_id']))
            ->when(! empty($filters['search']), fn ($q) => $q->where('title', 'like', '%'.$filters['search'].'%'))
            ->latest('id')
            ->get();

        return response()->json(['data' => $templates]);
    }

    /** GET /api/alerts/message-templates — approved templates this tenant may actually send. Tenant-scoped. */
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
     * POST /api/alerts/send-template — the Client Admin Dynamic Form
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
     * POST /api/admin/templates/{id}/test — Strict 1-Template-Per-Client &
     * Testing Gate. Fires ONE real WhatsApp send through this template
     * regardless of its approval status (a pending template must be
     * test-fireable — that is the entire point of a pre-approval gate),
     * and only on a confirmed successful send does it flip
     * is_super_admin_tested = true / tested_at = now(). approve() refuses
     * to run at all until that flag is set.
     *
     * Deliberately does NOT go through TemplateMessageDispatcher::dispatch()
     * — dispatch() requires MessageTemplate::approvedFor($accountId),
     * which a template being tested (by definition, not yet approved) can
     * never satisfy. This is a parallel, narrower send path:
     *   - skips the approval-status gate entirely,
     *   - skips per-message quota deduction — a Super Admin's test-fire
     *     is not billable client usage and must never consume a tenant's
     *     paid message quota,
     *   - prefixes the outgoing text with "[TEST] " so the recipient (and
     *     any WhatsApp chat history) can never mistake it for a real
     *     alert,
     *   - resolves the tenant to send THROUGH from the request body
     *     (account_id), not ResolvesTenantAccount — this route sits
     *     outside tenant.isolation (see this class's top docblock), so no
     *     ?account_id= query attribute is ever set for it; the template's
     *     own account_id is the fallback when the caller omits one and
     *     the template is already scoped to a specific client.
     *
     * Variable validation reuses MessageTemplate::variableValidationRules()
     * — the exact same per-field type/required rules
     * SendTemplateMessageRequest enforces for a live client send — so a
     * template that "tests clean" is validated identically to how a real
     * client send will be validated.
     */
    public function test(Request $request, int $id): JsonResponse
    {
        $template = MessageTemplate::findOrFail($id);

        $accountId = $request->integer('account_id') ?: $template->account_id;

        if (! $accountId) {
            return response()->json([
                'message' => 'This template is not assigned to a client account yet. Pass account_id to test-send through a specific client\'s WhatsApp connection.',
            ], 422);
        }

        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($accountId);

        if (! $account) {
            return response()->json(['message' => 'Account not found.'], 404);
        }

        $data = $request->validate([
            'account_id' => ['sometimes', 'integer', 'exists:accounts,id'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'variables' => ['sometimes', 'array'],
            ...MessageTemplate::variableValidationRules($template->effectiveVariablesSchema()),
        ]);

        if (PaymentAlertDispatcher::isWhatsAppDisconnected($account)) {
            return response()->json([
                'message' => 'WhatsApp is disconnected for this account. Connect its device before test-firing.',
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

        $result = $driver->sendMessage($normalizedPhone, $renderedMessage, ['template_id' => $template->id, 'test' => true]);

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

    /** POST /api/message-templates — status always starts 'pending', regardless of what the caller sends. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'industry_type' => ['nullable', 'string', 'max:100'],
            'template_body' => ['required', 'string', 'max:4000'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            ...$this->variablesSchemaRules(),
        ]);

        // Strict 1-Template-Per-Client & Testing Gate: block creating ANY
        // new template (even 'pending') for a client that already has an
        // approved one live, per the spec's literal "Block creation if 1
        // template already exists for that client until deleted or
        // revoked." Global templates (account_id null) are exempt.
        $this->assertSingleApprovedTemplatePerAccount($data['account_id'] ?? null);

        $template = MessageTemplate::create([
            ...$data,
            'status' => 'pending',
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Template created.', 'data' => $template], 201);
    }

    /**
     * PUT /api/message-templates/{id} — also how a template is
     * "assigned" to (or unassigned/globalized from) a specific client:
     * account_id is just another editable field, not a separate
     * sub-resource — the same shape store() already accepts.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $template = MessageTemplate::findOrFail($id);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'industry_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'template_body' => ['sometimes', 'string', 'max:4000'],
            'account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            ...$this->variablesSchemaRules(prefix: 'sometimes'),
        ]);

        // Strict 1-Template-Per-Client & Testing Gate: "assigning a
        // template to a client" (the update() route is how account_id is
        // (re)assigned — see this method's own docblock) is gated the
        // same way store() is, but only when account_id is actually
        // changing — resaving a template with its existing account_id
        // must never trip over its own approved row.
        if (array_key_exists('account_id', $data) && $data['account_id'] !== $template->account_id) {
            $this->assertSingleApprovedTemplatePerAccount($data['account_id'], excludingTemplateId: $id);
        }

        $template->fill($data)->save();

        return response()->json(['message' => 'Template updated.', 'data' => $template->fresh()]);
    }

    /**
     * PATCH /api/message-templates/{id}/approve
     *
     * Super Admin Testing Enforcement: a template cannot go live for a
     * client until it has been successfully test-fired via
     * POST /admin/templates/{id}/test at least once (see test() below).
     * This is also the final, authoritative Single Template Limit gate —
     * store()/update() already block the common paths, but this check
     * closes the remaining gap where two independently-created 'pending'
     * templates for the same account could otherwise both attempt to
     * become 'approved'.
     */
    public function approve(int $id): JsonResponse
    {
        $template = MessageTemplate::findOrFail($id);

        if (! $template->is_super_admin_tested) {
            abort(422, 'This template has not been successfully test-fired yet. Use POST /admin/templates/{id}/test before approving it.');
        }

        $this->assertSingleApprovedTemplatePerAccount($template->account_id, excludingTemplateId: $id);

        $template->forceFill(['status' => 'approved'])->save();

        return response()->json(['message' => 'Template approved.', 'data' => $template->fresh()]);
    }

    /** PATCH /api/message-templates/{id}/reject */
    public function reject(int $id): JsonResponse
    {
        $template = MessageTemplate::findOrFail($id);
        $template->forceFill(['status' => 'rejected'])->save();

        return response()->json(['message' => 'Template rejected.', 'data' => $template->fresh()]);
    }

    /** DELETE /api/message-templates/{id} */
    public function destroy(int $id): JsonResponse
    {
        $template = MessageTemplate::findOrFail($id);
        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    /**
     * Variable Configurator Panel — shared validation rules for the
     * variables_schema array a Super Admin submits from store()/update().
     * `key` isn't cross-checked against template_body's {{tokens}} here
     * (a template_body edit and a variables_schema edit can arrive in
     * the same request in either order) — MessageTemplate::effectiveVariablesSchema()
     * is what actually matters at send-time, and it already tolerates a
     * schema entry whose key has no matching {{token}} (simply unused)
     * or a {{token}} with no schema entry (falls back to the auto-derived
     * string/required default for that one key — see that method).
     *
     * `$prefix` is 'required' on create, 'sometimes' on update, matching
     * every other field in store()/update() above.
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

    /**
     * Strict 1-Template-Per-Client & Testing Gate — a client account may
     * have at most one APPROVED template live at a time ("Active/Approved"
     * per the spec; this table has no separate 'active' flag distinct
     * from status, so status === 'approved' is the check). Global
     * templates (account_id null) are exempt — the limit is per CLIENT.
     * Called from store() (creation), update() (re-assignment), and
     * approve() (the final authoritative gate) — see each call site's
     * own comment for why it needs this check at that specific moment.
     */
    private function assertSingleApprovedTemplatePerAccount(?int $accountId, ?int $excludingTemplateId = null): void
    {
        if (! $accountId) {
            return;
        }

        $exists = MessageTemplate::query()
            ->where('account_id', $accountId)
            ->where('status', 'approved')
            ->when($excludingTemplateId, fn ($q) => $q->where('id', '!=', $excludingTemplateId))
            ->exists();

        if ($exists) {
            abort(422, 'This client already has an approved template. Delete or revoke it before creating or assigning another.');
        }
    }
}
