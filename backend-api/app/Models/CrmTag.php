<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;

/**
 * Phase 6 — CRM Task 7. A tenant-owned label for CRM leads ("Hot",
 * "Follow Up", "VIP"...). Generic: nothing in the code knows any tag name.
 *
 * TAGS ARE NOT STATUS. crm_leads.status (new / contacted / converted /
 * not_converted) remains the only lifecycle and pipeline field; a tag is
 * independent metadata used for classification and filtering. Attaching or
 * detaching one never writes to crm_leads.
 *
 * NAME RULES (single definition: normalize() + the saving guard below):
 *   - trimmed, internal whitespace runs collapsed to one space
 *     ("  Follow   Up " -> "Follow Up") — the stored display form
 *   - 1..NAME_MAX characters after that
 *   - duplicates are CASE-INSENSITIVE within one account
 *     ("Hot" / "hot" / "HOT" are the same tag) and the SAME name is
 *     allowed in different accounts
 *   - a duplicate is refused with a 422 on `name`; it is never silently
 *     renamed or merged
 * Validation lives in the model for the same reason CrmLead's does: the
 * rule then holds for every writer, not only the HTTP request class. The
 * unique(account_id, normalized_name) index is the race-proof backstop.
 */
class CrmTag extends Model
{
    use LogsActivity;

    /** Dynamic Route Master / Global Audit Tracking — same module label as CrmLead. */
    protected string $auditModuleName = 'CRM';

    public const NAME_MAX = 50;

    protected $table = 'crm_tags';

    protected $fillable = [
        'account_id',
        'name',
    ];

    /** Derived from `name`; excluded from serialization and from the audit diff. */
    protected $hidden = [
        'normalized_name',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
        ];
    }

    /** The stored display form: trimmed, whitespace runs collapsed. */
    public static function clean(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /** THE duplicate key: the cleaned name, lower-cased (multibyte-safe). */
    public static function normalize(string $name): string
    {
        return mb_strtolower(self::clean($name), 'UTF-8');
    }

    protected static function booted(): void
    {
        static::saving(function (CrmTag $tag): void {
            // A tag never changes owner — the same rule CrmLead enforces
            // for its account/contact pair.
            if ($tag->exists && $tag->isDirty('account_id')) {
                throw ValidationException::withMessages([
                    'account_id' => ['A tag cannot be moved to another account.'],
                ]);
            }

            $tag->name = self::clean((string) $tag->name);

            if ($tag->name === '') {
                throw ValidationException::withMessages([
                    'name' => ['The name field is required.'],
                ]);
            }

            if (mb_strlen($tag->name) > self::NAME_MAX) {
                throw ValidationException::withMessages([
                    'name' => ['The name field must not be greater than '.self::NAME_MAX.' characters.'],
                ]);
            }

            $tag->normalized_name = self::normalize($tag->name);

            $duplicate = self::query()
                ->where('account_id', $tag->account_id)
                ->where('normalized_name', $tag->normalized_name)
                ->when($tag->exists, fn (Builder $q) => $q->whereKeyNot($tag->getKey()))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'name' => [self::duplicateMessage()],
                ]);
            }
        });
    }

    public static function duplicateMessage(): string
    {
        return 'A tag with this name already exists.';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** The leads carrying this tag. Pivot rows are tenant-proven by composite FKs. */
    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(CrmLead::class, 'crm_lead_tags', 'crm_tag_id', 'crm_lead_id');
    }

    /** Mirrors CrmLead::scopeForAccount(). */
    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Adds `lead_count` as ONE correlated COUNT inside the same SELECT —
     * never one query per tag. Counts crm_lead_tags directly, without
     * joining crm_leads (which withCount('leads') would do): every
     * assignment row is tenant-proven and cascades away with its lead, so
     * the pivot count IS the lead count, served entirely from the
     * (crm_tag_id, account_id) index. Measured on MariaDB 10.11 with
     * 100k leads / 200k assignments in one tenant: see the Task 7 report.
     */
    public function scopeWithLeadCount(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('crm_tags.*');
        }

        return $query->selectSub(
            CrmLeadTag::query()
                ->selectRaw('COUNT(*)')
                ->whereColumn('crm_lead_tags.crm_tag_id', 'crm_tags.id')
                ->whereColumn('crm_lead_tags.account_id', 'crm_tags.account_id'),
            'lead_count',
        );
    }

    /** Deterministic list order: case-insensitive name, then id. */
    public function scopeOrderedByName(Builder $query): Builder
    {
        return $query->orderBy('normalized_name')->orderBy('id');
    }
}
