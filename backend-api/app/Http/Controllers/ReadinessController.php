<?php

namespace App\Http\Controllers;

use App\Support\Observability\ReadinessProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /ready — readiness (can this instance serve API traffic?). `/up` stays the separate, dependency-free
 * liveness probe.
 *
 * Public body: `{"status":"ready"}` (200) or `{"status":"unavailable"}` (503) and nothing else.
 * Per-check detail (fixed status words only — see ReadinessProbe) is returned only to a caller presenting
 * `X-Ops-Token` equal to READINESS_DETAIL_TOKEN; with that unset the detail is never returned. Never cached.
 */
class ReadinessController extends Controller
{
    public function __invoke(Request $request, ReadinessProbe $probe): JsonResponse
    {
        $result = $probe->run();
        $body = ['status' => $result['ready'] ? 'ready' : 'unavailable'];

        if ($this->detailAllowed($request)) {
            $body['checks'] = $result['checks'];
        }

        return response()->json($body, $result['ready'] ? 200 : 503, ['Cache-Control' => 'no-store']);
    }

    private function detailAllowed(Request $request): bool
    {
        $expected = config('observability.readiness.detail_token');
        $given = $request->headers->get('X-Ops-Token');

        return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }
}
