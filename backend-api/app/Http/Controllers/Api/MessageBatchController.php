<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\MessageBatch;
use App\Models\MessageTemplate;
use App\Services\Batches\BatchException;
use App\Services\Batches\MessageBatchService;
use App\Services\Batches\RecipientFileParser;
use App\Services\Scheduling\ScheduledMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Batch sends from an Excel/CSV list, on the Send Notification page. Session-authenticated (the alerts route group),
 * so it is not part of the Developer API: the /api/v1 routes never reach it.
 */
class MessageBatchController extends Controller
{
    use ResolvesTenantAccount;

    public function __construct(private readonly MessageBatchService $batches, private readonly RecipientFileParser $parser)
    {
    }

    /** GET /api/alerts/message-batches — this account's latest batches, newest first. */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account first (pass ?account_id=).');

        $rows = MessageBatch::query()
            ->where('account_id', $account->id)
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (MessageBatch $batch) => $this->present($batch));

        return response()->json(['data' => $rows]);
    }

    /** POST /api/alerts/message-batches — parses the file and creates the batch (sent now, or at the scheduled time). */
    public function store(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account first (pass ?account_id=).');

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:5120'],
            'template_id' => ['required', 'integer'],
            'variables' => ['nullable', 'array'],
            'media_url' => ['nullable', 'string', 'max:2048'],
            'title' => ['nullable', 'string', 'max:120'],
            'batch_size' => ['required', 'integer', 'min:'.MessageBatch::MIN_BATCH_SIZE, 'max:'.MessageBatch::MAX_BATCH_SIZE],
            'interval_minutes' => ['required', 'integer', 'min:1', 'max:'.MessageBatch::MAX_INTERVAL_MINUTES],
            'scheduled_at' => ['nullable', 'date'],
            // The numbers to send from, in turn: the 1st message from the 1st number, the 2nd from the 2nd, and so on.
            'sender_number_ids' => ['required', 'array', 'min:1', 'max:10'],
            'sender_number_ids.*' => ['integer', 'distinct'],
        ]);

        $template = MessageTemplate::query()->approvedFor($account->id)->whereKey((int) $data['template_id'])->first();
        if (! $template) {
            return response()->json(['message' => 'Choose an approved template for this account.', 'error_code' => 'TEMPLATE_NOT_APPROVED'], 422);
        }

        $file = $request->file('file');

        try {
            $parsed = $this->parser->parse($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => 'FILE_UNREADABLE'], 422);
        }

        // Each chosen number must be this account's linked, active number. Anything else refuses the whole batch.
        try {
            $senders = [];
            foreach ($data['sender_number_ids'] as $senderId) {
                $senders[] = app(\App\Services\WhatsApp\SenderNumberResolver::class)->resolve($account, (int) $senderId);
            }
        } catch (\App\Services\WhatsApp\SenderNumberException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
        }

        try {
            $batch = $this->batches->create($account, $request->user(), $parsed, $file->getClientOriginalName(), [
                'sender_number_ids' => $senders,
                'template_id' => $template->id,
                'variables' => $data['variables'] ?? [],
                'media_url' => $data['media_url'] ?? null,
                'title' => $data['title'] ?? null,
                'batch_size' => $data['batch_size'],
                'interval_minutes' => $data['interval_minutes'],
                'scheduled_at' => ScheduledMessageService::parseSendAt($data['scheduled_at'] ?? null),
            ]);
        } catch (BatchException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
        }

        return response()->json([
            'message' => sprintf(
                '%d numbers ready%s. %d invalid row(s) skipped.',
                $batch->total,
                $batch->scheduled_at ? ', scheduled for '.$batch->scheduled_at->copy()->timezone('Asia/Kolkata')->format('d M Y, h:i A').' IST' : '',
                $parsed['invalid'],
            ),
            'data' => $this->present($batch),
        ], 201);
    }

    /** POST /api/alerts/message-batches/{id}/pause */
    public function pause(Request $request, int $id): JsonResponse
    {
        return $this->act($request, $id, fn ($account) => $this->batches->pause($account, $id));
    }

    /** POST /api/alerts/message-batches/{id}/resume */
    public function resume(Request $request, int $id): JsonResponse
    {
        return $this->act($request, $id, fn ($account) => $this->batches->resume($account, $id));
    }

    /** POST /api/alerts/message-batches/{id}/stop */
    public function stop(Request $request, int $id): JsonResponse
    {
        return $this->act($request, $id, fn ($account) => $this->batches->stop($account, $id));
    }

    private function act(Request $request, int $id, \Closure $action): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account first (pass ?account_id=).');

        try {
            $batch = $action($account);
        } catch (BatchException $e) {
            return response()->json(['message' => $e->getMessage(), 'error_code' => $e->errorCode], $e->status);
        }

        return response()->json(['data' => $this->present($batch)]);
    }

    /** @return array<string, mixed> */
    private function present(MessageBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'title' => $batch->title,
            'sender_number_ids' => $batch->sender_number_ids ?? [],
            'sender_numbers' => $this->senderPhones($batch),
            'kind' => $batch->kind,
            'source_filename' => $batch->source_filename,
            'status' => $batch->status,
            'total' => $batch->total,
            'sent_count' => $batch->sent_count,
            'failed_count' => $batch->failed_count,
            'pending_count' => max(0, $batch->total - $batch->sent_count - $batch->failed_count - $this->cancelledCount($batch)),
            'next_send_at' => $this->openTime($batch, 'min'),
            'finishes_at' => $this->openTime($batch, 'max'),
            'batch_size' => $batch->batch_size,
            'interval_minutes' => $batch->interval_minutes,
            'scheduled_at' => $batch->scheduled_at?->toIso8601String(),
            'next_chunk_at' => $batch->next_chunk_at?->toIso8601String(),
            'stop_reason' => $batch->stop_reason,
            'created_at' => $batch->created_at?->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
        ];
    }

    /** The earliest or latest planned time of the numbers still waiting, in ISO format; null when none wait. */
    private function openTime(MessageBatch $batch, string $which): ?string
    {
        $query = $batch->items()->where('status', 'pending');
        $value = $which === 'min' ? $query->min('send_at') : $query->max('send_at');

        return $value ? \Illuminate\Support\Carbon::parse($value, 'UTC')->toIso8601String() : null;
    }

    /** The phone numbers this run sent from, in turn order. */
    private function senderPhones(MessageBatch $batch): array
    {
        $ids = array_values($batch->sender_number_ids ?? []);
        if ($ids === []) {
            return [];
        }

        $phones = \App\Models\WhatsAppNumber::query()->whereIn('id', $ids)->pluck('phone_number', 'id');

        return array_values(array_filter(array_map(fn ($id) => $phones[$id] ?? null, $ids)));
    }

    private function cancelledCount(MessageBatch $batch): int
    {
        return $batch->items()->where('status', 'cancelled')->count();
    }
}
