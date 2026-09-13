<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 5 — No-Code WhatsApp Journey Builder. See the creating
 * migration's docblock for the full status/context_data contract.
 */
class WhatsAppFlowSession extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'account_id',
        'flow_id',
        'phone_number',
        'current_node_id',
        'context_data',
        'status',
        'last_interaction_at',
    ];

    protected function casts(): array
    {
        return [
            'context_data' => 'array',
            'last_interaction_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(WhatsAppFlow::class, 'flow_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * The one active session (if any) for a phone number under this
     * account — see the creating migration's docblock on why this is an
     * application-level "at most one" invariant, not a DB constraint.
     */
    public static function findActive(int $accountId, string $phoneNumber): ?self
    {
        return self::query()
            ->forAccount($accountId)
            ->where('phone_number', $phoneNumber)
            ->where('status', self::STATUS_ACTIVE)
            ->latest('id')
            ->first();
    }

    public function setVariable(string $name, mixed $value): void
    {
        $context = $this->context_data ?? [];
        $context[$name] = $value;
        $this->context_data = $context;
    }

    public function getVariable(string $name): mixed
    {
        return ($this->context_data ?? [])[$name] ?? null;
    }
}
