<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Services\Batches\BatchException;
use App\Services\Batches\MessageBatchService;
use App\Services\Batches\RecipientFileParser;
use App\Services\WhatsApp\SenderNumberException;
use App\Services\WhatsApp\SenderNumberResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bulk send from the Send Notification page: the numbers typed in the bulk box (up to 30), sent from the numbers the user
 * ticked, in turn. It is stored as a batch run (kind 'bulk'), so it follows the same spacing and shows in the same history
 * with the numbers it used.
 */
class BulkSendController extends Controller
{
    use ResolvesTenantAccount;

    public const MAX_NUMBERS = 30;

    public function __construct(
        private readonly MessageBatchService $batches,
        private readonly SenderNumberResolver $senders,
        private readonly RecipientFileParser $parser,
    ) {
    }

    /** POST /api/alerts/bulk-sends */
    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account first (pass ?account_id=).');

        $data = $request->validate([
            'recipient_phones' => ['required', 'array', 'min:1', 'max:'.self::MAX_NUMBERS],
            'recipient_phones.*' => ['required', 'string', 'max:20'],
            // Either a template, or free text (optionally with media) — the same "No template" choice offered elsewhere.
            'template_id' => ['nullable', 'integer'],
            'message_text' => ['nullable', 'string', 'max:4096'],
            'variables' => ['nullable', 'array'],
            'media_url' => ['nullable', 'string', 'max:2048'],
            'sender_number_ids' => ['required', 'array', 'min:1', 'max:10'],
            'sender_number_ids.*' => ['integer', 'distinct'],
            'scheduled_at' => ['nullable', 'date'],
        ], [
            'recipient_phones.max' => 'Bulk allows up to '.self::MAX_NUMBERS.' numbers. For more, use Bulk in the dashboard and upload an Excel or CSV file.',
        ]);

        $template = null;
        if (isset($data['template_id'])) {
            $template = MessageTemplate::query()->approvedFor($account->id)->whereKey((int) $data['template_id'])->first();
            if (! $template) {
                return response()->json(['message' => 'Choose an approved template for this account.', 'error_code' => 'TEMPLATE_NOT_APPROVED'], 422);
            }
        } elseif (trim((string) ($data['message_text'] ?? '')) === '' && trim((string) ($data['media_url'] ?? '')) === '') {
            return response()->json(['message' => 'Choose a template, or write a message or attach a media URL.', 'error_code' => 'TEMPLATE_OR_TEXT_REQUIRED'], 422);
        }

        // Every typed number must be a phone number; a bad one is named, so the user can fix it before anything is sent.
        $phones = [];
        $bad = [];
        foreach ($data['recipient_phones'] as $raw) {
            $phone = $this->parser->normalize(trim((string) $raw));
            if ($phone === null) {
                $bad[] = trim((string) $raw);
            } else {
                $phones[$phone] = true;
            }
        }
        if ($bad !== []) {
            return response()->json([
                'message' => 'These are not phone numbers (10 to 15 digits): '.implode(', ', $bad).'.',
                'error_code' => 'INVALID_NUMBERS',
            ], 422);
        }

        try {
            $senders = [];
            foreach ($data['sender_number_ids'] as $senderId) {
                $senders[] = $this->senders->resolve($account, (int) $senderId);
            }
        } catch (SenderNumberException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
        }

        $numbers = array_map('strval', array_keys($phones));

        try {
            $batch = $this->batches->create($account, $request->user(), ['phones' => $numbers, 'invalid' => 0, 'rows' => count($numbers)], '', [
                'kind' => 'bulk',
                'title' => 'Bulk: '.count($numbers).' '.(count($numbers) === 1 ? 'number' : 'numbers'),
                'template_id' => $template?->id,
                'message_text' => $data['message_text'] ?? null,
                'sender_number_ids' => $senders,
                'variables' => $data['variables'] ?? [],
                'media_url' => $data['media_url'] ?? null,
                // Up to MAX_NUMBERS numbers fit in one batch, so the interval between batches never applies here.
                'batch_size' => self::MAX_NUMBERS,
                'interval_minutes' => 0,
                'scheduled_at' => \App\Services\Scheduling\ScheduledMessageService::parseSendAt($data['scheduled_at'] ?? null),
            ]);
        } catch (BatchException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
        }

        return response()->json([
            'message' => count($numbers).' '.(count($numbers) === 1 ? 'number' : 'numbers').' queued, sent from '.count($senders).' '.(count($senders) === 1 ? 'number' : 'numbers').' in turn.',
            'data' => ['id' => $batch->id, 'status' => $batch->status],
        ], 201);
    }
}
