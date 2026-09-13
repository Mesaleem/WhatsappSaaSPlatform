<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;

/**
 * Message Logs Governance Fix — one-off data backfill, NOT scheduled
 * (see routes/console.php for the only scheduled command in this app).
 *
 * Message Logs was previously gated on the 'analytics' slug (a Core
 * Common module every account effectively shares), not its own
 * 'message_logs' slug now living under the WhatsApp Messaging Suite
 * (see Account::MODULES and frontend-app's types/account.ts
 * WHATSAPP_SUITE_MODULES). Account::hasModuleEnabled() treats a NULL
 * allowed_modules as "every module enabled", so accounts with no
 * restriction list need nothing — this command only touches accounts
 * that have an explicit, non-null allowed_modules array, i.e. ones a
 * Super Admin has actually restricted.
 *
 * For those restricted accounts, this preserves EXACTLY the access they
 * had a moment ago: an account only gains 'message_logs' here if it
 * already had 'analytics' (the literal old gate — true backward
 * compatibility) OR already had at least one WhatsApp Suite slug
 * (whatsapp_setup / send_alert / chatbot / templates / device_settings
 * — per this fix's explicit request: "existing accounts with WhatsApp
 * suite enabled automatically get message_logs pushed"). The union of
 * both conditions is deliberate and disclosed: 'analytics' alone
 * guarantees no regression from today's actual behavior; the WhatsApp
 * Suite condition additionally covers a tenant that has WhatsApp
 * features enabled but had 'analytics' switched off, who arguably
 * should have had Message Logs access already given what the page
 * shows. Neither condition is invented — both are named directly in
 * the request that created this command.
 *
 * Defaults to a dry run (prints what WOULD change, writes nothing).
 * Pass --apply to actually persist the changes.
 */
class BackfillMessageLogsModuleAccess extends Command
{
    protected $signature = 'accounts:backfill-message-logs-module {--apply : Actually persist the changes; without this flag, only a preview is printed}';

    protected $description = 'One-off: grant the new message_logs module slug to restricted accounts that already had equivalent access via analytics or the WhatsApp Suite.';

    /** Backend has no WHATSAPP_SUITE_MODULES constant (that grouping only exists in frontend-app/src/types/account.ts for the admin UI's Master Category checkbox) — mirrored here explicitly for this one-off, not promoted to a shared constant since nothing else backend-side needs the grouping. */
    private const WHATSAPP_SUITE_SLUGS = ['whatsapp_setup', 'send_alert', 'chatbot', 'templates', 'device_settings'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $accounts = Account::query()->whereNotNull('allowed_modules')->get();

        $this->info(sprintf('Scanning %d account(s) with a restricted allowed_modules list...', $accounts->count()));

        $toUpdate = [];
        foreach ($accounts as $account) {
            $modules = $account->allowed_modules ?? [];

            if (in_array('message_logs', $modules, true)) {
                continue; // already has it
            }

            $hadAnalytics = in_array('analytics', $modules, true);
            $hadWhatsappSuite = ! empty(array_intersect($modules, self::WHATSAPP_SUITE_SLUGS));

            if ($hadAnalytics || $hadWhatsappSuite) {
                $toUpdate[] = [
                    'account' => $account,
                    'reason' => $hadAnalytics && $hadWhatsappSuite
                        ? 'had analytics + WhatsApp Suite'
                        : ($hadAnalytics ? 'had analytics' : 'had WhatsApp Suite'),
                ];
            }
        }

        if (empty($toUpdate)) {
            $this->info('No accounts need updating. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line($apply ? 'Applying changes:' : 'DRY RUN — would update (pass --apply to persist):');
        foreach ($toUpdate as $row) {
            $this->line(sprintf('  #%d %s — %s', $row['account']->id, $row['account']->company_name, $row['reason']));
        }
        $this->line('');

        if (! $apply) {
            $this->comment(sprintf('%d account(s) would be updated. Re-run with --apply to persist.', count($toUpdate)));

            return self::SUCCESS;
        }

        foreach ($toUpdate as $row) {
            $account = $row['account'];
            $modules = $account->allowed_modules ?? [];
            $modules[] = 'message_logs';
            $account->update(['allowed_modules' => array_values($modules)]);
        }

        $this->info(sprintf('Updated %d account(s).', count($toUpdate)));

        return self::SUCCESS;
    }
}
