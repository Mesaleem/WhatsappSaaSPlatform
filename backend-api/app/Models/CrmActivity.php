<?php

namespace App\Models;

use App\Services\Crm\CrmTenantLinks;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 1 — a call, meeting, task, email, WhatsApp follow-up or note, or an
 * `event` the system writes (lead converted, deal won). Links to any of a lead,
 * contact, company and deal, so one record shows on each of their timelines.
 *
 * Invariants (saving guard, every writer): a known type / status / priority; at least
 * one link; every link in the same tenant; `done` carries completed_at and nothing
 * else does. Events are written by the services only and are never "pending".
 */
class CrmActivity extends Model
{
    use LogsActivity;

    protected string $auditModuleName = 'CRM';

    /** @var list<string> */
    protected array $auditIdentity = ['id'];

    protected $table = 'crm_activities';

    public const TYPE_CALL = 'call';
    public const TYPE_MEETING = 'meeting';
    public const TYPE_TASK = 'task';
    public const TYPE_EMAIL = 'email';
    public const TYPE_WHATSAPP = 'whatsapp';
    public const TYPE_NOTE = 'note';
    public const TYPE_EVENT = 'event';

    /** Types a user may create (an event is written by the system). @var list<string> */
    public const USER_TYPES = [self::TYPE_CALL, self::TYPE_MEETING, self::TYPE_TASK, self::TYPE_EMAIL, self::TYPE_WHATSAPP, self::TYPE_NOTE];

    /** @var list<string> */
    public const TYPES = [...self::USER_TYPES, self::TYPE_EVENT];

    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_DONE, self::STATUS_CANCELLED];

    /** @var list<string> */
    public const PRIORITIES = ['low', 'normal', 'high'];

    /** @var list<string> */
    public const LINKS = ['crm_lead_id', 'contact_id', 'crm_company_id', 'crm_deal_id'];

    protected $fillable = [
        'account_id', 'type', 'subject', 'body', 'crm_lead_id', 'contact_id', 'crm_company_id', 'crm_deal_id',
        'due_at', 'completed_at', 'status', 'priority', 'outcome', 'assigned_user_id', 'created_by_user_id',
    ];

    protected $attributes = ['status' => self::STATUS_PENDING, 'priority' => 'normal'];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'crm_lead_id' => 'integer',
            'contact_id' => 'integer',
            'crm_company_id' => 'integer',
            'crm_deal_id' => 'integer',
            'assigned_user_id' => 'integer',
            'created_by_user_id' => 'integer',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CrmActivity $activity): void {
            if ($activity->exists && $activity->isDirty('account_id')) {
                throw ValidationException::withMessages(['account_id' => ['An activity cannot be moved to another account.']]);
            }

            foreach (['type' => self::TYPES, 'status' => self::STATUSES, 'priority' => self::PRIORITIES] as $field => $allowed) {
                if (! in_array($activity->{$field}, $allowed, true)) {
                    throw ValidationException::withMessages([$field => ["The selected {$field} is invalid."]]);
                }
            }

            $activity->subject = trim((string) $activity->subject);
            if ($activity->subject === '') {
                throw ValidationException::withMessages(['subject' => ['The subject is required.']]);
            }

            // A brand-new activity must be attached to something. An existing one may lose
            // a link when that record is deleted (its history stays, unlinked).
            if (! $activity->exists && collect(self::LINKS)->every(fn (string $f) => $activity->{$f} === null)) {
                throw ValidationException::withMessages(['crm_lead_id' => ['Link the activity to a lead, contact, company or deal.']]);
            }

            $links = [];
            foreach (self::LINKS as $field) {
                if ($activity->isDirty([$field, 'account_id'])) {
                    $links[$field] = $activity->{$field};
                }
            }
            CrmTenantLinks::assertBelong((int) $activity->account_id, $links);

            if ($activity->assigned_user_id !== null && $activity->isDirty(['assigned_user_id', 'account_id'])
                && ! CrmLead::assigneeIsEligibleById((int) $activity->assigned_user_id, (int) $activity->account_id)) {
                throw ValidationException::withMessages(['assigned_user_id' => ['The selected assignee is not available.']]);
            }

            if (($activity->status === self::STATUS_DONE) !== ($activity->completed_at !== null)) {
                throw ValidationException::withMessages(['completed_at' => ['Only a done activity records when it was completed, and it must.']]);
            }
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'crm_lead_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(CrmCompany::class, 'crm_company_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(CrmDeal::class, 'crm_deal_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
