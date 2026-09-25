<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\HasJourneyGraph;
use App\Models\Concerns\MasksJourneySecrets;
use App\Services\WhatsApp\JourneyVersionService;
use App\Support\JourneySecrets;
use App\Traits\LogsActivity;

/**
 * Module 5 — No-Code WhatsApp Journey Builder. See the creating
 * migration's docblock for the full trigger_type/graph_data contract.
 */
class WhatsAppFlow extends Model
{
    use HasJourneyGraph;
    use MasksJourneySecrets;
    use LogsActivity;

    /**
     * Phase 7 Task 2 — whether the version created by THIS save becomes the
     * published one (new sessions start on it). True by default, which is
     * exactly the pre-versioning behaviour of an edit; the API sets it to
     * false for a draft save. Not persisted; reset after every save.
     */
    public bool $publishOnSave = true;

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

    /**
     * P5-7: the complete list of RUNTIME-EXECUTABLE types (these five plus
     * the palette exceptions noted below) is JourneyNodeCatalog::
     * RUNTIME_EXECUTABLE_TYPES — that is the list publish/activation and the
     * engine enforce.
     *
     * The five node types WhatsAppJourneyEngine actually EXECUTES today.
     * Unchanged, and still the only types the engine has a branch for —
     * every saved flow keeps running exactly as it did.
     */
    public const EXECUTABLE_NODE_TYPES = ['trigger', 'message', 'question', 'condition', 'save_lead'];

    /**
     * Phase 5 — Journey / Automation: the 27-node palette.
     *
     * These are PERSISTABLE, not yet executable. The frontend node
     * registry (frontend-app/src/journey/nodeRegistry.tsx) is the single
     * source of this list on its side; this array is the server-side
     * allow-list, and a test asserts the two agree exactly so a node can
     * never be offered in the palette that the API would reject, or vice
     * versa.
     *
     * Storing a node of one of these types is allowed; executing one is a
     * later task's job. WhatsAppJourneyEngine::advance() has no branch for
     * them and treats an unrecognised type the way it always has, so
     * adding them here cannot change how any existing flow runs.
     *
     * EXCEPTION (Phase 7 Task 1): `delay` is executed by the temporal
     * backbone (WhatsAppJourneyEngine parks the session as 'waiting';
     * journeys:resume-due continues it). It stays listed here, not in
     * EXECUTABLE_NODE_TYPES, because this list must match the frontend
     * palette exactly and a type may not appear in both lists.
     *
     * EXCEPTION (Phase 7 Task 4): `conditional` is executed too — pure
     * control flow, no send (WhatsAppJourneyEngine + JourneyConditionEvaluator:
     * rules combined by match all/any, then the "true"/"false" handle). Same
     * listing rule as `delay`.
     *
     * EXCEPTION (Phase 7 Task 6): `text`, `image`, `video`, `document` and
     * `audio` are executed too — one outbound message each through the
     * unified driver (JourneyActionConfig::PALETTE_SEND_TYPES). Same
     * listing rule as `delay`.
     */
    public const PALETTE_NODE_TYPES = [
        // Message (7)
        'prompt', 'text', 'image', 'video', 'document', 'audio', 'sticker',
        // Interactive (6)
        'list', 'external_url', 'reply_button', 'location', 'location_request', 'address_request',
        // Advanced (10)
        'flow', 'api', 'payment', 'template', 'conditional', 'catalog', 'product', 'agent', 'rag', 'human_intervention',
        // Utility (4)
        'code', 'email', 'journey', 'delay',
    ];

    /**
     * Every type a flow may CONTAIN. The union, deliberately: dropping a
     * legacy type would make every saved journey unsaveable the moment a
     * tenant opened and re-saved it.
     */
    public const NODE_TYPES = [...self::EXECUTABLE_NODE_TYPES, ...self::PALETTE_NODE_TYPES];

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

    /**
     * Phase 7 Task 2 — every create, and every save that changes graph_data,
     * snapshots the stored graph into an immutable WhatsAppFlowVersion
     * (JourneyVersionService::snapshot()). Model-level so that EVERY writer —
     * the API, seeders, tests, future code — keeps versions in step; a save
     * that does not touch the graph (rename, toggle) creates nothing.
     */
    protected static function booted(): void
    {
        static::saved(function (self $flow) {
            if ($flow->wasRecentlyCreated || $flow->wasChanged('graph_data')) {
                app(JourneyVersionService::class)->snapshot($flow, auth()->id(), $flow->publishOnSave);
            }

            $flow->publishOnSave = true;
        });
    }

    /**
     * P5-6 — audit rows (LogsActivity) carry the graph with every credential
     * value masked: neither plaintext nor ciphertext reaches activity_logs.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditableAttributes(array $attributes): array
    {
        $attributes = array_diff_key($attributes, array_flip(array_merge($this->getHidden(), ['created_at', 'updated_at'])));

        if (array_key_exists('graph_data', $attributes)) {
            $attributes['graph_data'] = JourneySecrets::maskJson($attributes['graph_data']);
        }

        return $attributes;
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WhatsAppFlowVersion::class, 'flow_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(WhatsAppFlowVersion::class, 'published_version_id');
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
}
