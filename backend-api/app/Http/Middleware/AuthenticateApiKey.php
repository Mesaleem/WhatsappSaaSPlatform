<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the external Developer API (Api\V1\*) via a Client API
 * Key, entirely independent of Sanctum/session auth — there is no human
 * User on these requests, only an Account. Accepts the key as either an
 * `X-API-KEY: <key>` header (Dynamic Template & API Integration Engine —
 * the header the Developer API Documentation box advertises) or the
 * pre-existing `Authorization: Bearer <key>` (Module 9 Developer Portal —
 * kept working unchanged so no existing integration breaks). On success,
 * the resolved ApiKey and Account are stashed on the request as
 * attributes for downstream controllers/rate-limiter to read:
 *   $request->attributes->get('api_key')          -> ApiKey
 *   $request->attributes->get('api_account_id')   -> int
 *
 * SECURITY: the incoming key is SHA-256 HASHED before the database
 * lookup (ApiKey::hashKey()) — the plaintext key is NEVER written to a
 * query log, and key_hash is what's actually stored (api_keys
 * migration), so this comparison never has the plaintext to leak even
 * transiently beyond this one request.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextKey = trim((string) $request->header('X-API-KEY', ''));

        if ($plainTextKey === '') {
            $authHeader = $request->header('Authorization', '');
            if (str_starts_with($authHeader, 'Bearer ')) {
                $plainTextKey = trim(substr($authHeader, 7));
            }
        }

        if ($plainTextKey === '') {
            return $this->unauthorized('Invalid or missing Client API Key.');
        }

        $apiKey = ApiKey::where('key_hash', ApiKey::hashKey($plainTextKey))->first();

        if (! $apiKey) {
            return $this->unauthorized('Invalid or missing Client API Key.');
        }

        if ($apiKey->isRevoked()) {
            return $this->unauthorized('This Client API Key has been revoked.');
        }

        if ($apiKey->isExpired()) {
            return $this->unauthorized('This Client API Key has expired.');
        }

        $account = Account::find($apiKey->account_id);

        if (! $account || ! $account->isAdministrativelyActive()) {
            return $this->unauthorized('The account associated with this Client API Key is not active.');
        }

        // Best-effort usage tracking — not wrapped in a transaction/lock;
        // losing an occasional touch under heavy concurrent use is an
        // acceptable trade-off for not adding write contention to every
        // single external API request.
        $apiKey->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('api_key', $apiKey);
        $request->attributes->set('api_account_id', $account->id);

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json(['status' => false, 'message' => $message], 401);
    }
}
