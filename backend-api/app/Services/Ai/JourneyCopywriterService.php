<?php

namespace App\Services\Ai;

use App\Services\Ai\Billing\MeteredAiService;
use App\Services\Ai\Data\AiRequest;
use App\Services\Ai\Data\AiResponse;
use App\Services\WhatsApp\JourneyActionConfig;
use App\Services\WhatsApp\JourneyConditionEvaluator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 8 Task 14 — "Generate with AI" for the Journey Builder
 * (AIJourneyController). A normal consumer of the central AI system,
 * sibling to TemplateCopywriterService (Task 13):
 *
 *   Journey Builder → MeteredAiService → AiService → AiManager → provider
 *
 * SCOPE, DELIBERATELY NARROW — read this before adding a node type.
 * This generates a DRAFT {nodes, edges} graph only. It never creates or
 * touches a WhatsAppFlow row: AIJourneyController hands the draft back to
 * the SAME JourneyCanvasEditor a human builds a journey in by hand, and
 * the EXISTING WhatsAppFlowController::store()/update() path — with its
 * unchanged validateFlow()/nodeConfigErrors()/conditionErrors()/
 * branchErrors()/JourneySecrets checks — is what actually saves it.
 * Nothing about publishing, activation, or the runtime engine is touched.
 *
 * SAFE_NODE_TYPES is a DELIBERATE SUBSET of WhatsAppFlow::PALETTE_NODE_TYPES
 * + the legacy executable types, not the full palette:
 *
 *   trigger, text, question, conditional, delay, prompt, agent,
 *   save_lead, human_intervention
 *
 * Excluded on purpose: every node type whose configuration is a REAL
 * WORLD IDENTIFIER or SECRET the model cannot know and must never invent
 * — media URLs (image/video/document/audio/sticker), Meta interactive
 * nodes (list/external_url/reply_button/location, location_request,
 * address_request),
 * api (outbound HTTP + JourneySecrets-masked credentials), payment,
 * template/catalog/product/flow (Meta-side ids), code (arbitrary
 * executable logic — a security surface, not a drafting one), email,
 * journey (sub-journey ids), rag (a specific knowledge base id). A
 * hallucinated id or secret in any of those is actively harmful (a dead
 * link, a fabricated catalog id, a credential-shaped string in a URL);
 * a plain conversational skeleton is not. Widening this set is a product
 * decision, not a prompt tweak — it needs its own review of what the
 * model could plausibly invent for that field.
 *
 * VALIDATION REUSES THE ENGINE'S OWN RULES, not a parallel copy:
 * JourneyActionConfig::error() and JourneyConditionEvaluator are the
 * exact classes WhatsAppFlowController::validateFlow() calls at save
 * time, called here in draft mode. A generated node that would fail
 * validateFlow() never reaches the caller — it is rejected and the
 * model is asked to produce another draft (accept callback), or the
 * request falls back to the deterministic skeleton below. The "exactly
 * one trigger" and "every conditional edge declares sourceHandle" checks
 * are NOT exposed as public methods on WhatsAppFlowController, so they
 * are re-stated here, deliberately small and mirroring that controller's
 * own CONDITIONAL_HANDLES logic exactly — see validateGraph()'s docblock.
 *
 * THE SAME ANTI-BUG THIS TASK WAS INSPIRED BY (Task 13's docblock, here
 * applied to a whole graph instead of one template body): the model's
 * answer is validated for "is this a usable, safe graph" and returned
 * UNCHANGED if so — never silently rewritten, trimmed to "closest known
 * pattern", or auto-repaired into something the model didn't actually
 * produce. A graph that fails validation is refused outright (or
 * replaced wholesale by the deterministic fallback on a provider
 * failure), never patched node-by-node.
 */
class JourneyCopywriterService
{
    public const OPERATION = 'journeys.ai_generate';

    /** Provider-side failures that degrade to the deterministic fallback (never an entitlement/credit refusal). */
    private const JOURNEY_FALLBACK = [
        AiException::PROVIDER_NOT_CONFIGURED,
        AiException::PROVIDER_UNAVAILABLE,
        AiException::PROVIDER_FAILED,
        AiException::PROVIDER_TIMEOUT,
        AiException::MALFORMED_RESPONSE,
    ];

    /** See this class's docblock — the deliberately narrow, secret/id-free node subset the model may generate. */
    public const SAFE_NODE_TYPES = ['trigger', 'text', 'question', 'conditional', 'delay', 'save_lead', 'prompt', 'agent', 'human_intervention'];

    /** Mirrors WhatsAppFlowController::DELAY_UNITS / CONDITIONAL_HANDLES exactly — see this class's docblock. */
    private const DELAY_UNITS = ['seconds', 'minutes', 'hours', 'days'];

    private const CONDITIONAL_HANDLES = ['true', 'false'];

    private const MAX_TOKENS = 2000;

    private const TEMPERATURE = 0.6;

    private const MAX_NODES = 20;

    public function __construct(private readonly MeteredAiService $metered)
    {
    }

    /**
     * @return array{provider: string, graph_data: array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}}
     *
     * @throws AiException authorization / credit / duplicate refusals, or an AI answer that
     *                      never produced a valid graph even after the provider call succeeded
     *                      (surfaced as AiException::malformedResponse() — same error family
     *                      a caller already handles for every other AI endpoint)
     */
    public function generate(
        AiAuthorization $authorization,
        string $operationKey,
        string $description,
        ?string $goal,
    ): array {
        $tokens = compact('description', 'goal');

        $request = new AiRequest(
            prompt: $this->userPrompt($tokens),
            system: $this->systemPrompt(),
            maxTokens: self::MAX_TOKENS,
            temperature: self::TEMPERATURE,
            operation: self::OPERATION,
            requiredKeys: ['nodes', 'edges'],
        );

        try {
            $result = $this->metered->generateStructured(
                $authorization,
                $request,
                $operationKey,
                accept: fn (AiResponse $response) => $this->validatedGraph($response->data) !== null,
            );

            $graph = $this->validatedGraph($result->response->data);

            if ($graph === null) {
                // The provider answered, but never produced a graph this
                // class can call valid — never pass through a half-built
                // or unsafe graph. Treated as the vendor's own
                // MALFORMED_RESPONSE family, same as every other AI
                // endpoint's "unusable answer" path.
                throw AiException::malformedResponse($result->response->provider);
            }

            return ['provider' => $result->response->provider, 'graph_data' => $graph];
        } catch (AiException $e) {
            if (! in_array($e->errorCode, self::JOURNEY_FALLBACK, true)) {
                throw $e;
            }

            Log::warning('JourneyCopywriterService: AI draft unavailable, serving the deterministic fallback.', [
                'account_id' => $authorization->account->id,
                'error_code' => $e->errorCode,
            ]);

            return ['provider' => 'template', 'graph_data' => $this->generateFromTemplate($tokens)];
        }
    }

    private function systemPrompt(): string
    {
        $types = implode(', ', self::SAFE_NODE_TYPES);
        $operators = implode(', ', JourneyConditionEvaluator::OPERATORS);

        return "You design WhatsApp chatbot conversation flows (\"journeys\") for a no-code journey builder. ".
            "Always reply with ONLY a JSON object of the exact shape {\"nodes\": [...], \"edges\": [...]} — no prose, ".
            "no markdown fences, no explanation.\n\n".
            "You may use ONLY these node \"type\" values: {$types}. Never invent any other type (no api, payment, ".
            "template, catalog, product, flow, code, email, media, or interactive-button nodes — those need real ".
            "ids, credentials, or media files this tool has no access to, so they are never part of a generated draft).\n\n".
            "Every node is an object: {\"id\": string (unique, short, no spaces), \"type\": one of the above, ".
            "\"position\": {\"x\": number, \"y\": number}, \"data\": object}. Lay nodes out top-to-bottom with position.x ".
            "around 40-80 and position.y increasing by about 160 per step down the conversation.\n\n".
            "Exactly ONE node must have type \"trigger\", and its data MUST be an empty object {}. Every journey ".
            "needs one and it starts the flow.\n\n".
            "Per-type \"data\" shape — use these keys exactly:\n".
            "  trigger: {} (always empty)\n".
            "  text: {\"text\": string} — a plain message sent to the customer\n".
            "  question: {\"prompt_text\": string, \"variable_name\": string, \"input_type\": \"text\"} — asks the ".
            "customer something and stores their reply in variable_name (only use input_type \"text\"; never ".
            "\"buttons\" or \"list\" — those need extra option objects this draft does not fill in)\n".
            "  prompt: {\"prompt\": string, \"outputVariable\": string} — an AI reply seeded by this system-style ".
            "instruction, stored in outputVariable; use for an open-ended AI answer to the customer\n".
            "  agent: {\"instructions\": string, \"outputVariable\": string} — a longer AI task with its own ".
            "instructions, stored in outputVariable\n".
            "  conditional: {\"match\": \"all\"|\"any\", \"conditions\": [{\"variable\": string, \"operator\": one of ".
            "[{$operators}], \"value\": string|number}]} — a branch point; its outgoing edges MUST each declare ".
            "sourceHandle \"true\" or \"false\" (see edges below)\n".
            "  delay: {\"amount\": number > 0, \"unit\": one of [\"seconds\",\"minutes\",\"hours\",\"days\"]} — a pause ".
            "before continuing\n".
            "  save_lead: {\"name_variable\": string|null, \"email_variable\": string|null, \"phone_variable\": ".
            "string|null, \"completion_message\": string|null} — saves previously-collected variables to the CRM; ".
            "only set the *_variable fields to a variable_name a question/prompt/agent node in this SAME graph ".
            "actually produced, or null\n".
            "  human_intervention: {} — hands the conversation to a human agent; always empty data\n\n".
            'Every edge is an object: {"id": string, "source": node id, "target": node id}. An edge leaving a '.
            '"conditional" node MUST also include "sourceHandle": "true" or "false" (exactly one edge of each, at '.
            "most). No other node type uses sourceHandle. Keep the graph small and linear where possible — at most ".
            self::MAX_NODES.' nodes — and make sure every node (other than natural end points) has at least one outgoing edge so the conversation never silently stops.';
    }

    /**
     * @param array{description: string, goal: ?string} $tokens
     */
    private function userPrompt(array $tokens): string
    {
        $goalLine = $tokens['goal'] !== null && trim($tokens['goal']) !== ''
            ? "The primary goal of this journey is: \"{$tokens['goal']}\".\n"
            : '';

        return sprintf(
            "Design a WhatsApp journey for this description: \"%s\".\n%s".
            'Produce the nodes and edges for this conversation flow now.',
            $tokens['description'],
            $goalLine,
        );
    }

    /**
     * The engine's own node-configuration and branch-identity rules,
     * applied in draft mode, PLUS the two checks WhatsAppFlowController
     * keeps private (restated here, not duplicated logic — each is a
     * single short loop, not a parallel validation engine):
     *
     *   - exactly one 'trigger' node, with empty data (mirrors
     *     validateFlow()'s own `$triggerNodes` count check)
     *   - every edge leaving a 'conditional' node declares sourceHandle
     *     "true" or "false" (mirrors branchErrors() exactly, including
     *     the same CONDITIONAL_HANDLES constant)
     *
     * Returns null on ANY problem — the model is asked to try again
     * (accept callback) rather than this method silently dropping or
     * repairing the offending node/edge itself. See this class's
     * docblock for why "never silently rewrite" matters here.
     *
     * @param array<string, mixed>|null $decoded
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}|null
     */
    private function validatedGraph(?array $decoded): ?array
    {
        $nodes = $decoded['nodes'] ?? null;
        $edges = $decoded['edges'] ?? null;

        if (! is_array($nodes) || ! array_is_list($nodes) || $nodes === [] || count($nodes) > self::MAX_NODES) {
            return null;
        }

        if (! is_array($edges) || ! array_is_list($edges)) {
            return null;
        }

        $seenIds = [];
        $triggerCount = 0;
        $cleanNodes = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                return null;
            }

            $id = $node['id'] ?? null;
            $type = $node['type'] ?? null;
            $data = $node['data'] ?? [];

            if (! is_string($id) || trim($id) === '' || isset($seenIds[$id])) {
                return null;
            }

            if (! is_string($type) || ! in_array($type, self::SAFE_NODE_TYPES, true)) {
                return null;
            }

            if (! is_array($data)) {
                return null;
            }

            $position = $node['position'] ?? null;
            $position = is_array($position) && is_numeric($position['x'] ?? null) && is_numeric($position['y'] ?? null)
                ? ['x' => (float) $position['x'], 'y' => (float) $position['y']]
                : ['x' => 40, 'y' => 160 * (count($cleanNodes) + 1)];

            if ($type === 'trigger') {
                $triggerCount++;
                $data = []; // never trust the model's trigger data — the contract is always empty.
            } elseif ($type === 'human_intervention') {
                $data = [];
            } elseif ($type === 'delay') {
                if (! is_numeric($data['amount'] ?? null) || (float) $data['amount'] <= 0) {
                    return null;
                }

                if (! in_array($data['unit'] ?? null, self::DELAY_UNITS, true)) {
                    return null;
                }
            } elseif ($type === 'conditional') {
                if (JourneyConditionEvaluator::conditionListErrors($data['conditions'] ?? [], $data['match'] ?? null, requireRules: false) !== []) {
                    return null;
                }
            } elseif (JourneyActionConfig::error($type, $data, draft: true) !== null) {
                return null;
            }

            $seenIds[$id] = true;
            $cleanNodes[] = ['id' => $id, 'type' => $type, 'position' => $position, 'data' => $data];
        }

        if ($triggerCount !== 1) {
            return null;
        }

        $nodeIds = $seenIds;
        $cleanEdges = [];

        foreach ($edges as $index => $edge) {
            if (! is_array($edge)) {
                return null;
            }

            $source = $edge['source'] ?? null;
            $target = $edge['target'] ?? null;

            if (! is_string($source) || ! isset($nodeIds[$source]) || ! is_string($target) || ! isset($nodeIds[$target])) {
                return null;
            }

            $clean = ['id' => is_string($edge['id'] ?? null) && trim($edge['id']) !== '' ? $edge['id'] : "edge-{$index}", 'source' => $source, 'target' => $target];

            $sourceType = null;

            foreach ($cleanNodes as $n) {
                if ($n['id'] === $source) {
                    $sourceType = $n['type'];

                    break;
                }
            }

            if ($sourceType === 'conditional') {
                if (! in_array($edge['sourceHandle'] ?? null, self::CONDITIONAL_HANDLES, true)) {
                    return null;
                }

                $clean['sourceHandle'] = $edge['sourceHandle'];
            }

            $cleanEdges[] = $clean;
        }

        return ['nodes' => $cleanNodes, 'edges' => $cleanEdges];
    }

    /**
     * Deterministic, dependency-free, zero-external-call fallback —
     * mirrors TemplateCopywriterService::generateFromTemplate()'s role: a
     * provider outage never blocks a user from getting a starting point.
     * A minimal, always-valid 3-node skeleton: trigger -> a text node
     * referencing the description -> save_lead. It passes
     * validatedGraph() by construction, so it is never charged for and
     * never needs a retry.
     *
     * @param array{description: string, goal: ?string} $tokens
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    private function generateFromTemplate(array $tokens): array
    {
        $greeting = trim(sprintf('Hi! %s', rtrim(Str::limit($tokens['description'], 200), '. ').'.'));

        $nodes = [
            ['id' => 'n1', 'type' => 'trigger', 'position' => ['x' => 40, 'y' => 40], 'data' => []],
            ['id' => 'n2', 'type' => 'text', 'position' => ['x' => 40, 'y' => 200], 'data' => ['text' => $greeting]],
            ['id' => 'n3', 'type' => 'save_lead', 'position' => ['x' => 40, 'y' => 360], 'data' => ['name_variable' => null, 'email_variable' => null, 'phone_variable' => null, 'completion_message' => null]],
        ];

        $edges = [
            ['id' => 'e1', 'source' => 'n1', 'target' => 'n2'],
            ['id' => 'e2', 'source' => 'n2', 'target' => 'n3'],
        ];

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
