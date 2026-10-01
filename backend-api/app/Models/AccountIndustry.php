<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 11 Task 1 — an account's membership of an industry (config/industries.php).
 * Assignment alone grants nothing: use also needs the capability, module, permission and subscription
 * (App\Services\Industry\IndustryAuthorizer).
 */
class AccountIndustry extends Model
{
    use LogsActivity;

    /** @var array<int, string> */
    protected array $auditIdentity = ['id', 'account_id', 'industry'];

    protected $fillable = ['account_id', 'industry', 'subtype', 'assigned_by_user_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
