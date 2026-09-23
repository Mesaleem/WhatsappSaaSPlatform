<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6 — CRM, Task 1. One business opportunity, attached to one
 * Contact, owned by one tenant.
 *
 * Deliberately distinct from App\Models\Lead, which is the existing,
 * unchanged Meta Lead Ads / journey capture record (see LeadController's
 * docblock: immutable, read-only, one row per submission). A CrmLead is
 * mutable, has a lifecycle, and may be created from any of the five
 * supported sources — including manually, which the capture table cannot
 * represent (its provider_lead_id is NOT NULL and globally unique).
 *
 * STATUS AND SOURCE are strings plus the constant whitelists below, not
 * DB enums. That is this codebase's existing convention for closed value
 * sets (ContactGroup::GROUP_TYPES, WhatsAppFlow::NODE_TYPES,
 * AccountEntitlement's SOURCE_* constants), and it is what makes the
 * representation extensible: adding a value is a constant change, not an
 * ALTER TABLE.
 *
 * VALIDATION LIVES IN THE MODEL because Task 1 ships no controller. The
 * saving hook below is the domain's own guard, so the invariants hold for
 * every future writer — a controller, a job, a journey node, an artisan
 * command or a seeder — rather than only for whichever request class
 * someone remembers to route through. It throws ValidationException
 * specifically so that when Task 2's controller does arrive, Laravel
 * renders these as ordinary 422s with field keys, exactly like the rest
 * of this API.
 */
class CrmLead extends Model
{
    use HasFactory;
    use LogsActivity;

    /** Dynamic Route Master / Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'CRM';

    /**
     * Task 9 — every `update` audit row carries the lead id, so an
     * individual or bulk status/assignee change is traceable per lead
     * (see LogsActivity::auditIdentityAttributes()).
     *
     * @var list<string>
     */
    protected array $auditIdentity = ['id'];

    public const STATUS_NEW = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_NOT_CONVERTED = 'not_converted';

    /**
     * The four statuses this product actually has. Deliberately NOT a
     * sales pipeline — qualified/proposal/negotiation/won/lost are
     * excluded because nothing in this repository requires them and the
     * brief forbids inventing them.
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTED,
        self::STATUS_CONVERTED,
        self::STATUS_NOT_CONVERTED,
    ];

    /**
     * Task 5 — the lifecycle transition matrix, stated explicitly so
     * transition behaviour is never implicit.
     *
     * EVERY TRANSITION BETWEEN THE FOUR STATUSES IS PERMITTED, including
     * reopening a terminal one. That is not laxity, it is the product
     * behaviour this domain already had and already tested: Task 2's
     * CrmLeadService::stampTerminalOutcome() has always had a "back to
     * an open status: neither outcome is true any more" branch, and
     * CrmContactResolutionTest::test_reopening_a_lead_clears_the_stale_outcome
     * has pinned converted -> contacted since then. A lead marked
     * converted by mistake must be correctable; making the outcome
     * irreversible would be inventing a restriction the product never
     * asked for, which the brief explicitly forbids.
     *
     * What IS enforced is that the row can never contradict itself —
     * see assertStatusInvariants(). A correction does not leave a stale
     * converted_at behind; it clears it.
     *
     * A status is also listed as a transition to itself, which is what
     * makes "set it to what it already is" a legitimate no-op rather
     * than an error. CrmLeadService short-circuits that case so it
     * writes nothing and audits nothing.
     *
     * This constant exists so a future product decision to restrict the
     * lifecycle is one edit here plus its tests, not a hunt through
     * service code.
     *
     * @var array<string, list<string>>
     */
    public const STATUS_TRANSITIONS = [
        self::STATUS_NEW => [self::STATUS_NEW, self::STATUS_CONTACTED, self::STATUS_CONVERTED, self::STATUS_NOT_CONVERTED],
        self::STATUS_CONTACTED => [self::STATUS_NEW, self::STATUS_CONTACTED, self::STATUS_CONVERTED, self::STATUS_NOT_CONVERTED],
        self::STATUS_CONVERTED => [self::STATUS_NEW, self::STATUS_CONTACTED, self::STATUS_CONVERTED, self::STATUS_NOT_CONVERTED],
        self::STATUS_NOT_CONVERTED => [self::STATUS_NEW, self::STATUS_CONTACTED, self::STATUS_CONVERTED, self::STATUS_NOT_CONVERTED],
    ];

    /**
     * Is this lifecycle move allowed? Both ends must be canonical
     * statuses — an unknown `from` (a row written before a status was
     * retired, say) fails closed rather than silently permitting
     * anything.
     */
    public static function canTransitionTo(?string $from, string $to): bool
    {
        return in_array($to, self::STATUS_TRANSITIONS[$from] ?? [], true);
    }

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_WHATSAPP = 'whatsapp';
    public const SOURCE_META_AD = 'meta_ad';
    public const SOURCE_API = 'api';
    public const SOURCE_JOURNEY = 'journey';

    /**
     * Where a lead came from.
     *
     * @var list<string>
     */
    public const SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_WHATSAPP,
        self::SOURCE_META_AD,
        self::SOURCE_API,
        self::SOURCE_JOURNEY,
    ];

    /**
     * Phase 6 Hardening (Issues 5 & 6) — how a capture row's
     * `leads.provider` maps onto this vocabulary. These three are the
     * complete set of values any writer in this codebase produces,
     * verified by inspection:
     *   'meta'             MetaLeadWebhookHandler (Lead Ads form submit)
     *                      and the leads.provider column default
     *   'whatsapp_journey' WhatsAppJourneyEngine::upsertLead() (save_lead node)
     *   'whatsapp_ctwa'    MetaWebhookController::captureCtwaLead()
     *                      (click-to-WhatsApp referral)
     * Phase 6 Task 10 — a CTWA lead maps to `meta_ad`. Hardening Issue 6
     * had mapped it to `whatsapp` (the lead arrives as a conversation);
     * the Task 10 spec is explicit that the Facebook/Instagram
     * Click-to-WhatsApp chain (ad -> conversation -> Contact -> CRM Lead)
     * is attributed to the ad. Leads already promoted under the old
     * mapping keep `whatsapp` — no data is rewritten here; the capture
     * row (`leads.provider = whatsapp_ctwa`) still identifies them.
     * `whatsapp` stays a valid source (manual / API use).
     *
     * @var array<string, string>
     */
    public const SOURCE_BY_CAPTURE_PROVIDER = [
        'meta' => self::SOURCE_META_AD,
        'whatsapp_journey' => self::SOURCE_JOURNEY,
        'whatsapp_ctwa' => self::SOURCE_META_AD,
    ];

    /**
     * Falls back to `manual` for a provider string this map does not
     * know — the domain's own "no automated source identified" value,
     * and the column default. Deliberately not a guess at the nearest
     * automated source, which would misattribute the lead.
     */
    public static function sourceForCaptureProvider(?string $provider): string
    {
        return self::SOURCE_BY_CAPTURE_PROVIDER[$provider] ?? self::SOURCE_MANUAL;
    }

    protected $fillable = [
        'account_id',
        'contact_id',
        // Round 2 — the capture row this opportunity was promoted from,
        // moved here from leads.crm_lead_id so it can carry a composite
        // tenant-proving FK. Null for a manually created lead.
        'capture_lead_id',
        'status',
        'source',
        'assigned_user_id',
        'converted_at',
        'not_converted_at',
        'not_converted_reason',
    ];

    protected $attributes = [
        'status' => self::STATUS_NEW,
        'source' => self::SOURCE_MANUAL,
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'contact_id' => 'integer',
            'capture_lead_id' => 'integer',
            'assigned_user_id' => 'integer',
            'converted_at' => 'datetime',
            'not_converted_at' => 'datetime',
        ];
    }

    /**
     * Every invariant this domain has, enforced on every write.
     *
     * The cross-tenant checks are deliberately re-checked here even
     * though the (contact_id, account_id) composite foreign key already
     * makes a cross-tenant contact physically unstorable. Two reasons,
     * both concrete:
     *  1. The FK surfaces as a raw QueryException whose driver message
     *     names tables, columns and constraint names. This guard fails
     *     first, with a clean field-keyed 422 instead.
     *  2. The message is IDENTICAL whether the referenced row belongs to
     *     another tenant or does not exist at all. That is the point: the
     *     brief requires that a user cannot infer another account's data
     *     "through errors or IDs", and a distinguishable error ("no such
     *     contact" vs "not yours") is exactly such an inference channel.
     * The assignment check has no FK backing it at all (see the creating
     * migration for why), so here it is the only line of defence.
     */
    protected static function booted(): void
    {
        static::saving(function (CrmLead $lead): void {
            if (! in_array($lead->status, self::STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => ['The selected status is invalid.'],
                ]);
            }

            if (! in_array($lead->source, self::SOURCES, true)) {
                throw ValidationException::withMessages([
                    'source' => ['The selected source is invalid.'],
                ]);
            }

            // Re-checked whenever either side of the pair moves: changing
            // a lead's account is as much a tenant violation as pointing
            // it at a foreign contact.
            if ($lead->isDirty(['contact_id', 'account_id'])) {
                $contactBelongs = Contact::query()
                    ->whereKey($lead->contact_id)
                    ->where('account_id', $lead->account_id)
                    ->exists();

                if (! $contactBelongs) {
                    throw ValidationException::withMessages([
                        'contact_id' => ['The selected contact is not available.'],
                    ]);
                }
            }

            /*
             * Round 2, Limitation 8 — ASSIGNMENT ELIGIBILITY, not just
             * account membership.
             *
             * An eligible CRM assignee must be (a) a member of this
             * lead's own account, (b) currently active, and (c) able to
             * reach the CRM at all — holding `manage-crm` through a role
             * or a direct grant, which is the same permission
             * routes/api.php gates every CRM endpoint on. Assigning work
             * to someone who cannot open it is not a meaningful
             * assignment, and assigning to a deactivated account is how
             * leads quietly go unworked.
             *
             * Checked ONLY when assigned_user_id or account_id is
             * changing. That is deliberate and load-bearing: an existing
             * assignment to a member who is later deactivated or loses
             * the permission stays exactly as it is — history is not
             * rewritten, and a lead is never silently unassigned. It is
             * only a NEW assignment that has to be to an eligible user.
             */
            if ($lead->assigned_user_id !== null && $lead->isDirty(['assigned_user_id', 'account_id'])) {
                // One message for every failure mode — wrong tenant,
                // nonexistent, inactive, or not CRM-capable — so the
                // endpoint cannot be used to probe another account's
                // users or to enumerate who holds which permission.
                if (! self::assigneeIsEligibleById((int) $lead->assigned_user_id, (int) $lead->account_id)) {
                    throw ValidationException::withMessages([
                        'assigned_user_id' => ['The selected assignee is not available.'],
                    ]);
                }
            }

            /*
             * Round 2, Limitation 1 — the capture link's tenant rule.
             * On MySQL/MariaDB the composite (capture_lead_id,
             * account_id) foreign key already makes a cross-tenant link
             * physically unstorable; this guard fails first with a clean,
             * non-identifying 422 instead of a raw driver error, and is
             * the only protection on SQLite, which cannot add a foreign
             * key through ALTER TABLE.
             */
            if ($lead->capture_lead_id !== null && $lead->isDirty(['capture_lead_id', 'account_id'])) {
                $captureBelongs = Lead::query()
                    ->whereKey($lead->capture_lead_id)
                    ->where('account_id', $lead->account_id)
                    ->exists();

                if (! $captureBelongs) {
                    throw ValidationException::withMessages([
                        'capture_lead_id' => ['The selected capture lead is not available.'],
                    ]);
                }
            }

            $lead->assertStatusInvariants();
        });
    }

    /**
     * Task 4 — THE definition of "may receive a NEW CRM assignment",
     * in one place.
     *
     * The saving guard above and the /api/crm/assignees listing both
     * call this, which is what stops the selector from offering someone
     * the write path would then refuse — two copies of this rule would
     * drift the moment either changed.
     *
     * The three conditions, and why each one:
     *   same account   a lead and its owner are the same tenant's; the
     *                  composite (contact_id, account_id) FK already
     *                  guarantees the contact half, and this is the
     *                  owner half.
     *   is_active      assigning work to a deactivated login is how
     *                  leads quietly go unworked.
     *   manage-crm     the permission every CRM route is gated on.
     *                  Someone who cannot open the CRM cannot
     *                  meaningfully own a lead in it. Checked with
     *                  ->can(), so a direct per-user grant counts as
     *                  well as one held through a role — the same call
     *                  the route middleware makes.
     *
     * FAILS CLOSED: a null user (not found, or found outside the
     * account) is ineligible, not "unknown".
     *
     * Deliberately NOT a hierarchy check of its own. This platform's
     * hierarchy (Super Admin, Agent, Admin, Client) is expressed as the
     * EFFECTIVE ACCOUNT that TenantIsolationMiddleware resolves, and
     * both callers already operate inside that account. Adding a second,
     * CRM-specific hierarchy here is exactly what the brief forbids.
     */
    /**
     * Task 9 — request-scoped memo for the eligibility answer, active only
     * inside withAssigneeEligibilityMemo(). A bulk assignment saves up to
     * CRM_BULK_MAX leads to the same assignee; without this the saving
     * guard would re-query the same user and permissions once per lead.
     * The RULE is unchanged — assigneeIsEligible() still decides — only
     * its answer is reused for the duration of one operation, then
     * discarded, so nothing can go stale across requests or queue jobs.
     *
     * @var array<string, bool>|null
     */
    private static ?array $eligibilityMemo = null;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function withAssigneeEligibilityMemo(callable $callback): mixed
    {
        $previous = self::$eligibilityMemo;
        self::$eligibilityMemo = [];

        try {
            return $callback();
        } finally {
            self::$eligibilityMemo = $previous;
        }
    }

    /**
     * The saving guard's lookup + assigneeIsEligible(), memoized only
     * inside withAssigneeEligibilityMemo(). Outside it (every individual
     * write) this is exactly the previous query-and-check.
     */
    public static function assigneeIsEligibleById(int $userId, int $accountId): bool
    {
        $key = $userId.':'.$accountId;

        if (self::$eligibilityMemo !== null && array_key_exists($key, self::$eligibilityMemo)) {
            return self::$eligibilityMemo[$key];
        }

        $assignee = User::query()
            ->whereKey($userId)
            ->where('account_id', $accountId)
            ->first();

        $eligible = self::assigneeIsEligible($assignee, $accountId);

        if (self::$eligibilityMemo !== null) {
            self::$eligibilityMemo[$key] = $eligible;
        }

        return $eligible;
    }

    public static function assigneeIsEligible(?User $user, int $accountId): bool
    {
        return $user !== null
            && (int) $user->account_id === $accountId
            && (bool) $user->is_active
            && $user->can('manage-crm');
    }

    /**
     * Round 2, Limitation 10 — the status/timestamp invariants, enforced
     * at the model so no writer can store an impossible combination:
     *
     *   new / contacted   converted_at NULL, not_converted_at NULL,
     *                     not_converted_reason NULL
     *   converted         converted_at SET,  not_converted_at NULL,
     *                     not_converted_reason NULL
     *   not_converted     converted_at NULL, not_converted_at SET
     *
     * CrmLeadService::stampTerminalOutcome() already produces exactly
     * these shapes; this is the backstop that makes them true for a
     * direct model write, a factory, a job or a seeder as well.
     *
     * not_converted_reason is OPTIONAL for not_converted, and that is
     * the existing product rule rather than an omission: the column was
     * introduced as nullable in Task 1 on the precedent of
     * leads.tenant_notify_error / contact_groups.sync_error, the
     * Contacts and Leads request classes both validate it as `nullable`,
     * and requiring it now would reject every lead already stored
     * without one. It is forbidden on the other three statuses, since a
     * reason for not converting cannot survive a lead that did convert
     * or is still open.
     */
    private function assertStatusInvariants(): void
    {
        $converted = $this->status === self::STATUS_CONVERTED;
        $notConverted = $this->status === self::STATUS_NOT_CONVERTED;

        if ($converted && $this->converted_at === null) {
            throw ValidationException::withMessages([
                'converted_at' => ['A converted lead must record when it converted.'],
            ]);
        }

        if (! $converted && $this->converted_at !== null) {
            throw ValidationException::withMessages([
                'converted_at' => ['Only a converted lead may record a conversion time.'],
            ]);
        }

        if ($notConverted && $this->not_converted_at === null) {
            throw ValidationException::withMessages([
                'not_converted_at' => ['A not-converted lead must record when it was closed.'],
            ]);
        }

        if (! $notConverted && $this->not_converted_at !== null) {
            throw ValidationException::withMessages([
                'not_converted_at' => ['Only a not-converted lead may record a close time.'],
            ]);
        }

        if (! $notConverted && $this->not_converted_reason !== null) {
            throw ValidationException::withMessages([
                'not_converted_reason' => ['Only a not-converted lead may record a reason.'],
            ]);
        }
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The account member who owns this opportunity. Reuses the existing
     * user/account-member architecture (users.account_id -> accounts, with
     * spatie roles layered on top) — no second employee system. Nullable:
     * a lead that has just arrived has no owner yet.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Phase 6 Hardening (Issue 4) — the capture row this CRM lead was
     * created from, when it came from one. Null for a manually created
     * lead. unique(capture_lead_id) states the 1:1 rule in the schema:
     * one capture submission produces at most one CRM opportunity.
     */
    public function captureLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'capture_lead_id');
    }

    /**
     * Task 7 — this lead's tags. Independent metadata: tags never feed
     * `status`, the lifecycle transitions or the pipeline columns.
     *
     * READ-ONLY by convention: every assignment is written through
     * CrmTagService (as a CrmLeadTag model, so it is audited and
     * tenant-guarded). Ordered case-insensitively by name, then id, so a
     * serialized lead's tag list is deterministic.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CrmTag::class, 'crm_lead_tags', 'crm_lead_id', 'crm_tag_id')
            ->orderBy('crm_tags.normalized_name')
            ->orderBy('crm_tags.id');
    }

    /**
     * Task 6 — THE pipeline definition. One ordered list, derived
     * straight from the four canonical statuses, so a Kanban column set
     * can never drift from the lifecycle it displays.
     *
     * The order is the lifecycle's own reading order (captured ->
     * worked -> outcome), and it is centralized here rather than
     * restated in a controller, a resource, a test or a frontend
     * contract. Adding or retiring a status changes this in one place.
     *
     * Labels are derived from the slug, not stored: making them
     * database-configurable is explicitly out of scope, and a lookup
     * table for four constants would be a second source of truth.
     *
     * NO pipeline_status / stage / kanban_status column exists or is
     * needed — `crm_leads.status` is the only status field, and the
     * pipeline is a VIEW of it.
     *
     * @return list<array{status: string, label: string, order: int}>
     */
    public static function pipelineStatuses(): array
    {
        return array_values(array_map(
            static fn (int $index, string $status): array => [
                'status' => $status,
                'label' => ucwords(str_replace('_', ' ', $status)),
                'order' => $index + 1,
            ],
            array_keys(self::STATUSES),
            self::STATUSES,
        ));
    }

    /**
     * Task 6 — the validation rules every CRM lead LIST surface shares:
     * /crm/leads, /crm/contacts/{id}/leads and /crm/pipeline.
     *
     * Declared once so the three cannot accept different vocabularies.
     * `status` is deliberately `nullable` here, not `required`: on the
     * list endpoints an absent status means "no filter", and on the
     * pipeline it means "every column".
     *
     * @return array<string, mixed>
     */
    public static function filterRules(?int $accountId = null): array
    {
        /*
         * Task 7 — tag filters. A tag id must belong to the CALLER'S
         * account: a foreign id and a nonexistent id fail the same exists
         * rule with the same message, so the filter cannot be used to
         * probe another tenant's tag ids. Without an account (no caller
         * passes none today) the id is only shape-checked; scopeFilter()
         * would then simply match nothing, because an assignment row can
         * never pair a local lead with a foreign tag.
         */
        $tagExists = $accountId === null
            ? []
            : [Rule::exists('crm_tags', 'id')->where('account_id', $accountId)];

        return [
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
            'source' => ['nullable', 'string', Rule::in(self::SOURCES)],
            // 'none' is the only way a query string can express
            // "unassigned"; anything else must be a positive integer, so
            // a typo is a 422 rather than a silently empty list.
            'assigned_user_id' => ['nullable', 'string', 'regex:/^(none|[1-9][0-9]*)$/'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'tag_id' => ['nullable', 'integer', 'min:1', ...$tagExists],
            // AND semantics — see scopeFilter(). Capped so a query string
            // cannot turn into an unbounded number of EXISTS probes.
            'tag_ids' => ['nullable', 'array', 'max:'.self::TAG_FILTER_MAX],
            'tag_ids.*' => ['required', 'integer', 'min:1', 'distinct', ...$tagExists],
        ];
    }

    /** Task 7 — the maximum number of tags one filter may require. */
    public const TAG_FILTER_MAX = 10;

    /**
     * Task 7 — messages for filterRules(). One identical, non-identifying
     * message for a foreign or nonexistent tag id.
     *
     * @return array<string, string>
     */
    public static function filterMessages(): array
    {
        return [
            'tag_id.exists' => 'The selected tag is not available.',
            'tag_ids.*.exists' => 'The selected tag is not available.',
        ];
    }

    /**
     * Task 6 — the shared CRM lead filter, extracted from
     * CrmLeadController::index() so the pipeline reuses the exact same
     * semantics rather than a second implementation that can drift.
     *
     * Deliberately does NOT apply tenant scoping: callers pair this with
     * forAccount(), which keeps the tenant boundary explicit and
     * impossible to omit by forgetting an argument here.
     *
     * The search leg goes through whereHas('contact'), so it can never
     * reach a contact outside the account the caller already scoped to.
     *
     * @param array<string, mixed> $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['source']), fn ($q) => $q->where('source', $filters['source']))
            ->when(isset($filters['assigned_user_id']), function ($q) use ($filters) {
                $filters['assigned_user_id'] === 'none'
                    ? $q->whereNull('assigned_user_id')
                    : $q->where('assigned_user_id', (int) $filters['assigned_user_id']);
            })
            ->when(isset($filters['search']), function ($q) use ($filters) {
                $search = $filters['search'];
                $q->whereHas('contact', function ($c) use ($search) {
                    $c->where('name', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%");
                });
            })
            /*
             * Task 7 — tag filter. `tag_id` and every `tag_ids[]` entry
             * are combined with AND: a lead must carry ALL of them. One
             * EXISTS probe per required tag, each a primary-key lookup on
             * crm_lead_tags(crm_lead_id, crm_tag_id) — no join, so no
             * duplicate rows and pagination totals stay exact.
             */
            ->when(self::requiredTagIds($filters) !== [], function ($q) use ($filters) {
                foreach (self::requiredTagIds($filters) as $tagId) {
                    $q->whereExists(function ($sub) use ($tagId) {
                        $sub->selectRaw('1')
                            ->from('crm_lead_tags')
                            ->whereColumn('crm_lead_tags.crm_lead_id', 'crm_leads.id')
                            ->where('crm_lead_tags.crm_tag_id', $tagId);
                    });
                }
            });
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<int>
     */
    private static function requiredTagIds(array $filters): array
    {
        $ids = array_merge(
            isset($filters['tag_id']) ? [$filters['tag_id']] : [],
            is_array($filters['tag_ids'] ?? null) ? $filters['tag_ids'] : [],
        );

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Task 6 — deterministic list ordering, shared by every CRM lead
     * list surface.
     *
     * newest-first was already the convention (->latest()); `id DESC` is
     * added as a tiebreak because created_at has second granularity, so
     * leads captured in the same second — a bulk import, a backfill, a
     * busy webhook minute — could otherwise swap places between
     * requests and make a paginated Kanban column show or skip a card.
     * Observable ordering is unchanged for rows with distinct
     * timestamps.
     */
    public function scopeOrderedForList(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** Mirrors Lead::scopeForAccount() — the tenant-scoping idiom already used in this codebase. */
    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
