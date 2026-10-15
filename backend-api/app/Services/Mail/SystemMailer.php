<?php

namespace App\Services\Mail;

use App\Http\Controllers\Api\PublicSiteController;
use App\Models\Account;
use App\Models\InAppNotification;
use App\Models\MailLog;
use App\Models\MailSetting;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Auth\AccountMailer;
use App\Support\TemplateRenderer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends the platform's automatic emails from the master templates.
 *
 * The template is the notification_templates row with that slug (editable
 * by the Super Admin in Broadcast & Notifications → Mail Templates). When the
 * row is missing or switched off, the built-in default from
 * SystemEmailCatalog is used instead, so an automatic email is never lost.
 *
 * Variables: values are HTML-escaped before they go into the body; pass
 * already-safe HTML (e.g. nl2br(e($text))) in $html instead. The subject gets
 * the plain values. Every send is best effort (never throws) and is written
 * to mail_logs with its template slug.
 */
class SystemMailer
{
    public function __construct(private readonly AccountMailer $mailer) {}

    /**
     * Render a template. Public so wording can be checked without sending.
     *
     * @param array<string, scalar|null> $vars plain values (escaped in the body)
     * @param array<string, string> $html trusted HTML values
     * @return array{0: string, 1: string} [subject, html body]
     */
    public function render(string $slug, array $vars = [], array $html = []): array
    {
        [$subject, $body] = $this->source($slug);

        $all = $this->globals() + array_map(fn ($v) => (string) ($v ?? ''), $vars);

        $renderedSubject = trim(str_replace(["\r", "\n"], ' ', TemplateRenderer::render($subject, $all)));
        $renderedBody = TemplateRenderer::render($body, array_map(fn (string $v) => e($v), $all) + $html);

        return [$renderedSubject, $renderedBody];
    }

    /** Send one email now. Returns false when mail is not set up or sending failed. */
    public function send(string $slug, string $to, array $vars = [], array $html = [], ?int $accountId = null, ?string $recipientName = null): bool
    {
        if ($to === '') {
            return false;
        }

        try {
            [$subject, $body] = $this->render($slug, $vars, $html);
        } catch (\Throwable $e) {
            Log::warning('System email not rendered.', ['template' => $slug, 'error' => $e->getMessage()]);

            return false;
        }

        $sent = $this->mailer->sendHtml($to, $subject, $body);

        $this->log($slug, $to, $recipientName, $subject, $sent, $accountId);

        return $sent;
    }

    /**
     * "Send test email" from the template editor: the given (possibly unsaved)
     * subject and body, every {{variable}} filled with a sample value.
     */
    public function sendTest(string $to, string $subject, string $body, ?string $recipientName = null): bool
    {
        $vars = $this->globals() + SystemEmailCatalog::SAMPLE_VALUES + ['name' => $recipientName ?: 'Jane Doe', 'email' => $to];
        // Any variable without a sample shows as [variable], so a typo is visible in the test email.
        $fill = fn (string $text, bool $escape) => (string) preg_replace_callback(
            '/\{\{\s*([^{}]*?)\s*\}\}/',
            static fn (array $m): string => array_key_exists($m[1], $vars) ? ($escape ? e((string) $vars[$m[1]]) : (string) $vars[$m[1]]) : '['.$m[1].']',
            $text,
        );

        $renderedSubject = '[Test] '.trim(str_replace(["\r", "\n"], ' ', $fill($subject, false)));
        $sent = $this->mailer->sendHtml($to, $renderedSubject, $fill($body, true));
        $this->log('test', $to, $recipientName, $renderedSubject, $sent, null);

        return $sent;
    }

    /**
     * Send after the current transaction commits and after the response is
     * sent, so a slow mail server never delays the request and a rolled-back
     * change never emails anyone.
     */
    public function sendLater(string $slug, string $to, array $vars = [], array $html = [], ?int $accountId = null, ?string $recipientName = null): void
    {
        DB::afterCommit(function () use ($slug, $to, $vars, $html, $accountId, $recipientName) {
            defer(fn () => $this->send($slug, $to, $vars, $html, $accountId, $recipientName));
        });
    }

    /** Send the same template to several users (e.g. every Super Admin). */
    public function sendToUsers(string $slug, iterable $users, array $vars = [], array $html = []): void
    {
        foreach ($users as $user) {
            if ($user->email) {
                $this->sendLater($slug, $user->email, $vars, $html, null, $user->name);
            }
        }
    }

    /** In-app notification for each user. */
    public function notifyUsers(iterable $users, string $title, string $body, string $category, ?string $link = null): void
    {
        foreach ($users as $user) {
            InAppNotification::query()->create([
                'user_id' => $user->id,
                'title' => mb_substr($title, 0, 255),
                'body' => mb_substr($body, 0, 1000),
                'category' => $category,
                'link' => $link,
                'is_read' => false,
            ]);
        }
    }

    /** @return Collection<int, User> active Super Admins */
    public function superAdmins(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->get();
    }

    /**
     * Who reviews a client's requests: every Super Admin, plus the users of
     * the client's Agent when it has one.
     *
     * @return Collection<int, User>
     */
    public function reviewersFor(Account $account): Collection
    {
        $reviewers = $this->superAdmins();

        if ($account->agent_id !== null) {
            $agents = User::query()
                ->where('account_id', $account->agent_id)
                ->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('name', 'agent'))
                ->get();
            $reviewers = $reviewers->merge($agents);
        }

        return $reviewers->unique('id')->values();
    }

    /** "Your Agent" / "The Super Admin" — who reviews this client's requests. */
    public static function reviewerLabel(Account $account): string
    {
        return $account->agent_id !== null ? 'Your Agent' : 'The Super Admin';
    }

    public function url(string $path = ''): string
    {
        return $this->mailer->frontendUrl($path);
    }

    /** @return array{0: string, 1: string} the template's subject and body (database row, else built-in) */
    private function source(string $slug): array
    {
        $row = NotificationTemplate::query()->where('slug', $slug)->where('is_system', true)->first();

        if ($row && $row->is_active && trim((string) $row->body) !== '' && trim((string) $row->subject) !== '') {
            return [(string) $row->subject, (string) $row->body];
        }

        $default = SystemEmailCatalog::get($slug);
        if ($default === null) {
            throw new \InvalidArgumentException("Unknown system email template [{$slug}].");
        }

        return [$default['subject'], $default['body']];
    }

    /** @return array<string, string> */
    private function globals(): array
    {
        $support = (string) (PublicSiteController::brand('support_email') ?: (MailSetting::currentCached()?->from_address ?? ''));

        return [
            'brand' => $this->mailer->brand(),
            'app_url' => $this->mailer->frontendUrl('/'),
            'support_email' => $support !== '' ? $support : 'our support team',
            'year' => now()->format('Y'),
        ];
    }

    private function log(string $slug, string $to, ?string $name, string $subject, bool $sent, ?int $accountId): void
    {
        // A one-time code or reset link never goes into the log; the template name is logged instead.
        $default = SystemEmailCatalog::get($slug);
        if (! empty($default['sensitive'])) {
            $subject = $default['name'];
        }

        try {
            MailLog::query()->create([
                'template_slug' => $slug,
                'account_id' => $accountId,
                'recipient_email' => $to,
                'recipient_name' => $name,
                'subject' => mb_substr($subject, 0, 255),
                'status' => $sent ? 'sent' : 'failed',
                'error_message' => $sent ? null : 'Not sent: mail settings are missing or the mail server refused it (see the application log).',
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Mail log not written.', ['template' => $slug, 'error' => $e->getMessage()]);
        }
    }
}
