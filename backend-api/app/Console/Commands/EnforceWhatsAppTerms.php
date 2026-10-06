<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\WhatsAppAddonService;
use Illuminate\Console\Command;

/**
 * php artisan whatsapp:enforce-terms — pauses extra numbers whose one-month term has
 * ended, and the included number while its plan is not active. Pausing never
 * removes a number. Runs hourly from routes/console.php.
 */
class EnforceWhatsAppTerms extends Command
{
    protected $signature = 'whatsapp:enforce-terms';

    protected $description = 'Pause WhatsApp numbers whose add-on term has ended or whose plan is not active';

    public function handle(WhatsAppAddonService $addons): int
    {
        $paused = $addons->enforceTerms();

        $this->info("Paused {$paused} WhatsApp number(s).");

        return self::SUCCESS;
    }
}
