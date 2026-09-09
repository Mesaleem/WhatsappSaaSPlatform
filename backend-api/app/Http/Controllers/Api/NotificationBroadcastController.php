<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\InAppNotification;
use App\Models\MailLog;
use App\Models\MailSetting;
use App\Models\NotificationBroadcast;
use App\Models\User;
use App\Support\TemplateRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Advanced Broadcast Engine. Dispatch is fully SYNCHRONOUS — this
 * environment's QUEUE_CONNECTION is `sync` (.env.example, confirmed
 * before writing this controller) and there is no real async queue
 * infrastructure to verify, so store() sends every email inline in a
 * try/catch loop before returning, exactly the pattern WebhookSender
 * already established for outbound HTTP calls. A large recipient list
 * will therefore make this request take proportionally longer; there is
 * no background job here to check on later.
 */
class NotificationBroadcastController extends Controller
{
    use ResolvesTenantAccount;

    private const TARGET_TYPES = ['account_users', 'specific_clients', 'all_users', 'custom_emails'];
    private const CHANNELS = ['email', 'in_app'];
    private const BROADCAST_MAILER_NAME = 'dynamic_broadcast';

    /**
     * Universal Table & Filter Standardization — search (subject) and a
     * sent_at date range, on the same additive/optional pattern every
     * other paginated list in this codebase already uses (see
     * AuditLogController::validateFilters()). No status/role filter here:
     * a broadcast row has neither field (no per-row status column, no
     * per-row role — recipients are a role-agnostic user/email list), so
     * those two toolbar slots are correctly absent rather than filtering
     * on something that doesn't exist.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $perPage = min((int) $request->integer('per_page', 15), 100);
        $account = $this->resolveAccount($request);

        $broadcasts = NotificationBroadcast::query()
            ->with(['sender:id,name', 'account:id,company_name', 'template:id,name'])
            ->when($account, fn ($q) => $q->where('account_id', $account->id))
            ->when(
                ! empty($filters['search']),
                fn ($q) => $q->where('subject', 'like', '%'.$filters['search'].'%')
            )
            ->when(
                ! empty($filters['from']),
                fn ($q) => $q->where('sent_at', '>=', Carbon::parse($filters['from'])->startOfDay())
            )
            ->when(
                ! empty($filters['to']),
                fn ($q) => $q->where('sent_at', '<=', Carbon::parse($filters['to'])->endOfDay())
            )
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json(array_merge($broadcasts->toArray(), [
            'scope' => $account ? 'account' : 'global',
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $isSuperAdmin = $user->isSuperAdmin();

        $data = $request->validate([
            'template_id' => ['nullable', 'integer', 'exists:notification_templates,id'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(self::CHANNELS)],
            'target_type' => ['required', Rule::in(self::TARGET_TYPES)],
            'account_ids' => ['required_if:target_type,specific_clients', 'array'],
            'account_ids.*' => ['integer', 'exists:accounts,id'],
            'emails' => ['required_if:target_type,custom_emails', 'array'],
            'emails.*' => ['email'],
        ]);

        // Never trust client-supplied targeting for a privilege-sensitive
        // action: a non-Super-Admin can ONLY ever broadcast to their own
        // account's users, regardless of what target_type/account_ids/
        // emails the request body claims.
        if (! $isSuperAdmin) {
            $data['target_type'] = 'account_users';
        }

        if ($data['target_type'] === 'custom_emails' && in_array('in_app', $data['channels'], true)) {
            return response()->json([
                'message' => 'The "In-App" channel requires a platform user account and cannot be used with custom email addresses. Remove the In-App channel or choose a different target.',
            ], 422);
        }

        $recipients = $this->resolveRecipients($request, $data, $isSuperAdmin);

        $subject = $data['subject'];
        $bodyTemplate = $data['body'];
        $channels = $data['channels'];

        $emailSent = 0;
        $emailFailed = 0;
        /** @var list<array{recipient_email: string, recipient_name: ?string, status: string, error_message: ?string}> */
        $mailLogRows = [];

        if (in_array('email', $channels, true)) {
            $mailSetting = MailSetting::currentCached();

            if ($mailSetting && $mailSetting->isConfigured()) {
                Config::set('mail.mailers.'.self::BROADCAST_MAILER_NAME, $mailSetting->toMailerConfig());
                $fromAddress = $mailSetting->from_address;
                $fromName = $mailSetting->from_name ?: 'WhatsApp SaaS Platform';

                foreach ($this->emailTargets($recipients) as $target) {
                    try {
                        $renderedBody = TemplateRenderer::render($bodyTemplate, [
                            'name' => $target['name'],
                            'email' => $target['email'],
                        ]);

                        Mail::mailer(self::BROADCAST_MAILER_NAME)->html($renderedBody, function ($message) use ($target, $fromAddress, $fromName, $subject) {
                            $message->to($target['email'])
                                ->from($fromAddress, $fromName)
                                ->subject($subject);
                        });

                        $emailSent++;
                        $mailLogRows[] = [
                            'recipient_email' => $target['email'],
                            'recipient_name' => $target['name'],
                            'status' => 'sent',
                            'error_message' => null,
                        ];
                    } catch (Throwable $e) {
                        $emailFailed++;
                        // Mail Log — Track Record of Mail Sends: capture
                        // the REAL failure reason (previously discarded,
                        // only an aggregate counter was incremented) so a
                        // bounced/rejected address is diagnosable without
                        // re-reading the server's own mail driver logs.
                        $mailLogRows[] = [
                            'recipient_email' => $target['email'],
                            'recipient_name' => $target['name'],
                            'status' => 'failed',
                            'error_message' => $e->getMessage(),
                        ];
                    }
                }
            } else {
                // No SMTP configured — every intended email recipient
                // counts as failed rather than silently vanishing, so the
                // broadcast summary honestly reflects that nothing sent.
                $emailFailed = $recipients['users']->count() + count($recipients['emails']);
                foreach ($this->emailTargets($recipients) as $target) {
                    $mailLogRows[] = [
                        'recipient_email' => $target['email'],
                        'recipient_name' => $target['name'],
                        'status' => 'failed',
                        'error_message' => 'No SMTP mail settings are configured for this platform.',
                    ];
                }
            }
        }

        if (in_array('in_app', $channels, true) && $recipients['users']->isNotEmpty()) {
            $now = now();
            $rows = $recipients['users']->map(fn (User $recipient) => [
                'user_id' => $recipient->id,
                'title' => $subject,
                'body' => TemplateRenderer::render($bodyTemplate, [
                    'name' => $recipient->name,
                    'email' => $recipient->email,
                ]),
                'category' => $data['category'] ?? null,
                'is_read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
        }

        $broadcast = NotificationBroadcast::create([
            'template_id' => $data['template_id'] ?? null,
            'account_id' => $recipients['account_id'],
            'subject' => $subject,
            'body' => $bodyTemplate,
            'category' => $data['category'] ?? null,
            'channels' => $channels,
            'target_type' => $data['target_type'],
            'target_summary' => $recipients['target_summary'],
            'recipient_count' => $recipients['users']->count() + count($recipients['emails']),
            'email_sent_count' => $emailSent,
            'email_failed_count' => $emailFailed,
            'sent_by' => $user->id,
            'sent_at' => now(),
        ]);

        if (isset($rows)) {
            $broadcastId = $broadcast->id;
            $rowsWithBroadcast = array_map(function (array $row) use ($broadcastId) {
                $row['broadcast_id'] = $broadcastId;

                return $row;
            }, $rows);
            foreach (array_chunk($rowsWithBroadcast, 500) as $chunk) {
                InAppNotification::insert($chunk);
            }
        }

        if (! empty($mailLogRows)) {
            $now = now();
            $mailLogRowsWithMeta = array_map(function (array $row) use ($broadcast, $now) {
                $row['broadcast_id'] = $broadcast->id;
                $row['account_id'] = $broadcast->account_id;
                $row['subject'] = $broadcast->subject;
                $row['sent_at'] = $now;
                $row['created_at'] = $now;
                $row['updated_at'] = $now;

                return $row;
            }, $mailLogRows);
            foreach (array_chunk($mailLogRowsWithMeta, 500) as $chunk) {
                MailLog::insert($chunk);
            }
        }

        return response()->json($broadcast->fresh(), 201);
    }

    /**
     * @return array{users: Collection<int, User>, emails: list<string>, account_id: ?int, target_summary: string}
     */
    private function resolveRecipients(Request $request, array $data, bool $isSuperAdmin): array
    {
        $targetType = $data['target_type'];

        if ($targetType === 'account_users') {
            $account = $this->requireAccount(
                $request,
                'Select a client/tenant account first (pass ?account_id=) before broadcasting to its users.'
            );
            $users = User::where('account_id', $account->id)->where('is_active', true)->get();

            return [
                'users' => $users,
                'emails' => [],
                'account_id' => $account->id,
                'target_summary' => 'All users of '.$account->company_name.' ('.$users->count().' users)',
            ];
        }

        if ($targetType === 'specific_clients') {
            $accountIds = $data['account_ids'];
            $users = User::whereIn('account_id', $accountIds)->where('is_active', true)->get();
            $count = count($accountIds);

            return [
                'users' => $users,
                'emails' => [],
                'account_id' => $count === 1 ? $accountIds[0] : null,
                'target_summary' => $count.' account(s) selected ('.$users->count().' users)',
            ];
        }

        if ($targetType === 'all_users') {
            $users = User::where('is_active', true)->get();

            return [
                'users' => $users,
                'emails' => [],
                'account_id' => null,
                'target_summary' => 'All platform users ('.$users->count().' users)',
            ];
        }

        // custom_emails
        $emails = array_values(array_unique($data['emails']));

        return [
            'users' => collect(),
            'emails' => $emails,
            'account_id' => null,
            'target_summary' => count($emails).' custom email address(es)',
        ];
    }

    /**
     * @param array{users: Collection<int, User>, emails: list<string>} $recipients
     * @return list<array{name: string, email: string}>
     */
    private function emailTargets(array $recipients): array
    {
        $fromUsers = $recipients['users']->map(fn (User $u) => ['name' => $u->name, 'email' => $u->email])->all();
        $fromRaw = array_map(fn (string $email) => ['name' => $email, 'email' => $email], $recipients['emails']);

        return array_merge($fromUsers, $fromRaw);
    }
}
