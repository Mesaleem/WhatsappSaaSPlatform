<?php

namespace App\Models\Collections;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Phase 11 Task 4 — one named charge in an account's catalogue, owned by a calling module's `scope`. */
class CollectionChargeItem extends Model
{
    use LogsActivity;

    public const STATUSES = ['active', 'archived'];

    /** Descriptive only — nothing bills recurring from it. */
    public const FREQUENCIES = ['one_time', 'monthly', 'quarterly', 'half_yearly', 'yearly'];

    protected string $auditModuleName = 'Collections';

    /** @var array<int, string> */
    protected array $auditIdentity = ['id', 'account_id', 'name'];

    protected $table = 'collection_charge_items';

    protected $fillable = ['account_id', 'scope', 'name', 'description', 'amount', 'frequency', 'status'];

    protected function casts(): array
    {
        return ['account_id' => 'integer'];
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where($query->getModel()->getTable().'.account_id', $accountId);
    }
}
