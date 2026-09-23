<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowVersion;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Task 2 — the only writer of whatsapp_flow_versions and of
 * whatsapp_flows.published_version_id.
 *
 * snapshot() runs after every save of a journey (WhatsAppFlow's saved
 * event). Under a row lock on the journey it compares the journey's
 * CURRENT stored graph with its latest version and, only if they differ,
 * inserts version max+1 — so:
 *   - an unchanged graph (rename, toggle, re-save) creates nothing;
 *   - two concurrent saves are serialized by the lock and numbered
 *     1, 2, 3 … with no duplicates (unique(flow_id, version) backs it up);
 *   - the version always holds what is actually stored, whichever save
 *     committed last.
 * With $publish (the default, and what the existing UI does) the journey's
 * published version moves to it, so NEW sessions use it. Sessions already
 * running stay on the version they were pinned to.
 */
class JourneyVersionService
{
    public function snapshot(WhatsAppFlow $flow, ?int $userId = null, bool $publish = true): ?WhatsAppFlowVersion
    {
        return DB::transaction(function () use ($flow, $userId, $publish) {
            /** @var WhatsAppFlow|null $locked */
            $locked = WhatsAppFlow::query()->whereKey($flow->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return null;
            }

            $latest = WhatsAppFlowVersion::query()->where('flow_id', $locked->id)->orderByDesc('version')->first();

            if ($latest && self::canonical($latest->graph_data) === self::canonical($locked->graph_data)) {
                if ($publish && ! $locked->published_version_id) {
                    $this->pointAt($locked, $latest);
                }

                $flow->setAttribute('published_version_id', $locked->published_version_id);
                $flow->syncOriginalAttribute('published_version_id');

                return null;
            }

            $version = WhatsAppFlowVersion::create([
                'flow_id' => $locked->id,
                'account_id' => $locked->account_id,
                'version' => ($latest?->version ?? 0) + 1,
                'graph_data' => $locked->graph_data,
                'created_by_user_id' => $userId,
            ]);

            if ($publish) {
                $this->pointAt($locked, $version);
            }

            $flow->setAttribute('published_version_id', $locked->published_version_id);
            $flow->syncOriginalAttribute('published_version_id');

            return $version;
        });
    }

    /** Make an existing version of this journey the one new sessions start on. */
    public function publish(WhatsAppFlow $flow, WhatsAppFlowVersion $version): void
    {
        abort_unless((int) $version->flow_id === (int) $flow->id, 404);

        DB::transaction(function () use ($flow, $version) {
            $locked = WhatsAppFlow::query()->whereKey($flow->getKey())->lockForUpdate()->firstOrFail();
            $this->pointAt($locked, $version);
        });

        $flow->setAttribute('published_version_id', $version->id);
        $flow->syncOriginalAttribute('published_version_id');
    }

    /** The newest version (the working copy's snapshot) — what a manual test runs. */
    public function latest(WhatsAppFlow $flow): ?WhatsAppFlowVersion
    {
        return WhatsAppFlowVersion::query()->where('flow_id', $flow->id)->orderByDesc('version')->first();
    }

    private function pointAt(WhatsAppFlow $locked, WhatsAppFlowVersion $version): void
    {
        // Query-builder update: no model events, so no recursive snapshot.
        WhatsAppFlow::query()->whereKey($locked->id)->update(['published_version_id' => $version->id]);
        $locked->published_version_id = $version->id;
    }

    /** Key order is not meaningful in a graph (MySQL's JSON type reorders keys); list order is. */
    private static function canonical(mixed $value): string
    {
        return json_encode(self::sortKeys($value));
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => self::sortKeys($v), $value);
    }
}
