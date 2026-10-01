<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;

/**
 * Phase 5 P5-C — Native WhatsApp Group actions require the account's
 * `whatsapp_groups` entitlement. Suites that exercise the LATER gates of
 * those actions (provider/engine, sync state, quota) grant it with this,
 * so they keep testing the gate they were written for.
 *
 * firstOrCreate on the capability row: some suites deliberately run on an
 * unseeded catalog (see UnifiedQuotaAndCapabilityTest).
 */
trait GrantsNativeWhatsAppGroups
{
    protected function grantNativeWhatsAppGroups(Account $account): void
    {
        $capability = Capability::firstOrCreate(
            ['slug' => 'whatsapp_groups'],
            ['label' => 'WhatsApp Groups', 'category' => 'whatsapp'],
        );

        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => $capability->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );
    }
}
