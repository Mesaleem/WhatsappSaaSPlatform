<?php

namespace App\Services\Crm;

use App\Models\Account;
use App\Models\Contact;
use App\Models\CrmLead;
use App\Models\CrmLeadSetting;
use App\Services\Access\AccessControlService;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * QA fixes (2026-10-14) — a new WhatsApp contact can become a CRM lead.
 *
 * Only the click-to-WhatsApp ad referral and the journey save_lead node used
 * to create leads from WhatsApp; an ordinary first message from an unknown
 * number never reached the CRM. When the account turns on "Create a lead when
 * a new number messages us on WhatsApp" (and holds the CRM), a message from a
 * number with no CRM lead yet opens one (source whatsapp). Never throws.
 */
class InboundWhatsAppLeads
{
    public function __construct(
        private readonly CrmLeadService $leads,
        private readonly AccessControlService $access,
    ) {}

    public function maybeCreate(int $accountId, string $phone, ?string $name = null): ?CrmLead
    {
        try {
            $settings = CrmLeadSetting::query()->where('account_id', $accountId)->first();
            if ($settings === null || ! $settings->whatsapp_inbound_leads) {
                return null;
            }
            $account = Account::query()->find($accountId);
            if ($account === null || ! $this->access->canTenant($account, 'crm')) {
                return null;
            }

            $normalized = PhoneNumberNormalizer::normalize($phone);
            if ($normalized === '') {
                return null;
            }
            $contactId = Contact::query()->where('account_id', $accountId)->where('phone_number', $normalized)->value('id');
            if ($contactId !== null && CrmLead::query()->where('account_id', $accountId)->where('contact_id', $contactId)->exists()) {
                return null; // already in the CRM
            }

            return $this->leads->create($account, [
                'phone_number' => $normalized,
                'name' => $name,
                'source' => CrmLead::SOURCE_WHATSAPP,
            ]);
        } catch (Throwable $e) {
            Log::warning('InboundWhatsAppLeads: lead not created.', ['account_id' => $accountId, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
