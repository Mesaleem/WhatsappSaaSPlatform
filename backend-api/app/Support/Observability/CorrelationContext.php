<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\Events\TokenAuthenticated;

/**
 * Adds the authenticated caller's ids to the log Context. Only integer ids are ever written — never a name,
 * email, token or other attribute. Called from Sanctum's TokenAuthenticated event (the user is already
 * resolved there, so no extra query), and read lazily by the JSON formatter for API-key requests.
 */
final class CorrelationContext
{
    public static function onTokenAuthenticated(TokenAuthenticated $event): void
    {
        $user = $event->token->tokenable ?? null;
        if ($user === null) {
            return;
        }

        $userId = $user->getKey();
        $accountId = $user->account_id ?? null;

        if (is_int($userId)) {
            Context::add('user_id', $userId);
        }
        if (is_int($accountId)) {
            Context::add('account_id', $accountId);
        }
    }
}
