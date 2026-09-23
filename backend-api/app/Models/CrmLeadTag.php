<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6 — CRM Task 7. One lead <-> tag assignment (a crm_lead_tags row).
 *
 * WHY A MODEL AND NOT ONLY belongsToMany()->attach(): assignments are CRM
 * data mutations and this project audits mutations through Eloquent model
 * events (LogsActivity). Writing each assignment as a model means attach
 * produces an activity_logs `create` row and detach a `delete` row, with
 * account_id taken from the row itself — no second audit table, no bespoke
 * log line. All writes go through CrmTagService; the relations on CrmLead
 * and CrmTag are for reading.
 *
 * COMPOSITE KEY: the table's primary key is (crm_lead_id, crm_tag_id).
 * Eloquent needs one key name to run delete(), so crm_lead_id is declared
 * and setKeysForSaveQuery() adds crm_tag_id — a delete therefore removes
 * exactly this one pair. Rows are never updated.
 *
 * TENANT GUARD: the composite foreign keys already make a cross-tenant row
 * unstorable; this guard fails first with the same non-identifying 422 the
 * rest of the CRM uses, rather than a raw driver error naming constraints.
 */
class CrmLeadTag extends Model
{
    use LogsActivity;

    protected string $auditModuleName = 'CRM';

    protected $table = 'crm_lead_tags';

    protected $primaryKey = 'crm_lead_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'crm_lead_id',
        'crm_tag_id',
        'account_id',
    ];

    protected function casts(): array
    {
        return [
            'crm_lead_id' => 'integer',
            'crm_tag_id' => 'integer',
            'account_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Task 9 — pairs a bulk operation has ALREADY proven belong to one
     * account (the leads selected by account_id under lock, the tag
     * resolved through forAccount()). Active only inside
     * withVerifiedPairs(); lets the creating guard skip re-querying the
     * same lead and tag once per row. Anything not in the set still takes
     * the full query path, and the composite foreign keys still apply.
     *
     * @var array{account: int, leads: array<int, true>, tags: array<int, true>}|null
     */
    private static ?array $verified = null;

    /**
     * @template T
     * @param list<int> $leadIds
     * @param list<int> $tagIds
     * @param callable(): T $callback
     * @return T
     */
    public static function withVerifiedPairs(int $accountId, array $leadIds, array $tagIds, callable $callback): mixed
    {
        $previous = self::$verified;
        self::$verified = [
            'account' => $accountId,
            'leads' => array_fill_keys($leadIds, true),
            'tags' => array_fill_keys($tagIds, true),
        ];

        try {
            return $callback();
        } finally {
            self::$verified = $previous;
        }
    }

    private static function isPreVerified(CrmLeadTag $row): bool
    {
        return self::$verified !== null
            && self::$verified['account'] === (int) $row->account_id
            && isset(self::$verified['leads'][(int) $row->crm_lead_id])
            && isset(self::$verified['tags'][(int) $row->crm_tag_id]);
    }

    protected static function booted(): void
    {
        static::creating(function (CrmLeadTag $row): void {
            $row->created_at ??= now();

            if (self::isPreVerified($row)) {
                return;
            }

            $leadBelongs = CrmLead::query()
                ->whereKey($row->crm_lead_id)
                ->where('account_id', $row->account_id)
                ->exists();

            $tagBelongs = CrmTag::query()
                ->whereKey($row->crm_tag_id)
                ->where('account_id', $row->account_id)
                ->exists();

            if (! $leadBelongs || ! $tagBelongs) {
                throw ValidationException::withMessages([
                    'tag' => ['The selected tag is not available.'],
                ]);
            }
        });

        static::updating(function (): void {
            throw new \LogicException('A lead tag assignment is immutable; detach and attach instead.');
        });
    }

    protected function setKeysForSaveQuery($query): Builder
    {
        return $query
            ->where('crm_lead_id', $this->getOriginal('crm_lead_id', $this->crm_lead_id))
            ->where('crm_tag_id', $this->getOriginal('crm_tag_id', $this->crm_tag_id));
    }
}
