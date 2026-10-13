<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\Ai\Billing\AiDailyAllowance;
use App\Services\Credits\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Free daily AI credits (owner rule, 2026-10-13).
 *
 * status()       GET /api/ai/credit-status -- the header's AI credit chip for the signed-in
 *                user's OWN account (a client's or an agent's; never a selected
 *                sub-client): today's free credits + the paid wallet. A Super Admin is
 *                unlimited.
 * settings()     GET /api/admin/ai/free-daily-credits -- Super Admin only.
 * saveSettings() PUT /api/admin/ai/free-daily-credits -- Super Admin only: how many free
 *                AI credits a client account and an agent account get each day.
 */
class AiCreditStatusController extends Controller
{
    public function status(Request $request, AiDailyAllowance $daily, CreditService $credits): JsonResponse
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            return response()->json(['data' => ['unlimited' => true]]);
        }

        $account = $user->account;

        if (! $account) {
            return response()->json(['data' => null]);
        }

        $wallet = $credits->balance($account);

        return response()->json(['data' => [
            'unlimited' => false,
            'daily' => $daily->summary($account),
            'wallet_available' => (int) ($wallet['available'] ?? 0),
        ]]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => [
            'client_per_day' => max(0, (int) PlatformSetting::get(PlatformSetting::AI_FREE_DAILY_CLIENT)),
            'agent_per_day' => max(0, (int) PlatformSetting::get(PlatformSetting::AI_FREE_DAILY_AGENT)),
        ]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_per_day' => ['required', 'integer', 'min:0', 'max:1000000'],
            'agent_per_day' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        PlatformSetting::put(PlatformSetting::AI_FREE_DAILY_CLIENT, (string) $data['client_per_day']);
        PlatformSetting::put(PlatformSetting::AI_FREE_DAILY_AGENT, (string) $data['agent_per_day']);

        return response()->json(['message' => 'Free daily AI credits saved.', 'data' => $data]);
    }
}
