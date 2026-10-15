<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResolvesTenantAccount;
use App\Http\Controllers\Controller;
use App\Models\CrmLeadSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * QA fixes (2026-10-14) — lead routing settings (CRM Sales Settings page).
 *
 *   GET /api/crm/lead-settings
 *   PUT /api/crm/lead-settings  { assign_mode, notify_assignee, whatsapp_inbound_leads }
 */
class CrmLeadSettingController extends Controller
{
    use ResolvesTenantAccount;

    public function show(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');

        return response()->json(['data' => $this->present(CrmLeadSetting::for($account->id))]);
    }

    public function update(Request $request): JsonResponse
    {
        $account = $this->requireAccount($request, 'Select a client account first (pass ?account_id=).');
        $data = $request->validate([
            'assign_mode' => ['required', Rule::in(CrmLeadSetting::ASSIGN_MODES)],
            'notify_assignee' => ['required', 'boolean'],
            'whatsapp_inbound_leads' => ['required', 'boolean'],
        ]);

        $settings = CrmLeadSetting::for($account->id);
        $settings->fill($data)->save();

        return response()->json(['message' => 'Lead settings saved.', 'data' => $this->present($settings)]);
    }

    /** @return array<string, mixed> */
    private function present(CrmLeadSetting $s): array
    {
        return [
            'assign_mode' => $s->assign_mode ?? 'none',
            'notify_assignee' => (bool) ($s->notify_assignee ?? true),
            'whatsapp_inbound_leads' => (bool) ($s->whatsapp_inbound_leads ?? false),
        ];
    }
}
