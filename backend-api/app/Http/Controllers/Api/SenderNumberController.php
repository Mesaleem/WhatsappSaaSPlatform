<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Services\WhatsApp\SenderNumberResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The WhatsApp numbers a send can go out from: this account's linked, active numbers only, default first. */
class SenderNumberController extends Controller
{
    use ResolvesTenantAccount;

    public function __construct(private readonly SenderNumberResolver $senders)
    {
    }

    /** GET /api/alerts/sender-numbers */
    public function index(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client/tenant account first (pass ?account_id=).');

        $numbers = $this->senders->activeNumbers($account)->map(fn ($number) => [
            'id' => $number->id,
            'phone_number' => $number->phone_number,
            'is_default' => (bool) $number->is_default,
        ]);

        return response()->json(['data' => $numbers->values()]);
    }
}
