<?php

namespace App\Services\Crm\Sales;

use App\Models\Account;
use App\Models\CrmPayment;
use App\Models\MailSetting;
use App\Services\Pdf\SimplePdfWriter;
use App\Services\WhatsApp\DirectMessageDispatcher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * CRM Sales Phase 3 — the payment receipt: a PDF (existing SimplePdfWriter layout) and
 * sending it. Email attaches the PDF; WhatsApp sends a short text confirmation from the
 * tenant's connected number (a receipt has no public link of its own, by design). A
 * reversed payment has no receipt. A contact who opted out is never messaged.
 */
class CrmReceiptService
{
    public function __construct(private readonly CrmDocumentService $documents)
    {
    }

    public function pdf(CrmPayment $payment): string
    {
        $account = Account::query()->findOrFail($payment->account_id);
        $profile = $this->documents->profileFor($account);
        $money = static fn ($v): string => number_format((float) $v, 2);
        $payment->loadMissing(['allocations.document', 'contact', 'company']);

        $active = $payment->allocations->whereNull('reversed_at');

        return SimplePdfWriter::renderInvoice([
            'company' => (string) ($profile->legal_name ?: $account->company_name),
            'subtitle' => $profile->gstin ? 'GSTIN '.$profile->gstin : '',
            'accent' => '#0F766E',
            'title' => 'PAYMENT RECEIPT',
            'number' => $payment->number,
            'issued_on' => $payment->paid_on?->format('d M Y') ?? '-',
            'status' => $payment->reversed_at ? 'REVERSED' : 'RECEIVED',
            'status_color' => $payment->reversed_at ? '#DC2626' : '#059669',
            'billed_to' => array_values(array_filter([$payment->company?->name, $payment->contact?->name, $payment->contact?->phone_number])),
            'details' => array_values(array_filter([
                ['Method', ucwords(str_replace('_', ' ', $payment->method))],
                $payment->reference ? ['Reference', $payment->reference] : null,
            ])),
            'items' => $active->map(fn ($a) => ['Towards '.($a->document?->number ?? 'invoice'), '1', $money($a->amount), $money($a->amount)])->values()->all()
                ?: [['Advance / on account', '1', $money($payment->amount), $money($payment->amount)]],
            'subtotal' => 'INR '.$money($payment->amount),
            'tax' => 'INR 0.00',
            'tax_note' => null,
            'total' => 'INR '.$money($payment->amount),
            'payment' => null,
            'footer' => ['Thank you for your payment.'],
        ]);
    }

    /** @return string|null the error, or null when the provider accepted it */
    public function send(CrmPayment $payment, string $channel, ?string $recipient): ?string
    {
        if ($payment->reversed_at !== null) {
            return 'This payment was reversed.';
        }
        $payment->loadMissing(['contact']);
        if ($payment->contact?->opted_out_at !== null) {
            return 'This contact has opted out of messages.';
        }
        $account = Account::query()->findOrFail($payment->account_id);
        $seller = (string) ($this->documents->profileFor($account)->legal_name ?: $account->company_name);

        if ($channel === 'whatsapp') {
            $phone = $recipient ?: $payment->contact?->phone_number;
            if (! $phone) {
                return 'No phone number for this customer.';
            }
            $text = "Payment received: INR {$payment->amount} on ".($payment->paid_on?->format('d M Y') ?? '').". Receipt {$payment->number}. Thank you!\n- {$seller}";
            $result = DirectMessageDispatcher::dispatch($payment->account_id, $phone, 'text', ['body' => $text], 'crm');

            return ($result['status'] ?? null) === 'sent' ? null : (string) ($result['message'] ?? 'Not sent.');
        }

        $to = $recipient ?: $payment->contact?->email;
        if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address.';
        }
        $setting = MailSetting::currentCached();
        if (! $setting || ! $setting->isConfigured()) {
            return 'Email is not set up on this platform.';
        }
        try {
            Config::set('mail.mailers.crm_documents', $setting->toMailerConfig());
            $bytes = $this->pdf($payment);
            Mail::mailer('crm_documents')->html('<p>Thank you for your payment of INR '.e((string) $payment->amount).'. Your receipt '.e($payment->number).' is attached.</p><p>- '.e($seller).'</p>', function ($m) use ($to, $setting, $seller, $payment, $bytes) {
                $m->to($to)->from($setting->from_address, $seller)->subject('Payment receipt '.$payment->number)
                    ->attachData($bytes, 'receipt.pdf', ['mime' => 'application/pdf']);
            });

            return null;
        } catch (\Throwable $e) {
            Log::warning('CrmReceiptService: email failed.', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);

            return 'The email could not be sent.';
        }
    }
}
