<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateWhatsAppGroupRequest;
use App\Models\Account;
use App\Models\ContactGroup;
use App\Services\Groups\NativeGroupCreationService;
use Illuminate\Http\JsonResponse;

/**
 * Developer API Platform for WhatsApp Group Creation & Unified
 * Messaging -- requirement 2. Authenticated by ApiAuthMiddleware
 * ('auth.apisecret' -- X-API-KEY + X-API-SECRET), NOT the pre-existing
 * single-factor AuthenticateApiKey ('auth.apikey') every other Api\V1\*
 * controller uses -- see routes/api.php and ApiAuthMiddleware's own
 * docblock for why this is a deliberately separate, narrower-scoped
 * auth tier rather than an edit to the existing one.
 *
 * `native_wa_group` creation delegates to NativeGroupCreationService --
 * the EXACT SAME create-transaction + queue-CreateNativeWhatsAppGroupJob
 * logic ContactGroupController::store()'s internal, Sanctum-authenticated
 * counterpart already uses (see that service's own docblock) -- so a
 * group created via this external endpoint is indistinguishable, in the
 * database and in the internal Contact Groups UI, from one created by a
 * tenant's own dashboard user. `internal_segment` creation is the
 * "internal broadcast segment" path the spec's requirement 2 also names
 * -- a plain ContactGroup row, same as ContactGroupController::store()'s
 * own internal_segment branch.
 */
class GroupController extends Controller
{
    /** POST /api/v1/whatsapp/groups/create */
    public function create(CreateWhatsAppGroupRequest $request): JsonResponse
    {
        $accountId = (int) $request->attributes->get('api_account_id');
        $account = Account::with(['currentSubscription', 'whatsAppSession'])->find($accountId);

        if (! $account) {
            return response()->json(['success' => false, 'error_code' => 'NOT_FOUND', 'message' => 'Account not found.'], 404);
        }

        // Same paid-addon gate GroupMessageDispatcher/ContactGroupController
        // already enforce for every other contact-group action.
        if (! $account->hasModuleEnabled('contact_groups')) {
            return response()->json([
                'success' => false,
                'error_code' => 'GROUP_MODULE_DISABLED',
                'message' => 'Group Messaging is a paid feature. Please upgrade your subscription plan to unlock custom contact groups.',
            ], 403);
        }

        $data = $request->validated();
        $groupType = $data['group_type'] ?? ContactGroup::GROUP_TYPE_INTERNAL;

        if ($groupType !== ContactGroup::GROUP_TYPE_NATIVE) {
            $group = ContactGroup::create([
                'account_id' => $account->id,
                'name' => $data['name'],
                'is_default' => false,
                'group_type' => ContactGroup::GROUP_TYPE_INTERNAL,
            ]);

            return response()->json(['success' => true, 'data' => $group], 201);
        }

        // Native WhatsApp group -- requires the 'qr' (Baileys) engine and
        // a live, connected session; the official Meta Cloud API has no
        // group-messaging capability at all (see
        // NativeWhatsAppGroupService's class docblock). Same two
        // preconditions ContactGroupController::storeNativeGroup()
        // already enforces.
        $engineType = $account->currentSubscription?->engine_type;

        if ($engineType !== 'qr') {
            return response()->json([
                'success' => false,
                'error_code' => 'NATIVE_GROUP_REQUIRES_QR_ENGINE',
                'message' => 'Native WhatsApp Groups require the QR (Baileys) engine. The official Meta Cloud API does not support WhatsApp group messaging.',
            ], 422);
        }

        if ($account->whatsAppSession?->status !== 'connected') {
            return response()->json([
                'success' => false,
                'error_code' => 'WHATSAPP_NOT_CONNECTED',
                'message' => 'Connect your WhatsApp device first -- a live session is required to create a real WhatsApp group.',
            ], 422);
        }

        $group = NativeGroupCreationService::create($account, $data['name'], $data['contacts']);

        return response()->json(['success' => true, 'data' => $group], 201);
    }
}
