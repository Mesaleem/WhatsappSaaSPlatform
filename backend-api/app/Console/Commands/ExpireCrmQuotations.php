<?php

namespace App\Console\Commands;

use App\Services\Crm\Sales\CrmDocumentService;
use Illuminate\Console\Command;

/** CRM Sales Phase 2 — sent quotations / proposals past valid_until become expired. */
class ExpireCrmQuotations extends Command
{
    protected $signature = 'crm:expire-quotations';

    protected $description = 'Mark CRM quotations and proposals past their validity as expired.';

    public function handle(CrmDocumentService $documents): int
    {
        $this->info('Expired '.$documents->expireDue().' document(s).');

        return self::SUCCESS;
    }
}
