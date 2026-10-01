<?php

namespace App\Models\Collections;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 11 Task 4 — one recorded payment. Append-only: there is no update or delete path. */
class CollectionPayment extends Model
{
    use LogsActivity;

    public const METHODS = ['cash', 'upi', 'card', 'bank_transfer', 'cheque', 'other'];

    protected string $auditModuleName = 'Collections';

    /** @var array<int, string> */
    protected array $auditIdentity = ['id', 'account_id', 'charge_assignment_id'];

    protected $table = 'collection_payments';

    protected $fillable = ['account_id', 'charge_assignment_id', 'amount', 'payment_date', 'payment_method', 'reference', 'idempotency_key', 'recorded_by_user_id'];

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'charge_assignment_id' => 'integer', 'payment_date' => 'date:Y-m-d'];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where($query->getModel()->getTable().'.account_id', $accountId);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CollectionChargeAssignment::class, 'charge_assignment_id');
    }
}
