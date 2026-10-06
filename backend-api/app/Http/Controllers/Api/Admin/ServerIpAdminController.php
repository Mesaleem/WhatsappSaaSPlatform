<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Access\EntitlementAuditLogger;
use App\Services\ApiAccess\ServerIpBindingService;
use Illuminate\Http\JsonResponse;

/**
 * Support's tools for a client's server IP. A Super Admin can reset the IP edit count, so a shared-hosting client
 * whose host changed the outbound IP gets one free change again. Every reset is written to the audit trail.
 */
class ServerIpAdminController extends Controller
{
    public function __construct(private readonly ServerIpBindingService $serverIp)
    {
    }

    /** POST /api/admin/accounts/{id}/reset-ip-edit-count */
    public function resetEditCount(int $id): JsonResponse
    {
        $account = Account::query()->findOrFail($id);
        $before = (int) $account->ip_edit_count;

        $this->serverIp->resetEditCount($account);

        app(EntitlementAuditLogger::class)->record($account, true, [
            'action' => 'api_server_ip.edit_count_reset',
            'resource_type' => 'account',
            'source' => 'admin',
            'category' => 'server_ip',
            'before' => $before,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'The IP edit count was reset. The client can change the server IP once for free now.',
            'server_ip' => $this->serverIp->status($account->refresh()),
        ]);
    }
}
