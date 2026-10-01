<?php

namespace App\Services\Ai;

use App\Models\Account;

/**
 * Phase 8 AI foundation — proof that one AI operation was authorized for
 * ONE target account. Produced only by AiAuthorizer and required by every
 * AiService call, so no AI provider call can happen without the full
 * check having run.
 *
 * $source ("manual", "automation", or a caller label such as
 * "journey") is for logs/audit only — it never grants anything.
 *
 * @internal construct through AiAuthorizer only.
 */
final class AiAuthorization
{
    public function __construct(
        public readonly Account $account,
        public readonly ?int $actorUserId,
        public readonly string $module,
        public readonly ?string $permission,
        public readonly string $source,
    ) {
    }
}
