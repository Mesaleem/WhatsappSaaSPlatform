<?php

namespace App\Models;

use App\Models\Concerns\HasJourneyGraph;
use App\Models\Concerns\MasksJourneySecrets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Phase 7 Task 2 — one immutable saved graph of a journey. See the
 * 2026_09_24_130000 migration.
 *
 * IMMUTABLE: once inserted a version is never updated or deleted through
 * the model (both throw). A session pinned to it (flow_version_id) can
 * therefore rely on its graph forever. Rows disappear only through the
 * database cascade when the whole journey is deleted.
 *
 * Created only by JourneyVersionService.
 */
class WhatsAppFlowVersion extends Model
{
    use HasJourneyGraph;
    use MasksJourneySecrets;

    protected $table = 'whatsapp_flow_versions';

    protected $fillable = [
        'flow_id',
        'account_id',
        'version',
        'graph_data',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'graph_data' => 'array',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Journey versions are immutable; save a new version instead.');
        });

        static::deleting(function () {
            throw new LogicException('Journey versions are immutable and cannot be deleted individually.');
        });
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(WhatsAppFlow::class, 'flow_id');
    }

    public function scopeForAccount(Builder $query, int $accountId): Builder
    {
        return $query->where('account_id', $accountId);
    }
}
