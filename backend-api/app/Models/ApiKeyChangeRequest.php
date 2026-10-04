<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKeyChangeRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'account_id', 'api_key_id', 'current_binding_id', 'status', 'pending_slot', 'current_label', 'current_ip',
        'requested_label', 'requested_ip_policy', 'requested_ips', 'reason', 'requested_by_user_id',
        'decided_by_user_id', 'decided_at', 'decision_note', 'new_binding_id',
    ];

    protected function casts(): array
    {
        return ['requested_ips' => 'array', 'decided_at' => 'datetime'];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'api_key_id' => $this->api_key_id,
            'status' => $this->status,
            'current_label' => $this->current_label,
            'current_ip' => $this->current_ip,
            'requested_label' => $this->requested_label,
            'requested_ip_policy' => $this->requested_ip_policy,
            'requested_ips' => $this->requested_ips ?? [],
            'reason' => $this->reason,
            'decision_note' => $this->decision_note,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
