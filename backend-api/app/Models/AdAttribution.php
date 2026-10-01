<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\LogsActivity;
use LogicException;

/**
 * Phase 10 Task 1 — one ad-click referral and the funnel steps it reached
 * (see the create_ad_attributions_table migration). Written only by
 * App\Services\Ads\AdAttributionService.
 *
 * Every linked row must belong to the SAME account (checked on save), so a
 * programming error can never attach one tenant's attribution to another
 * tenant's lead, journey session, campaign or WhatsApp number.
 */
class AdAttribution extends Model
{
    // Phase 10 Task 5 — conversion value edits by a signed-in user are audited (actor, time, old/new).
    // Webhook / journey / console writes have no actor and are not logged (see LogsActivity).
    use LogsActivity;

    protected string $auditModuleName = 'Ads Attribution';

    /** @var list<string> */
    protected array $auditIdentity = ['id', 'crm_lead_id'];

    public const PROVIDER_META = 'meta';

    public const CHANNEL_WHATSAPP_CTWA = 'whatsapp_ctwa';

    protected $fillable = [
        'account_id', 'provider', 'channel', 'source_type', 'source_id', 'click_id', 'referral_message_id',
        'whatsapp_session_id', 'contact_phone', 'ad_campaign_id', 'capture_lead_id', 'crm_lead_id', 'flow_session_id',
        'referral_received_at', 'lead_linked_at', 'journey_started_at', 'converted_at',
        'conversion_value', 'conversion_currency', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'referral_received_at' => 'datetime',
            'lead_linked_at' => 'datetime',
            'journey_started_at' => 'datetime',
            'converted_at' => 'datetime',
            'conversion_value' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /** @var array<string, class-string<Model>> */
    private const OWNED_LINKS = [
        'whatsapp_session_id' => WhatsAppSession::class,
        'ad_campaign_id' => AdCampaign::class,
        'capture_lead_id' => Lead::class,
        'crm_lead_id' => CrmLead::class,
        'flow_session_id' => WhatsAppFlowSession::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (AdAttribution $attribution): void {
            foreach (self::OWNED_LINKS as $column => $model) {
                if (! $attribution->isDirty($column) || $attribution->{$column} === null) {
                    continue;
                }

                $owner = $model::query()->whereKey($attribution->{$column})->value('account_id');

                if ((int) $owner !== (int) $attribution->account_id) {
                    throw new LogicException("AdAttribution {$column} must belong to the attribution's own account.");
                }
            }
        });
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }

    public function adCampaign(): BelongsTo
    {
        return $this->belongsTo(AdCampaign::class);
    }

    public function crmLead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class);
    }

    /** Derived (not stored): converted | ambiguous | attributed | unresolved. */
    public function diagnosticStatus(): string
    {
        if ($this->converted_at !== null) {
            return 'converted';
        }

        if (($this->metadata['match']['status'] ?? null) === 'ambiguous') {
            return 'ambiguous';
        }

        return $this->ad_campaign_id !== null || $this->crm_lead_id !== null || $this->flow_session_id !== null ? 'attributed' : 'unresolved';
    }
}
