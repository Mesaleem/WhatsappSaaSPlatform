<?php

namespace App\Services\Crm;

use App\Models\Account;
use App\Models\Contact;
use App\Models\CrmCaptureLinkFailure;
use App\Models\CrmLead;
use App\Models\Lead;
use App\Services\Access\AccessControlService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 6 — CRM Hardening, Issues 4/5/6. Promotes an existing capture
 * row (`leads`) into the CRM: resolve or reuse the Contact, create the
 * CrmLead, and record the link on the capture row.
 *
 *     leads  ->  crm_leads  ->  contacts
 *
 * THE CAPTURE ROW IS NEVER TOUCHED AT ALL. Since Round 2 the link lives
 * on crm_leads.capture_lead_id, under a composite (capture_lead_id,
 * account_id) foreign key that makes a cross-tenant promotion impossible
 * at the database level — so `leads` is now exactly the table it was
 * before the CRM existed, not one column wider. Everything
 * MetaLeadWebhookHandler, WhatsAppJourneyEngine::upsertLead() and
 * MetaWebhookController::captureCtwaLead() write stays as it was, and
 * LeadController/SocialInboxController keep reading the same rows.
 *
 * IDEMPOTENT BY CONSTRUCTION. Two of the three capture writers use
 * updateOrCreate() on a dedup key and can therefore run more than once
 * for the same submission; link() returns the existing CrmLead the
 * moment one already points at this capture row, and
 * unique(capture_lead_id) makes a second one unstorable anyway — so a
 * redelivery can never produce a duplicate opportunity.
 *
 * FAILURES ARE OBSERVABLE (Round 2, Limitation 5). A capture that cannot
 * be promoted writes a CrmCaptureLinkFailure row carrying the
 * identifiers needed to find and retry it — never the payload, never a
 * provider credential.
 *
 * ENTITLEMENT (Phase 6 Task 10). linkQuietly() — the entry point every
 * capture writer, retry() and the backfill use — promotes a capture only
 * when its account may use the CRM: the same two checks the CRM routes
 * make (`capability.guard:crm` -> AccessControlService::canTenant() and
 * `module.guard:lead_crm` -> Account::hasModuleEnabled()). A capture for
 * a non-entitled account is left unpromoted and recorded as a
 * `not_entitled` failure, so retry() can promote it after an upgrade.
 * The capture itself is never affected. link() stays the unconditional
 * promotion primitive.
 */
class CaptureLeadLinker
{
    public const CRM_CAPABILITY = 'crm';

    public const CRM_MODULE = 'lead_crm';

    public function __construct(
        private readonly ContactResolver $contacts,
        private readonly AccessControlService $accessControl,
    ) {
    }

    /**
     * Phase 6 Task 10 — may this capture row's account use the CRM right
     * now? Same predicates as the CRM route guards. Not cached beyond
     * Account::findCached() (the entitlement query is live, so a revoke
     * applies to the next capture immediately).
     */
    public function accountMayUseCrm(Lead $lead): bool
    {
        $account = Account::findCached((int) $lead->account_id);

        return $account !== null
            && $account->hasModuleEnabled(self::CRM_MODULE)
            && $this->accessControl->canTenant($account, self::CRM_CAPABILITY);
    }

    /**
     * Link one capture row, or explain why it cannot be linked by
     * returning null.
     *
     * Returns null — deliberately, without creating anything — when the
     * row carries no phone number that normalizes to digits. The brief
     * is explicit: an unresolvable capture lead must be left unlinked
     * rather than given an invented Contact. In practice this is a Meta
     * Lead Ads form that collected an email but no phone, or a form
     * whose phone field name did not match MetaLeadWebhookHandler's
     * best-effort heuristic.
     */
    public function link(Lead $lead): ?CrmLead
    {
        $existing = $this->existingCrmLeadFor($lead);

        if ($existing) {
            return $existing;
        }

        if (blank($lead->lead_phone)) {
            $this->recordFailure($lead, CrmCaptureLinkFailure::REASON_UNRESOLVABLE_PHONE, 'The capture row carries no phone number that normalizes to digits.');

            return null;
        }

        try {
            $crmLead = $this->createFor($lead);
        } catch (UniqueConstraintViolationException $e) {
            // Phase 6 Task 10 — two deliveries of the same event raced
            // past existingCrmLeadFor(); unique(capture_lead_id) let only
            // one insert win. Return the winner instead of failing, so a
            // concurrent duplicate is a no-op rather than a spurious
            // failure row. Any other unique violation is rethrown.
            $winner = $this->existingCrmLeadFor($lead);

            if (! $winner) {
                throw $e;
            }

            $crmLead = $winner;
        }

        $this->resolveFailure($lead, $crmLead);

        return $crmLead;
    }

    /** One transaction: resolve or reuse the Contact, create the CrmLead. */
    private function createFor(Lead $lead): CrmLead
    {
        return DB::transaction(function () use ($lead): CrmLead {
            $contact = $this->contacts->resolve(
                (int) $lead->account_id,
                (string) $lead->lead_phone,
                $lead->lead_name,
                $lead->lead_email,
            );

            return CrmLead::create([
                'account_id' => $lead->account_id,
                'contact_id' => $contact->id,
                // Round 2 — the link lives here now, under a composite
                // (capture_lead_id, account_id) foreign key, so the
                // database itself refuses a cross-tenant promotion.
                'capture_lead_id' => $lead->id,
                'status' => CrmLead::STATUS_NEW,
                'source' => CrmLead::sourceForCaptureProvider($lead->provider),
            ]);
        });
    }

    /**
     * The CRM lead this capture row was already promoted into, if any.
     * Reading the link off crm_leads rather than off the capture row is
     * what makes link() idempotent under a webhook redelivery.
     */
    public function existingCrmLeadFor(Lead $lead): ?CrmLead
    {
        return CrmLead::query()
            ->where('capture_lead_id', $lead->id)
            ->first();
    }

    /**
     * Retry one recorded failure. Idempotent in both directions: a
     * capture that has since been promoted returns its existing CRM
     * lead and marks the failure resolved without creating anything,
     * and a capture that still cannot be resolved simply bumps the
     * attempt count.
     *
     * Deliberately NOT a queued job or a schedule — the brief forbids
     * introducing automation here. This is the operation an operator (or
     * a later task's admin screen) invokes; nothing calls it on a timer.
     */
    public function retry(CrmCaptureLinkFailure $failure): ?CrmLead
    {
        $lead = $failure->lead;

        if (! $lead) {
            return null;
        }

        return $this->linkQuietly($lead);
    }

    /**
     * link(), but a CRM failure can never break a capture.
     *
     * WHY THIS EXISTS AND WHERE IT IS USED: all three capture call sites
     * run inside a webhook or a live journey advance. MetaLeadWebhookHandler
     * is deliberately synchronous and Meta retries any non-2xx response,
     * so an exception escaping from CRM linkage would turn a successful,
     * already-persisted capture into an endless redelivery loop — a
     * regression in the exact behaviour this hardening pass is required
     * not to break. The capture record is the source of truth; its CRM
     * projection is derived, and a derived write failing is a logged
     * incident, not a reason to lose the lead.
     *
     * The failure is logged with enough identity to replay it by hand,
     * and the row simply stays unlinked — which the backfill's own
     * "only ever links what is not yet linked" rule will pick up on a
     * later run.
     */
    public function linkQuietly(Lead $lead): ?CrmLead
    {
        try {
            // Task 10 — an already-promoted capture is returned whatever
            // the account's current entitlement (read, not a write).
            $existing = $this->existingCrmLeadFor($lead);

            if ($existing) {
                return $existing;
            }

            if (! $this->accountMayUseCrm($lead)) {
                $this->recordFailure($lead, CrmCaptureLinkFailure::REASON_NOT_ENTITLED, 'The account is not entitled to the CRM (crm capability or lead_crm module) at capture time.');

                return null;
            }

            return $this->link($lead);
        } catch (Throwable $e) {
            Log::warning('CaptureLeadLinker: could not promote a capture lead into the CRM; the capture row is unchanged and stays unlinked.', [
                'lead_id' => $lead->id,
                'account_id' => $lead->account_id,
                'provider' => $lead->provider,
                'exception' => $e->getMessage(),
            ]);

            // Round 2, Limitation 5 — the log line alone made failures
            // invisible in practice. recordFailure() is itself wrapped,
            // because a failure to record a failure must still not break
            // the capture.
            try {
                $this->recordFailure($lead, CrmCaptureLinkFailure::REASON_EXCEPTION, $e->getMessage());
            } catch (Throwable $inner) {
                Log::error('CaptureLeadLinker: could not even record the promotion failure.', [
                    'lead_id' => $lead->id,
                    'exception' => $inner->getMessage(),
                ]);
            }

            return null;
        }
    }

    /**
     * The Contact a capture row resolves to, without creating anything.
     * Used by the backfill's reporting and by tests; not a write path.
     */
    public function existingContactFor(Lead $lead): ?Contact
    {
        return $this->existingCrmLeadFor($lead)?->contact;
    }

    /**
     * Record, or update, the open failure for this capture row.
     *
     * unique(lead_id) means a capture that keeps failing updates one row
     * and bumps `attempts` rather than accumulating duplicates, so the
     * table stays a work queue rather than a log. The message is
     * truncated and carries no payload and no provider credential — the
     * capture row still holds all the data a retry needs.
     */
    private function recordFailure(Lead $lead, string $reason, string $message): void
    {
        $failure = CrmCaptureLinkFailure::query()->where('lead_id', $lead->id)->first();

        if ($failure) {
            $failure->forceFill([
                'reason' => $reason,
                'message' => mb_substr($message, 0, 1000),
                'attempts' => $failure->attempts + 1,
                'resolved_at' => null,
                'resolved_crm_lead_id' => null,
            ])->save();

            return;
        }

        CrmCaptureLinkFailure::create([
            'account_id' => $lead->account_id,
            'lead_id' => $lead->id,
            'provider' => $lead->provider,
            'provider_lead_id' => $lead->provider_lead_id,
            'reason' => $reason,
            'message' => mb_substr($message, 0, 1000),
            'attempts' => 1,
        ]);
    }

    /** Close the open failure for this capture row once promotion finally succeeds. */
    private function resolveFailure(Lead $lead, CrmLead $crmLead): void
    {
        CrmCaptureLinkFailure::query()
            ->where('lead_id', $lead->id)
            ->whereNull('resolved_at')
            ->get()
            ->each(fn (CrmCaptureLinkFailure $f) => $f->forceFill([
                'resolved_at' => now(),
                'resolved_crm_lead_id' => $crmLead->id,
            ])->save());
    }
}
