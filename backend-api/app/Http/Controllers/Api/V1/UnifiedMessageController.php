<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnifiedSendMessageRequest;
use App\Models\ContactGroup;
use App\Models\MessageTemplate;
use App\Services\Groups\GroupDirectMessageDispatcher;
use App\Services\Groups\GroupMessageDispatcher;
use App\Services\Templates\TemplateComponentTranslator;
use App\Services\Templates\TemplateMessageDispatcher;
use App\Services\WhatsApp\DirectMessageDispatcher;
use Illuminate\Http\JsonResponse;

/**
 * Developer API Platform for WhatsApp Group Creation & Unified
 * Messaging -- requirement 3. Authenticated by ApiAuthMiddleware
 * ('auth.apisecret'), the same dual-factor tier as
 * Api\V1\GroupController -- see that controller's and
 * ApiAuthMiddleware's own docblocks for why this is separate from the
 * pre-existing single-factor Api\V1\TemplateMessageController.
 *
 * Routing is exactly the spec's own two axes:
 *   recipient_type: 'individual' -> a direct phone number ("to"),
 *                   'group'      -> resolveGroup() below (accepts
 *                                    either this app's internal
 *                                    ContactGroup id or the raw
 *                                    WhatsApp group JID string).
 *   message_type:   'text'|'media'  -> DirectMessageDispatcher /
 *                                       GroupDirectMessageDispatcher
 *                                       (new, this feature).
 *                   'template'      -> the EXISTING
 *                                       TemplateMessageDispatcher /
 *                                       GroupMessageDispatcher (same
 *                                       classes Api\V1\
 *                                       TemplateMessageController
 *                                       already uses) -- template_name
 *                                       is resolved to a MessageTemplate
 *                                       (matched against `title`, this
 *                                       model's actual column -- see
 *                                       that model's docblock; the spec's
 *                                       literal "template_name" has no
 *                                       matching column, disclosed
 *                                       translation) and
 *                                       template.components is turned
 *                                       into the flat `variables` array
 *                                       those dispatchers already expect
 *                                       via TemplateComponentTranslator
 *                                       (see its own docblock for the
 *                                       positional-mapping caveat).
 *
 * Engine selection (Meta Cloud API vs QR/Baileys) is NOT decided here --
 * every dispatcher this controller calls already resolves it via
 * WhatsAppEngineFactory::make($account), matching the spec's "dispatch
 * via Meta Cloud API or QR engine based on account configuration"
 * requirement without a second, parallel engine-selection switch in
 * this controller.
 */
class UnifiedMessageController extends Controller
{
    /** POST /api/v1/whatsapp/messages/send */
    public function send(UnifiedSendMessageRequest $request): JsonResponse
    {
        $accountId = (int) $request->attributes->get('api_account_id');
        $apiKeyId = $request->attributes->get('api_key')?->id;
        $data = $request->validated();

        return $data['recipient_type'] === 'group'
            ? $this->sendToGroup($accountId, $apiKeyId, $data)
            : $this->sendToIndividual($accountId, $apiKeyId, $data);
    }

    private function sendToIndividual(int $accountId, ?int $apiKeyId, array $data): JsonResponse
    {
        if ($data['message_type'] === 'template') {
            $resolved = $this->resolveTemplate($accountId, $data['template']);

            if ($resolved instanceof JsonResponse) {
                return $resolved;
            }

            [$template, $variables] = $resolved;

            $result = TemplateMessageDispatcher::dispatch(
                $accountId,
                $template->id,
                $data['to'],
                $variables,
                source: 'api',
                apiKeyId: $apiKeyId,
            );

            return match ($result['status']) {
                'sent' => response()->json(['success' => true, 'dispatch_id' => $result['dispatch_log_id'] ?? null, 'queued_recipients_count' => 1]),
                'missing_variables' => response()->json(['success' => false, 'error_code' => 'INVALID_VARIABLES', 'message' => $result['message'], 'missing' => $result['missing']], 422),
                'not_found' => response()->json(['success' => false, 'error_code' => 'TEMPLATE_NOT_APPROVED', 'message' => $result['message']], 404),
                'disconnected' => response()->json(['success' => false, 'error_code' => 'WHATSAPP_DISCONNECTED', 'message' => $result['message']], 422),
                'quota_exhausted' => response()->json(['success' => false, 'error_code' => 'INSUFFICIENT_QUOTA', 'message' => $result['message']], 402),
                default => response()->json(['success' => false, 'error_code' => 'SEND_FAILED', 'message' => $result['message'] ?? 'Could not send this message.'], 422),
            };
        }

        $content = $data['message_type'] === 'text' ? $data['text'] : $data['media'];

        $result = DirectMessageDispatcher::dispatch(
            $accountId,
            $data['to'],
            $data['message_type'],
            $content,
            source: 'api',
            apiKeyId: $apiKeyId,
        );

        return match ($result['status']) {
            'sent' => response()->json(['success' => true, 'dispatch_id' => $result['dispatch_log_id'] ?? null, 'queued_recipients_count' => 1]),
            'not_found' => response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => $result['message']], 404),
            'disconnected' => response()->json(['success' => false, 'error_code' => 'WHATSAPP_DISCONNECTED', 'message' => $result['message']], 422),
            'quota_exhausted' => response()->json(['success' => false, 'error_code' => 'INSUFFICIENT_QUOTA', 'message' => $result['message']], 402),
            default => response()->json(['success' => false, 'error_code' => 'SEND_FAILED', 'message' => $result['message'] ?? 'Could not send this message.'], 422),
        };
    }

    private function sendToGroup(int $accountId, ?int $apiKeyId, array $data): JsonResponse
    {
        $group = $this->resolveGroup($accountId, $data['group_id']);

        if (! $group) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Contact group not found.'], 404);
        }

        if ($data['message_type'] === 'template') {
            $resolved = $this->resolveTemplate($accountId, $data['template']);

            if ($resolved instanceof JsonResponse) {
                return $resolved;
            }

            [$template, $variables] = $resolved;

            $result = GroupMessageDispatcher::dispatch(
                $accountId,
                $group->id,
                $template->id,
                $variables,
                source: 'api',
                apiKeyId: $apiKeyId,
            );
        } else {
            $content = $data['message_type'] === 'text' ? $data['text'] : $data['media'];

            $result = GroupDirectMessageDispatcher::dispatch(
                $accountId,
                $group->id,
                $data['message_type'],
                $content,
                source: 'api',
                apiKeyId: $apiKeyId,
            );
        }

        return match ($result['status']) {
            'queued' => response()->json([
                'success' => true,
                'dispatch_id' => $result['dispatch_id'],
                'queued_recipients_count' => $result['queued_recipients_count'],
            ]),
            'group_access_denied' => response()->json(['success' => false, 'error_code' => 'GROUP_ACCESS_DENIED', 'message' => $result['message']], 403),
            'empty_group' => response()->json(['success' => false, 'error_code' => 'EMPTY_GROUP', 'message' => $result['message']], 422),
            'template_not_approved' => response()->json(['success' => false, 'error_code' => 'TEMPLATE_NOT_APPROVED', 'message' => $result['message']], 422),
            'not_found' => response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => $result['message']], 404),
            'disconnected' => response()->json(['success' => false, 'error_code' => 'WHATSAPP_DISCONNECTED', 'message' => $result['message']], 422),
            'group_not_synced' => response()->json(['success' => false, 'error_code' => 'GROUP_NOT_SYNCED', 'message' => $result['message']], 422),
            'unsupported_engine' => response()->json(['success' => false, 'error_code' => 'UNSUPPORTED_ENGINE', 'message' => $result['message']], 422),
            'quota_exhausted' => response()->json(['success' => false, 'error_code' => 'INSUFFICIENT_QUOTA', 'message' => $result['message']], 402),
            'insufficient_quota' => response()->json([
                'success' => false,
                'error_code' => 'INSUFFICIENT_QUOTA',
                'message' => "This group dispatch requires {$result['required']} credits, but your account only has {$result['remaining']} remaining credits.",
            ], 402),
            default => response()->json(['success' => false, 'error_code' => 'SEND_FAILED', 'message' => $result['message'] ?? 'Could not queue this group dispatch.'], 422),
        };
    }

    /**
     * Accepts either this app's internal ContactGroup id (numeric) or the
     * raw WhatsApp group JID string (e.g. "120363xxx@g.us") -- the
     * spec's own literal "WhatsApp Group JID / Group ID" wording. Always
     * re-scoped to $accountId, same tenant-isolation guarantee every
     * other ContactGroup lookup in this codebase already enforces.
     */
    private function resolveGroup(int $accountId, string $groupIdOrJid): ?ContactGroup
    {
        if (ctype_digit($groupIdOrJid)) {
            return ContactGroup::where('account_id', $accountId)->find((int) $groupIdOrJid);
        }

        return ContactGroup::where('account_id', $accountId)->where('wa_group_jid', $groupIdOrJid)->first();
    }

    /**
     * Resolves template.name against the SAME approvedFor($accountId)
     * scope every other template-sending entry point in this codebase
     * already uses, matched against MessageTemplate.title (see that
     * model's docblock -- "template_name" has no literal matching
     * column), then translates template.components into this app's flat
     * `variables` array via TemplateComponentTranslator.
     *
     * @param array{name: string, components?: array} $templatePayload
     * @return array{0: MessageTemplate, 1: array<string, string>}|JsonResponse
     */
    private function resolveTemplate(int $accountId, array $templatePayload): array|JsonResponse
    {
        $template = MessageTemplate::query()
            ->approvedFor($accountId)
            ->where('title', $templatePayload['name'])
            ->first();

        if (! $template) {
            return response()->json([
                'success' => false,
                'error_code' => 'TEMPLATE_NOT_APPROVED',
                'message' => "No approved template named \"{$templatePayload['name']}\" is available to this account.",
            ], 404);
        }

        $variables = TemplateComponentTranslator::toVariables($template, $templatePayload['components'] ?? []);

        return [$template, $variables];
    }
}
