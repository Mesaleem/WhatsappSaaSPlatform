<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\LogsActivity;

/**
 * Module 5 — No-Code WhatsApp Journey Builder. See the creating
 * migration's docblock for the full trigger_type/graph_data contract.
 */
class WhatsAppFlow extends Model
{
    use LogsActivity;

    /**
     * Journey Builder Table Name Fix (2026-09-16) — [Bugfix, disclosed,
     * root cause]: this model never declared $table, so Eloquent derived
     * one from the class name via Str::snake(Str::pluralStudly('WhatsAppFlow')),
     * which yields 'whats_app_flows' (a word boundary is inserted before
     * every capital, so "Whats"+"App"+"Flow(s)" -> "whats_app_flows").
     * The creating migration (2026_09_11_200000_create_whatsapp_flows_table.php)
     * explicitly names the table 'whatsapp_flows' (no separating
     * underscore), so every query this model ran (JourneyBuilderPage's
     * list/create/update/delete, WhatsAppJourneyEngine's trigger lookup)
     * hit a table that never existed: "Base table or view not found:
     * 1146 Table 'whats_app_flows' doesn't exist". Declaring $table
     * explicitly points Eloquent at the table the migration actually
     * created — no schema change, no migration needed.
     */
    protected $table = 'whatsapp_flows';

    /** IMPLEMENT: Dynamic Route Master with Super-Admin Bypass & Global Audit Tracking — module label shown in the Activity Logs UI. */
    protected string $auditModuleName = 'WhatsApp Flows';
    public const TRIGGER_TYPES = ['keyword', 'ctwa_referral', 'default'];

    public const NODE_TYPES = ['trigger', 'message', 'question', 'condition', 'save_lead'];

    protected $fillable = [
        'account_id',
        'name',
        'trigger_type',
        'trigger_value',
        'graph_data',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'graph_data' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WhatsAppFlowSession::class, 'flow_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function nodes(): array
    {
        return $this->graph_data['nodes'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function edges(): array
    {
        return $this->graph_data['edges'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findNode(?string $nodeId): ?array
    {
        if ($nodeId === null) {
            return null;
        }

        foreach ($this->nodes() as $node) {
            if (($node['id'] ?? null) === $nodeId) {
                return $node;
            }
        }

        return null;
    }

    /**
     * The single node with type='trigger' — every graph's designated
     * entry point. Returns null for a malformed graph with none (or
     * more than one — the first is used, same "first match wins"
     * discipline as chatbot_rules).
     *
     * @return array<string, mixed>|null
     */
    public function triggerNode(): ?array
    {
        foreach ($this->nodes() as $node) {
            if (($node['type'] ?? null) === 'trigger') {
                return $node;
            }
        }

        return null;
    }

    /**
     * Outgoing edges from a given node id, in the order stored.
     *
     * @return array<int, array<string, mixed>>
     */
    public function outgoingEdges(string $nodeId): array
    {
        return array_values(array_filter(
            $this->edges(),
            fn (array $edge) => ($edge['source'] ?? null) === $nodeId
        ));
    }
}
