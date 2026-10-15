<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Lead routing for one account (QA fixes, 2026-10-14). See the crm_lead_settings migration. */
class CrmLeadSetting extends Model
{
    public const ASSIGN_MODES = ['none', 'owner', 'round_robin'];

    protected $fillable = ['account_id', 'assign_mode', 'notify_assignee', 'whatsapp_inbound_leads', 'last_assigned_user_id'];

    protected function casts(): array
    {
        return [
            'notify_assignee' => 'boolean',
            'whatsapp_inbound_leads' => 'boolean',
            'last_assigned_user_id' => 'integer',
        ];
    }

    public static function for(int $accountId): self
    {
        return self::query()->firstOrNew(['account_id' => $accountId], [
            'assign_mode' => 'none',
            'notify_assignee' => true,
            'whatsapp_inbound_leads' => false,
        ]);
    }
}
