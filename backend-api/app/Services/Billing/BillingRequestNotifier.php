<?php

namespace App\Services\Billing;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\ModuleAddonRequest;
use App\Models\QuotaRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Mail\SystemMailer;

/**
 * Emails and in-app notifications for a client's billing requests:
 *
 *   - plan request (manual checkout: new plan, renewal or upgrade),
 *   - message quota / wallet top-up request,
 *   - paid module add-on request,
 *   - and "payment received — plan active" once a plan invoice is paid.
 *
 * The person who asked gets a confirmation; the reviewers (every Super Admin,
 * plus the client's Agent when it has one — SystemMailer::reviewersFor) get an
 * alert email and an in-app notification. Everything is sent after the
 * request commits and never blocks or fails it.
 */
class BillingRequestNotifier
{
    private const REQUEST_TYPES = [
        'upgrade' => 'Upgrade',
        'renew' => 'Renewal',
        'new' => 'New plan',
    ];

    public function __construct(private readonly SystemMailer $mailer) {}

    public function planRequested(Account $account, User $requester, Invoice $invoice, string $action): void
    {
        $type = self::REQUEST_TYPES[$action] ?? 'Plan';
        $amount = $this->money((float) $invoice->total_amount);
        $plan = (string) $invoice->plan_label;

        // QA fixes (2026-10-14): an invoice the Super Admin or an agent issued FOR the
        // client (e.g. "Send" on a custom plan) is announced to the client — it used to
        // send the "we received your request" email to the Super Admin instead.
        if ((int) $requester->account_id !== (int) $account->id) {
            $this->invoiceIssued($account, $invoice);

            return;
        }

        $this->mailer->sendLater('billing.plan_request_received', $requester->email, [
            'name' => $requester->name,
            'company_name' => $account->company_name,
            'plan_name' => $plan,
            'request_type' => $type,
            'invoice_number' => $invoice->invoice_number,
            'amount' => $amount,
            'billing_url' => $this->mailer->url('/billing'),
        ], [], $account->id, $requester->name);

        $reviewers = $this->mailer->reviewersFor($account);
        $this->mailer->sendToUsers('billing.plan_request_admin', $reviewers, [
            'company_name' => $account->company_name,
            'requester_name' => $requester->name,
            'requester_email' => $requester->email,
            'plan_name' => $plan,
            'current_plan' => $this->currentPlanLabel($account, $invoice),
            'request_type' => strtolower($type),
            'invoice_number' => $invoice->invoice_number,
            'amount' => $amount,
            'admin_url' => $this->mailer->url('/billing'),
        ]);
        $this->mailer->notifyUsers(
            $reviewers,
            "{$type} request: {$account->company_name} → {$plan}",
            "Invoice {$invoice->invoice_number} for {$amount} is waiting for payment.",
            'billing',
            '/billing',
        );
    }

    public function quotaRequested(Account $account, User $requester, QuotaRequest $quotaRequest): void
    {
        $summary = $quotaRequest->requested_topup_amount !== null
            ? $this->money((float) $quotaRequest->requested_topup_amount).' wallet top-up'
            : number_format((int) $quotaRequest->requested_extra_messages).' extra messages';
        $reason = $quotaRequest->reason ? nl2br(e($quotaRequest->reason)) : '-';

        $this->mailer->sendLater('billing.quota_request_received', $requester->email, [
            'name' => $requester->name,
            'company_name' => $account->company_name,
            'request_summary' => $summary,
            'reviewer' => SystemMailer::reviewerLabel($account),
            'billing_url' => $this->mailer->url('/billing'),
        ], ['reason' => $reason], $account->id, $requester->name);

        $reviewers = $this->mailer->reviewersFor($account);
        $this->mailer->sendToUsers('billing.quota_request_admin', $reviewers, [
            'company_name' => $account->company_name,
            'requester_name' => $requester->name,
            'requester_email' => $requester->email,
            'request_summary' => $summary,
            'admin_url' => $this->mailer->url('/admin/quota-requests'),
        ], ['reason' => $reason]);
        $this->mailer->notifyUsers($reviewers, 'Quota top-up request: '.$account->company_name, $summary.' requested by '.$requester->name.'.', 'billing', '/admin/quota-requests');
    }

    public function addonRequested(Account $account, User $requester, ModuleAddonRequest $addonRequest, string $addonName): void
    {
        $units = $addonRequest->units !== null ? (string) $addonRequest->units : '-';
        $reason = $addonRequest->reason ? nl2br(e($addonRequest->reason)) : '-';

        $this->mailer->sendLater('billing.addon_request_received', $requester->email, [
            'name' => $requester->name,
            'company_name' => $account->company_name,
            'addon_name' => $addonName,
            'units' => $units,
            'reviewer' => SystemMailer::reviewerLabel($account),
            'addons_url' => $this->mailer->url('/add-ons'),
        ], ['reason' => $reason], $account->id, $requester->name);

        $reviewers = $this->mailer->reviewersFor($account);
        $this->mailer->sendToUsers('billing.addon_request_admin', $reviewers, [
            'company_name' => $account->company_name,
            'requester_name' => $requester->name,
            'requester_email' => $requester->email,
            'addon_name' => $addonName,
            'units' => $units,
            'admin_url' => $this->mailer->url('/add-ons'),
        ], ['reason' => $reason]);
        $this->mailer->notifyUsers($reviewers, 'Add-on request: '.$account->company_name, $requester->name.' requested '.$addonName.($units !== '-' ? " ({$units} units)" : '').'.', 'billing', '/add-ons');
    }

    /** QA fixes (2026-10-14): the client hears about an invoice created for it (email + in-app). */
    public function invoiceIssued(Account $account, Invoice $invoice): void
    {
        $owner = $account->owner;
        if (! $owner) {
            return;
        }
        if ($owner->email) {
            $this->mailer->sendLater('billing.invoice_issued', $owner->email, [
                'name' => $owner->name,
                'company_name' => $account->company_name,
                'plan_name' => (string) $invoice->plan_label,
                'invoice_number' => $invoice->invoice_number,
                'amount' => $this->money((float) $invoice->total_amount),
                'billing_url' => $this->mailer->url('/billing'),
            ], [], $account->id, $owner->name);
        }
        $this->mailer->notifyUsers([$owner], 'Invoice ready: '.$invoice->plan_label, "Invoice {$invoice->invoice_number} for ".$this->money((float) $invoice->total_amount).' is ready. Pay it on the Billing page.', 'billing', '/billing');
    }

    /** Called inside the payment transaction; the email goes out after it commits. */
    public function planActivated(Account $account, Invoice $invoice, Subscription $subscription): void
    {
        $validUntil = $subscription->expires_at?->copy()->timezone('Asia/Kolkata')->format('d M Y').' (IST)';

        // QA fixes (2026-10-14): the Super Admin (and the client's agent) hear that a
        // payment arrived — especially an online one nobody recorded by hand.
        if ((float) $invoice->total_amount > 0) {
            $reviewers = $this->mailer->reviewersFor($account);
            $paidVia = $invoice->payment_gateway === 'manual' ? 'Recorded payment' : ucfirst((string) $invoice->payment_gateway);
            $this->mailer->sendToUsers('billing.payment_received_admin', $reviewers, [
                'company_name' => $account->company_name,
                'plan_name' => (string) $invoice->plan_label,
                'invoice_number' => $invoice->invoice_number,
                'amount' => $this->money((float) $invoice->total_amount),
                'paid_via' => $paidVia,
                'valid_until' => $validUntil,
                'admin_url' => $this->mailer->url('/admin/accounts'),
            ]);
            $this->mailer->notifyUsers($reviewers, 'Payment received: '.$account->company_name, $invoice->plan_label.' — '.$this->money((float) $invoice->total_amount)." ({$paidVia}).", 'billing', '/admin/accounts');
        }

        $owner = $account->owner;
        if (! $owner || ! $owner->email) {
            return;
        }
        $this->mailer->notifyUsers([$owner], 'Your '.$invoice->plan_label.' plan is active', 'Payment received. Your plan runs until '.$validUntil.'.', 'billing', '/billing');

        $this->mailer->sendLater('billing.plan_activated', $owner->email, [
            'name' => $owner->name,
            'company_name' => $account->company_name,
            'plan_name' => (string) $invoice->plan_label,
            'invoice_number' => $invoice->invoice_number,
            'amount' => $this->money((float) $invoice->total_amount),
            'valid_until' => $subscription->expires_at?->copy()->timezone('Asia/Kolkata')->format('d M Y').' (IST)',
            'billing_url' => $this->mailer->url('/billing'),
        ], [], $account->id, $owner->name);
    }

    private function money(float $amount): string
    {
        return '₹'.number_format($amount, 2);
    }

    /** The plan the client last paid for (not counting this invoice), or a plain fallback. */
    private function currentPlanLabel(Account $account, Invoice $exclude): string
    {
        $label = Invoice::query()
            ->where('account_id', $account->id)
            ->where('status', 'paid')
            ->whereKeyNot($exclude->id)
            ->whereNotNull('plan_label')
            ->orderByDesc('paid_at')
            ->value('plan_label');

        if ($label) {
            return (string) $label;
        }

        return $account->currentSubscription ? 'Free trial / custom' : 'None';
    }
}
