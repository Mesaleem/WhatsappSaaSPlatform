<?php

namespace App\Services\WhatsApp;

use App\Support\JourneyNodeCatalog;

/**
 * P5-7 — can this graph become the journey NEW sessions run on?
 *
 * Saving a DRAFT stays permissive (any palette node, half-built
 * configuration — WhatsAppFlowController::validateFlow()). PUBLISHING
 * (create/update with `publish` true, publishing a stored version) and
 * ACTIVATING a journey require a graph the runtime can execute end to end:
 *
 *   1. every node type has a runtime handler
 *      (JourneyNodeCatalog::RUNTIME_EXECUTABLE_TYPES);
 *   2. node ids are present and unique;
 *   3. every connection starts and ends at a node of this graph;
 *   4. every action node's configuration is complete — the engine's own
 *      run-time rule (JourneyActionConfig::error(..., draft: false)), and
 *      every branching node's rules as JourneyConditionEvaluator requires
 *      them at run time — so a published journey cannot fail on an empty
 *      message or rule it was allowed to publish with.
 *
 * Backend-enforced for every caller, the Super Admin included (a Super
 * Admin may manage a tenant's journeys; that does not make a graph the
 * runtime cannot execute executable). Messages name node ids/types only.
 */
final class JourneyPublishValidator
{
    /**
     * @param  array<int, mixed>  $nodes
     * @param  array<int, mixed>  $edges
     * @return array<string, array<int, string>>
     */
    public static function errors(array $nodes, array $edges): array
    {
        $errors = [];
        $ids = [];

        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                $errors["graph_data.nodes.{$i}"] = ['Every node must be an object.'];

                continue;
            }

            $type = $node['type'] ?? null;
            $id = isset($node['id']) && is_scalar($node['id']) ? (string) $node['id'] : '';

            if ($id === '') {
                $errors["graph_data.nodes.{$i}.id"] = ['Every node requires an id.'];
            } elseif (isset($ids[$id])) {
                $errors["graph_data.nodes.{$i}.id"] = ["Node id '{$id}' is used more than once."];
            } else {
                $ids[$id] = true;
            }

            if (! JourneyNodeCatalog::isRuntimeExecutable($type)) {
                $errors["graph_data.nodes.{$i}.type"] = [sprintf(
                    "The '%s' node cannot run yet, so this journey cannot be published. Remove it, or save the journey as a draft.",
                    is_string($type) ? $type : '?'
                )];

                continue;
            }

            $configError = JourneyActionConfig::error((string) $type, $node['data'] ?? [], draft: false);

            if ($configError !== null) {
                $errors["graph_data.nodes.{$i}.data"] = [$configError];
            }

            // Branching nodes: the rules the engine would refuse at run time.
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];

            if ($type === 'conditional') {
                $problems = JourneyConditionEvaluator::conditionListErrors($data['conditions'] ?? [], $data['match'] ?? null, requireRules: true);

                if ($problems !== []) {
                    $errors["graph_data.nodes.{$i}.data.conditions"] = $problems;
                }
            }

            if ($type === 'condition' && (! is_string($data['variable'] ?? null) || trim($data['variable']) === '')) {
                $errors["graph_data.nodes.{$i}.data.variable"] = ['A Condition node needs the variable to branch on.'];
            }
        }

        foreach ($edges as $j => $edge) {
            $source = is_array($edge) && isset($edge['source']) && is_scalar($edge['source']) ? (string) $edge['source'] : null;
            $target = is_array($edge) && isset($edge['target']) && is_scalar($edge['target']) ? (string) $edge['target'] : null;

            if ($source === null || $target === null || ! isset($ids[$source]) || ! isset($ids[$target])) {
                $errors["graph_data.edges.{$j}"] = ['This connection does not join two nodes of this journey.'];
            }
        }

        return $errors;
    }
}
