<?php

namespace App\Models\Collections;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 11 Task 4 — a charge item assigned to one CRM contact. `amount_due` is a snapshot; paid /
 * outstanding / status are derived from the payments by BillingCollectionService, never stored.
 */
class CollectionChargeAssignment extends Model
{
    use LogsActivity;

    protected string $auditModuleName = 'Collections';

    /** @var array<int, string> */
    protected array $auditIdentity = ['id', 'account_id', 'contact_id', 'charge_item_id'];

    protected $table = 'collection_charge_assignments';

    protected $fillable = ['account_id', 'contact_id', 'charge_item_id', 'amount_due', 'due_date', 'assigned_by_user_id', 'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason'];

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'contact_id' => 'integer', 'charge_item_id' => 'integer', 'due_date' => 'date:Y-m-d', 'cancelled_at' => 'datetime'];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where($query->getModel()->getTable().'.account_id', $accountId);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CollectionChargeItem::class, 'charge_item_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CollectionPayment::class, 'charge_assignment_id');
    }
}
