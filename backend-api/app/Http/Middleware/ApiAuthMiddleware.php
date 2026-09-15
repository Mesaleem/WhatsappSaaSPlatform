<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Developer API Platform for WhatsApp Group Creation & Unified
 * Messaging -- requirement 1's dual-factor `x-api-key` + `x-api-secret`
 * auth, scoped ONLY to the two new endpoints this feature introduces
 * (POST /api/v1/whatsapp/groups/create, POST /api/v1/whatsapp/messages/send
 * -- see routes/api.php's 'auth.apisecret' group). Deliberately a NEW,
 * separate middleware/alias rather than an edit to AuthenticateApiKey
 * ('auth.apikey'): every pre-existing external route
 * (/v1/messages/send-payment-alert, /v1/messages/send-template,
 * /v1/send-message) stays on the original single-factor check, unchanged
 * -- adding a mandatory second header there would break every already-
 * integrated caller of those routes. See ApiKey's creating-secret
 * migration and ApiKeyController::store()/regenerateSecret() for how the
 * secret itself is issued.
 *
 * Accepts the key/secret as `X-API-KEY`/`X-API-SECRET` headers (this
 * feature's own literal spec) -- does NOT also accept the legacy
 * `Authorization: Bearer <key>` form AuthenticateApiKey supports, since
 * that single-token form has nowhere to carry a second factor.
 *
 * On success, attaches to the request (read by the two new V1
 * controllers below):
 *   $request->attributes->get('api_key')         -> ApiKey
 *   $request->attributes->get('api_account_id')  -> int
 *   $request->attributes->get('api_agent_id')    -> int|null   (Account::agent_id)
 *   $request->attributes->get('api_engine_type') -> string|null ('qr'|'meta'|null, from currentSubscription)
 *
 * SECURITY: both the key and the secret are SHA-256-hashed before their
 * respective DB lookups/comparisons (ApiKey::hashKey()/isSecretValid(),
 * the latter using hash_equals() for a timing-safe compare) -- neither
 * plaintext is ever written to a query log or stored.
 */
class ApiAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextKey = trim((string) $request->header('X-API-KEY', ''));
        $plainTextSecret = trim((string) $request->header('X-API-SECRET', ''));

        if ($plainTextKey === '' || $plainTextSecret === '') {
            return $this->unauthorized('Both X-API-KEY and X-API-SECRET headers are required.');
        }

        $apiKey = ApiKey::where('key_hash', ApiKey::hashKey($plainTextKey))->first();

        if (! $apiKey) {
            return $this->unauthorized('Invalid API key or secret.');
        }

        if ($apiKey->isRevoked()) {
            return $this->unauthorized('This API key has been revoked.');
        }

        if ($apiKey->isExpired()) {
            return $this->unauthorized('This API key has expired.');
        }

        // [Disclosed]: a key created before this feature (or issued since
        // via the unchanged single-factor ApiKeyController::store() code
        // path -- there is none; store() now always sets a secret, see
        // its own docblock -- so in practice this only ever fires for a
        // pre-existing key) has secret_hash === null. isSecretValid()
        // returns false for a null secret_hash regardless of what the
        // caller sends, so this deliberately does not distinguish "wrong
        // secret" from "no secret provisioned yet" in the response --
        // doing so would let an attacker probe which keys have a secret
        // provisioned at all. The fix (regenerate-secret) is documented
        // on the Developer Portal, not leaked here.
        if (! $apiKey->isSecretValid($plainTextSecret)) {
            return $this->unauthorized('Invalid API key or secret.');
        }

        $account = Account::with(['currentSubscription'])->find($apiKey->account_id);

        if (! $account || ! $account->isAdministrativelyActive()) {
            return $this->unauthorized('The account associated with this API key is not active.');
        }

        // Best-effort usage tracking, same trade-off AuthenticateApiKey
        // already accepts for this same column.
        $apiKey->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('api_account_id', $account->id);
        $request->attributes->set('api_agent_id', $account->agent_id);
        $request->attributes->set('api_engine_type', $account->currentSubscription?->engine_type);

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['success' => false, 'error_code' => 'UNAUTHORIZED', 'message' => $message], 401);
    }
}
