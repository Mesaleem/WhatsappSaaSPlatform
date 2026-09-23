<?php

namespace App\Models\Concerns;

/**
 * Phase 7 Task 2 — read helpers over a journey graph (`graph_data`:
 * {nodes: [...], edges: [...]}). Moved verbatim out of WhatsAppFlow so the
 * immutable WhatsAppFlowVersion offers exactly the same API, and the
 * engine can walk a session's PINNED version with the same calls it has
 * always made on the flow.
 */
trait HasJourneyGraph
{
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
