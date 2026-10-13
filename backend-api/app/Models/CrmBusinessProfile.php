<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CRM Sales Phase 2 — the tenant's seller details for its documents: legal name, GSTIN
 * and state code (CGST+SGST inside the state, IGST outside), address, bank / UPI and
 * default terms. One row per tenant; copied into each document when it is issued.
 */
class CrmBusinessProfile extends Model
{
    use LogsActivity;

    protected string $auditModuleName = 'CRM';

    protected $table = 'crm_business_profiles';

    protected $fillable = [
        'account_id', 'legal_name', 'gstin', 'state', 'state_code', 'address_line1', 'address_line2', 'city',
        'pincode', 'phone', 'email', 'bank_details', 'upi_id', 'default_terms', 'round_off', 'default_due_days',
    ];

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'round_off' => 'boolean', 'default_due_days' => 'integer'];
    }

    /** The two-digit GST state code: the stored one, else the GSTIN's first two digits. */
    public function stateCode(): ?string
    {
        if ($this->state_code) {
            return $this->state_code;
        }

        return $this->gstin ? substr($this->gstin, 0, 2) : null;
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
