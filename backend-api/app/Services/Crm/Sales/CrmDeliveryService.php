<?php

namespace App\Services\Crm\Sales;

use App\Models\Contact;
use App\Models\CrmDocument;
use App\Models\CrmDocumentDelivery;
use App\Models\MailSetting;
use App\Services\WhatsApp\DirectMessageDispatcher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 4 — sending a document, and its history.
 *
 * WhatsApp goes through the existing DirectMessageDispatcher (the tenant's own
 * connected number, QR or Meta — the engine itself is not touched) as a PDF document
 * fetched from the document's secure public link (an unguessable token; never an id).
 * Email uses the platform's configured mailer (MailSetting) with the PDF attached and
 * the tenant's business name as the sender name. Manual records what staff sent
 * outside the platform. Each attempt is its own delivery row: `sent` means the
 * provider accepted it — this never claims "delivered".
 *
 * A contact who opted out (contacts.opted_out_at) is never messaged automatically or
 * by this service.
 */
class CrmDeliveryService
{
    public function __construct(private readonly CrmDocumentPdf $pdf)
    {
    }

    /** The document's secure PDF link (also what WhatsApp fetches). */
    public function pdfUrl(CrmDocument $doc): string
    {
        return rtrim((string) config('app.url'), '/').'/api/public/crm-documents/'.$doc->public_token.'/pdf';
    }

    /** The customer-facing page to view and pay the document. */
    public function viewUrl(CrmDocument $doc): string
    {
        return rtrim((string) (config('services.frontend.url') ?: config('app.url')), '/').'/d/'.$doc->public_token;
    }

    /** QA fixes (2026-10-14): whether the account's plan can send WhatsApp at all (the CRM-only plan cannot). */
    public static function canUseWhatsApp(int $accountId): bool
    {
        $account = \App\Models\Account::query()->with('currentSubscription')->find($accountId);
        $engine = $account?->currentSubscription?->engine_type;

        return $account !== null && in_array($engine, ['qr', 'meta'], true);
    }

    public function sendWhatsApp(CrmDocument $doc, ?string $phone, ?string $message, ?int $actorUserId, string $purpose = 'document', ?int $reminderId = null): CrmDocumentDelivery
    {
        $this->assertSendable($doc);
        // QA fixes (2026-10-14): a plan without WhatsApp sending gets a plain answer, not the
        // engine's internal "no WhatsApp engine configured" text.
        if (! self::canUseWhatsApp((int) $doc->account_id)) {
            throw ValidationException::withMessages(['channel' => ['Your plan does not include WhatsApp sending. Send it by email instead.']]);
        }
        $phone = $phone ?: ($doc->customer_snapshot['phone'] ?? $doc->contact?->phone_number);
        if (! $phone) {
            throw ValidationException::withMessages(['recipient' => ['This customer has no phone number. Enter one to send on WhatsApp.']]);
        }
        $this->assertNotOptedOut($doc, $phone);

        $caption = $message ?: $this->defaultMessage($doc);
        $result = DirectMessageDispatcher::dispatch($doc->account_id, $phone, 'media', [
            'media_type' => 'document',
            'url' => $this->pdfUrl($doc),
            'filename' => $this->pdf->filename($doc),
            'caption' => $caption,
        ], 'crm');

        $ok = ($result['status'] ?? null) === 'sent';

        return CrmDocumentDelivery::create([
            'account_id' => $doc->account_id,
            'crm_document_id' => $doc->id,
            'channel' => 'whatsapp',
            'purpose' => $purpose,
            'recipient' => $phone,
            'status' => $ok ? 'sent' : 'failed',
            'provider_message_id' => isset($result['dispatch_log_id']) ? (string) $result['dispatch_log_id'] : null,
            'error' => $ok ? null : mb_substr((string) ($result['message'] ?? $result['status'] ?? 'Not sent.'), 0, 500),
            'sent_by_user_id' => $actorUserId,
            'crm_payment_reminder_id' => $reminderId,
            'sent_at' => $ok ? now() : null,
        ]);
    }

    public function sendEmail(CrmDocument $doc, ?string $to, ?string $subject, ?string $message, ?int $actorUserId, string $purpose = 'document', ?int $reminderId = null): CrmDocumentDelivery
    {
        $this->assertSendable($doc);
        $to = $to ?: ($doc->customer_snapshot['email'] ?? $doc->contact?->email);
        if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['recipient' => ['Enter a valid email address for this customer.']]);
        }
        if ($doc->contact?->opted_out_at !== null) {
            throw ValidationException::withMessages(['recipient' => ['This contact has opted out of messages.']]);
        }

        $seller = (string) ($doc->seller_snapshot['name'] ?? 'Your supplier');
        $subject = $subject ?: $this->label($doc).' '.$doc->number.' from '.$seller;
        $body = nl2br(e($message ?: $this->defaultMessage($doc)));
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#0f172a">'.$body
            .'<p><a href="'.e($this->viewUrl($doc)).'">View '.e(strtolower($this->label($doc))).' online</a></p></div>';

        $error = null;
        $setting = MailSetting::currentCached();
        if (! $setting || ! $setting->isConfigured()) {
            $error = 'Email is not set up on this platform.';
        } else {
            try {
                Config::set('mail.mailers.crm_documents', $setting->toMailerConfig());
                $replyTo = $doc->seller_snapshot['email'] ?? null;
                $bytes = $this->pdf->render($doc);
                $filename = $this->pdf->filename($doc);
                Mail::mailer('crm_documents')->html($html, function ($m) use ($to, $setting, $seller, $subject, $replyTo, $bytes, $filename) {
                    $m->to($to)->from($setting->from_address, $seller)->subject($subject)->attachData($bytes, $filename, ['mime' => 'application/pdf']);
                    if ($replyTo) {
                        $m->replyTo($replyTo, $seller);
                    }
                });
            } catch (\Throwable $e) {
                Log::warning('CrmDeliveryService: email failed.', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
                $error = 'The email could not be sent.';
            }
        }

        return CrmDocumentDelivery::create([
            'account_id' => $doc->account_id,
            'crm_document_id' => $doc->id,
            'channel' => 'email',
            'purpose' => $purpose,
            'recipient' => $to,
            'status' => $error ? 'failed' : 'sent',
            'error' => $error,
            'sent_by_user_id' => $actorUserId,
            'crm_payment_reminder_id' => $reminderId,
            'sent_at' => $error ? null : now(),
        ]);
    }

    /** Staff sent it themselves (their own phone, by hand...): recorded as such, never as "sent by the system". */
    public function recordManual(CrmDocument $doc, string $how, string $recipient, ?int $actorUserId): CrmDocumentDelivery
    {
        $this->assertSendable($doc);

        return CrmDocumentDelivery::create([
            'account_id' => $doc->account_id,
            'crm_document_id' => $doc->id,
            'channel' => 'manual',
            'recipient' => mb_substr($how.': '.$recipient, 0, 191),
            'status' => 'recorded',
            'sent_by_user_id' => $actorUserId,
            'sent_at' => now(),
        ]);
    }

    public function defaultMessage(CrmDocument $doc, bool $reminder = false): string
    {
        $seller = (string) ($doc->seller_snapshot['name'] ?? '');
        $name = (string) ($doc->customer_snapshot['contact_name'] ?? $doc->customer_snapshot['name'] ?? '');
        $hello = $name !== '' ? "Hello {$name}," : 'Hello,';

        if ($reminder) {
            return "{$hello}\nThis is a friendly reminder that invoice {$doc->number} has a balance of INR {$doc->balance_due}"
                .($doc->due_date ? ' due on '.$doc->due_date->format('d M Y') : '').".\nView and pay: {$this->viewUrl($doc)}\n- {$seller}";
        }

        $line = match ($doc->type) {
            CrmDocument::TYPE_INVOICE => "Please find invoice {$doc->number} for INR {$doc->grand_total}".($doc->due_date ? ', due on '.$doc->due_date->format('d M Y') : '').'.',
            default => "Please find {$this->label($doc)} {$doc->number}".(in_array($doc->type, ['quotation', 'proposal'], true) && $doc->valid_until ? ', valid until '.$doc->valid_until->format('d M Y') : '').'.',
        };

        return "{$hello}\n{$line}\nView online: {$this->viewUrl($doc)}\n- {$seller}";
    }

    private function assertSendable(CrmDocument $doc): void
    {
        if ($doc->number === null || $doc->isEditable() || in_array($doc->status, ['void', 'cancelled'], true)) {
            throw ValidationException::withMessages(['document' => ['Issue the document before sending it.']]);
        }
    }

    private function assertNotOptedOut(CrmDocument $doc, string $phone): void
    {
        $optedOut = $doc->contact?->opted_out_at !== null
            || Contact::query()->forAccount($doc->account_id)->where('phone_number', \App\Support\PhoneNumberNormalizer::normalize($phone))->whereNotNull('opted_out_at')->exists();
        if ($optedOut) {
            throw ValidationException::withMessages(['recipient' => ['This contact has opted out of messages.']]);
        }
    }

    private function label(CrmDocument $doc): string
    {
        return match ($doc->type) {
            CrmDocument::TYPE_SALES_ORDER => 'Sales order',
            CrmDocument::TYPE_CREDIT_NOTE => 'Credit note',
            CrmDocument::TYPE_CUSTOMER_PO => 'Purchase order',
            default => ucfirst($doc->type),
        };
    }
}
