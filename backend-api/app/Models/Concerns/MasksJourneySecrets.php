<?php

namespace App\Models\Concerns;

use App\Support\JourneySecrets;

/**
 * P5-6 — a journey graph never leaves the server with a secret in it.
 *
 * `saving`: credential values in the graph are encrypted (and a MASK sent
 * back by a client is resolved to the value already stored), for every
 * writer — API, seeders, services, tests.
 *
 * Serialization (`toArray()` / JSON responses): credential values are
 * replaced by JourneySecrets::MASK. The model's own `graph_data`
 * attribute keeps the stored (encrypted) form, so nothing that executes
 * or versions a journey sees a masked value.
 */
trait MasksJourneySecrets
{
    protected static function bootMasksJourneySecrets(): void
    {
        static::saving(function (self $model) {
            $graph = $model->graph_data;

            if (! is_array($graph)) {
                return;
            }

            $previous = $model->exists ? $model->getOriginal('graph_data') : null;
            $protected = JourneySecrets::protect($graph, is_array($previous) ? $previous : null);

            if ($protected !== $graph) {
                $model->graph_data = $protected;
            }
        });
    }

    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();

        if (isset($attributes['graph_data']) && is_array($attributes['graph_data'])) {
            $attributes['graph_data'] = JourneySecrets::mask($attributes['graph_data']);
        }

        return $attributes;
    }
}
