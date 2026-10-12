<?php

namespace App\Console\Commands;

use App\Rules\DeliverableEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Download the latest community list of temporary-mail domains
 * (github.com/disposable-email-domains) into storage/app, where
 * App\Rules\DeliverableEmail picks it up next to the copy shipped with the
 * code. A failed or suspicious download keeps the current file.
 */
class RefreshDisposableEmailDomains extends Command
{
    protected $signature = 'security:refresh-disposable-domains';

    protected $description = 'Update the list of temporary/disposable email domains refused at signup';

    private const SOURCE = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf';

    public function handle(): int
    {
        try {
            $response = Http::timeout(30)->get(self::SOURCE);
        } catch (\Throwable $e) {
            $this->error('Download failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('Download failed: HTTP '.$response->status());

            return self::FAILURE;
        }

        $domains = array_values(array_filter(
            array_map(fn ($l) => strtolower(trim($l)), explode("\n", $response->body())),
            fn ($d) => $d !== '' && preg_match('/^[a-z0-9.-]+\.[a-z0-9-]+$/', $d) === 1,
        ));

        // A real list has thousands of entries; refuse to replace it with something broken.
        if (count($domains) < 1000) {
            $this->error('The downloaded list looks wrong ('.count($domains).' domains); keeping the current one.');

            return self::FAILURE;
        }

        file_put_contents(storage_path('app/disposable_email_domains.txt'), implode("\n", $domains)."\n");
        DeliverableEmail::flush();
        $this->info('Saved '.count($domains).' disposable email domains.');

        return self::SUCCESS;
    }
}
