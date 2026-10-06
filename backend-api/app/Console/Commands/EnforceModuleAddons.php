<?php

namespace App\Console\Commands;

use App\Services\Billing\ModuleAddonService;
use Illuminate\Console\Command;

/** php artisan modules:enforce-addons — switches off paid modules whose term has ended. Hourly. */
class EnforceModuleAddons extends Command
{
    protected $signature = 'modules:enforce-addons';

    protected $description = 'Switch off paid module add-ons whose term has ended';

    public function handle(ModuleAddonService $service): int
    {
        $ended = $service->enforce();

        $this->info("Ended {$ended} module add-on term(s).");

        return self::SUCCESS;
    }
}
