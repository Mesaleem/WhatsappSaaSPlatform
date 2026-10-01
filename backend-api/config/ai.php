<?php

/*
|--------------------------------------------------------------------------
| AI foundation (Phase 8 — AI Foundation & Provider Abstraction)
|--------------------------------------------------------------------------
|
| The single place that selects and configures the AI provider behind
| App\Services\Ai\AiManager. Business code depends on the
| App\Services\Ai\Contracts\AiProvider contract, never on a vendor.
|
| Credentials come ONLY from the environment. The keys below reuse the
| env variables config/services.php already declares for these vendors
| (OPENAI_API_KEY, ANTHROPIC_API_KEY, ...), so no secret is duplicated.
| Nothing in this file is ever returned by an API response.
|
*/

return [

    // Provider used when a caller does not ask for one. null/'' = AI off
    // (every call fails with AI_PROVIDER_NOT_CONFIGURED — safe default).
    'default' => env('AI_PROVIDER'),

    // Providers that may be resolved at all. A configured-but-not-enabled
    // provider is refused (AI_PROVIDER_UNAVAILABLE).
    // Phase 8 Task 8: gemini added to the default list (still needs its key
    // and AI_PROVIDER=gemini, or an explicit per-call provider, to be used).
    'enabled' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_ENABLED_PROVIDERS', 'openai,anthropic,gemini'))))),

    // Per-request HTTP timeout, seconds.
    'timeout' => (int) env('AI_TIMEOUT', 30),

    // Automatic retries of a CONNECTION failure only (never of a provider
    // answer, which may already have been billed by the vendor), with no
    // sleep between attempts. 0 = no retry (default).
    'retries' => (int) env('AI_RETRIES', 0),

    // Log channel for AI operation records (metadata only — see
    // App\Services\Ai\AiOperationLogger). null = the default channel.
    'log_channel' => env('AI_LOG_CHANNEL'),

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('AI_OPENAI_MODEL', env('OPENAI_MODEL', 'gpt-4o-mini')),
            // Phase 8 Task 9 — embeddings (knowledge bases)
            'embedding_model' => env('AI_OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
            'embedding_dimensions' => env('AI_OPENAI_EMBEDDING_DIMENSIONS'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            // Without the version segment (Anthropic SDK convention); the
            // provider calls /v1/messages.
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
            'model' => env('AI_ANTHROPIC_MODEL', env('ANTHROPIC_MODEL', 'claude-3-5-haiku-latest')),
            'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
        ],
        // Phase 8 Task 8 — Google Gemini (generateContent). The key reuses the
        // platform env variable config/services.php already declares
        // (GEMINI_API_KEY). Platform-level only: the deprecated per-tenant
        // accounts.gemini_api_key / social-provider vault key are NOT read.
        // The model deliberately does not fall back to the legacy
        // GEMINI_MODEL (services.gemini, default the retired gemini-1.5-pro).
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
            'api_version' => env('GEMINI_API_VERSION', 'v1beta'),
            'model' => env('AI_GEMINI_MODEL', 'gemini-3.5-flash'),
            // Phase 8 Task 9 — embeddings (knowledge bases)
            'embedding_model' => env('AI_GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
            'embedding_dimensions' => env('AI_GEMINI_EMBEDDING_DIMENSIONS'),
        ],
    ],

    /*
    | Phase 8 Task 5 — AI credit pricing. The ONLY place an AI operation is
    | converted into credits (App\Services\Ai\Billing\AiCreditPricing).
    |
    |   charge   = max(minimum_per_operation, ceil((input + output tokens) / tokens_per_credit))
    |   estimate = the same formula over an UPPER BOUND of the request
    |              (prompt+system bytes + max_tokens) — the hold taken before
    |              the call; the settled charge never exceeds it.
    |
    | tokens_per_credit_overrides: "provider:model" or "provider" => tokens per
    | credit, for when a model must be priced differently. Empty by default.
    |
    | missing_usage: what to charge when the vendor reports no token usage —
    |   'minimum'     minimum_per_operation (never over-charges; default)
    |   'reservation' the whole hold (the upper bound)
    | Either way the operation is recorded with usage_reported = false and a
    | warning is logged.
    |
    | OWNER DECISION: these values are placeholders chosen for this task; the
    | existing credit system (Phase 8 Tasks 1–3) defines no AI price.
    */
    'credits' => [
        'tokens_per_credit' => max(1, (int) env('AI_TOKENS_PER_CREDIT', 1000)),
        'minimum_per_operation' => max(0, (int) env('AI_MIN_CREDITS_PER_OPERATION', 1)),
        'tokens_per_credit_overrides' => [],
        'missing_usage' => env('AI_MISSING_USAGE_CHARGE', 'minimum'),
        // A run with no outcome after this long is abandoned by
        // `ai:settle-operations` (hold released, nothing charged). Must exceed
        // timeout × (retries + 1).
        'stale_after_seconds' => max(60, (int) env('AI_STALE_AFTER_SECONDS', 900)),
    ],

    /*
    | Phase 8 Task 7 — Journey AI nodes (prompt, agent). Bounds applied to
    | every node execution (App\Services\WhatsApp\JourneyAiNodeRunner):
    |
    |   max_tokens        the output cap sent with each request (also caps
    |                     the credit hold, which is priced from it)
    |   max_input_chars   the rendered prompt / instructions / input are cut
    |                     to this many characters each before the call
    |   max_output_chars  the stored reply is cut to this (a WhatsApp text
    |                     body holds at most 4096)
    |   allowed_models    the node's optional "model hint" is honoured only
    |                     when it is listed here (per provider: "provider:model"
    |                     or a bare model name); otherwise the provider's
    |                     configured model is used. Empty by default, so a
    |                     tenant can never pick a model the platform has not
    |                     priced.
    */
    'journey' => [
        'max_tokens' => max(1, (int) env('AI_JOURNEY_MAX_TOKENS', 500)),
        'max_input_chars' => max(100, (int) env('AI_JOURNEY_MAX_INPUT_CHARS', 4000)),
        'max_output_chars' => max(1, min(4096, (int) env('AI_JOURNEY_MAX_OUTPUT_CHARS', 4096))),
        // Phase 8 Task 10 — rag node: most characters of retrieved passages
        // put into one generation request (passages beyond it are dropped).
        'max_context_chars' => max(500, (int) env('AI_JOURNEY_MAX_CONTEXT_CHARS', 12000)),
        'allowed_models' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_JOURNEY_ALLOWED_MODELS', ''))))),
    ],

    /*
    | Phase 8 Task 11 — registered agents (App\Services\Ai\Agents). Every limit
    | bounds ONE execution (one visit of a Journey `agent` node). An agent's
    | own settings may only LOWER these, never raise them.
    |
    |   max_model_calls        model iterations (each a separate metered
    |                          operation, key …:m{n}); hard cap 8
    |   max_tool_calls         tool executions; hard cap 5
    |   max_execution_seconds  wall-clock budget of one attempt; exceeded →
    |                          the step is retried from its persisted progress
    |   max_output_chars       the final reply is cut to this
    |   max_input_chars        the task input (node input variable) is cut to this
    |   max_instructions_chars longest agent system instructions accepted
    |   max_tool_result_chars  a tool result given back to the model is cut to this
    |   max_context_chars      hard ceiling of one model request's prompt
    |   max_tokens             max output tokens of one model call
    |   tools                  the platform allow-list of tool ids an agent may
    |                          be granted (ToolRegistry); an id not listed here
    |                          cannot be granted or executed
    */
    'agents' => [
        'max_model_calls' => max(1, min(8, (int) env('AI_AGENT_MAX_MODEL_CALLS', 4))),
        'max_tool_calls' => max(0, min(5, (int) env('AI_AGENT_MAX_TOOL_CALLS', 3))),
        'max_execution_seconds' => max(5, min(300, (int) env('AI_AGENT_MAX_EXECUTION_SECONDS', 60))),
        'max_output_chars' => max(1, min(4096, (int) env('AI_AGENT_MAX_OUTPUT_CHARS', 2000))),
        'max_input_chars' => max(100, min(8000, (int) env('AI_AGENT_MAX_INPUT_CHARS', 4000))),
        'max_instructions_chars' => max(200, min(20000, (int) env('AI_AGENT_MAX_INSTRUCTIONS_CHARS', 8000))),
        'max_tool_result_chars' => max(200, min(8000, (int) env('AI_AGENT_MAX_TOOL_RESULT_CHARS', 2000))),
        'max_context_chars' => max(1000, min(64000, (int) env('AI_AGENT_MAX_CONTEXT_CHARS', 24000))),
        'max_tokens' => max(1, (int) env('AI_AGENT_MAX_TOKENS', 600)),
        'tools' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_AGENT_TOOLS', 'journey.variable.get,crm.lead.find_current,crm.lead.capture_current,crm.lead.update_status'))))),
    ],

    /*
    | Phase 8 Task 9 — embeddings (App\Services\Ai\Contracts\EmbeddingProvider),
    | used by knowledge-base ingestion and retrieval. Selected independently
    | of the generation provider (Anthropic has no embedding API). Empty =
    | embeddings off: ingestion and search fail with AI_PROVIDER_NOT_CONFIGURED.
    | Billed through MeteredAiService::embed() with the same pricing.
    */
    'embeddings' => [
        'provider' => env('AI_EMBEDDING_PROVIDER'),
    ],

    /*
    | Phase 8 Task 9 — knowledge bases (App\Services\Knowledge).
    |
    |   max_document_chars   largest text document accepted (after extraction)
    |   chunk_chars          target chunk size in characters
    |   chunk_overlap_chars  characters repeated between consecutive chunks
    |   embedding_batch_size chunks per embedding request (one metered
    |                        operation each, ≤ EmbeddingRequest::MAX_INPUTS)
    |   max_search_chunks    most chunks the development vector store scans
    |                        per search (it is a brute-force scan — see
    |                        DatabaseVectorStore); a larger knowledge base
    |                        needs a real vector backend
    |   max_results          largest `limit` a retrieval may ask for
    |   processing_lease_seconds  a document stuck in 'processing' longer
    |                        than this may be claimed again
    |   max_query_chars      longest search query accepted
    */
    'knowledge' => [
        'max_document_chars' => max(1000, (int) env('KNOWLEDGE_MAX_DOCUMENT_CHARS', 200000)),
        'chunk_chars' => max(200, (int) env('KNOWLEDGE_CHUNK_CHARS', 1200)),
        'chunk_overlap_chars' => max(0, (int) env('KNOWLEDGE_CHUNK_OVERLAP_CHARS', 200)),
        'embedding_batch_size' => max(1, min(100, (int) env('KNOWLEDGE_EMBEDDING_BATCH_SIZE', 32))),
        'max_search_chunks' => max(100, (int) env('KNOWLEDGE_MAX_SEARCH_CHUNKS', 20000)),
        'max_results' => max(1, (int) env('KNOWLEDGE_MAX_RESULTS', 20)),
        'processing_lease_seconds' => max(60, (int) env('KNOWLEDGE_PROCESSING_LEASE_SECONDS', 900)),
        'max_query_chars' => max(10, (int) env('KNOWLEDGE_MAX_QUERY_CHARS', 2000)),
    ],

    // Defaults for a request that does not set them.
    'defaults' => [
        'max_tokens' => (int) env('AI_MAX_TOKENS', 1024),
        'temperature' => (float) env('AI_TEMPERATURE', 0.7),
    ],

];
