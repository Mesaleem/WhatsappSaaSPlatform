<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * CRM Sales Phase 1 — a customer's company (B2B). "Company", never "account": an
 * Account is the WapHub tenant. A company has many contacts and deals; an individual
 * customer simply has none.
 *
 * Same conventions as CrmLead: string + constant whitelists for type / status, the
 * invariants in the saving guard (so every writer is checked), LogsActivity for the
 * audit trail, forAccount() for tenant scoping. A company never moves between tenants.
 */
class CrmCompany extends Model
{
    use LogsActivity;

    protected string $auditModuleName = 'CRM';

    /** @var list<string> */
    protected array $auditIdentity = ['id'];

    protected $table = 'crm_companies';

    public const TYPE_PROSPECT = 'prospect';
    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_PARTNER = 'partner';

    /** @var list<string> */
    public const TYPES = [self::TYPE_PROSPECT, self::TYPE_CUSTOMER, self::TYPE_PARTNER];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $fillable = [
        'account_id', 'name', 'industry', 'website', 'gstin', 'phone', 'email',
        'address_line1', 'address_line2', 'city', 'state', 'pincode', 'country',
        'owner_user_id', 'type', 'status',
    ];

    protected $attributes = [
        'type' => self::TYPE_PROSPECT,
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'owner_user_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CrmCompany $company): void {
            if ($company->exists && $company->isDirty('account_id')) {
                throw ValidationException::withMessages(['account_id' => ['A company cannot be moved to another account.']]);
            }

            $company->name = trim((string) preg_replace('/\s+/u', ' ', (string) $company->name));
            if ($company->name === '') {
                throw ValidationException::withMessages(['name' => ['The company name is required.']]);
            }

            if ($company->gstin !== null) {
                $company->gstin = strtoupper(trim($company->gstin)) ?: null;
            }

            if (! in_array($company->type, self::TYPES, true)) {
                throw ValidationException::withMessages(['type' => ['The selected type is invalid.']]);
            }

            if (! in_array($company->status, self::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => ['The selected status is invalid.']]);
            }

            if ($company->owner_user_id !== null && $company->isDirty(['owner_user_id', 'account_id'])
                && ! CrmLead::assigneeIsEligibleById((int) $company->owner_user_id, (int) $company->account_id)) {
                throw ValidationException::withMessages(['owner_user_id' => ['The selected owner is not available.']]);
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'crm_company_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(CrmDeal::class, 'crm_company_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
