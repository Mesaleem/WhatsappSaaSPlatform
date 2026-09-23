<?php

namespace App\Console\Commands;

use App\Services\Crm\PlatformCrmAccount;
use Illuminate\Console\Command;

/**
 * Creates (idempotently) the Super Admin's own CRM account
 * "Platform (Super Admin)" and grants it the crm capability. Same logic as
 * the 2026_09_23_130000 data migration; for fresh installs, where that
 * migration runs before the Super Admin role is seeded.
 */
class EnsurePlatformCrmAccount extends Command
{
    protected $signature = 'crm:platform-account';

    protected $description = "Create the Super Admin's own CRM account (Platform (Super Admin)) with the crm capability, if missing.";

    public function handle(PlatformCrmAccount $platform): int
    {
        $result = $platform->ensure();

        $this->info(sprintf(
            'Platform CRM account #%d "%s": %s; crm capability %s.',
            $result['account']->id,
            $result['account']->company_name,
            $result['created'] ? 'created' : 'already existed',
            $result['crm_granted'] ? 'granted now' : 'already present (or capability not seeded / revoked)',
        ));

        return self::SUCCESS;
    }
}
