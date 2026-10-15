<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\MessageTemplate;
use App\Services\Templates\MetaTemplateSubmitter;
use App\Services\Templates\TemplateService;
use Illuminate\Console\Command;

/**
 * QA fixes (2026-10-14) — Meta's template verdicts, even without the webhook.
 *
 * Templates waiting for Meta (status pending_meta_approval / meta PENDING) and
 * approved Meta templates (which Meta can later pause or disable) are checked
 * against the client's WhatsApp Business Account; any change is applied like
 * the message_template_status_update webhook does.
 */
class SyncMetaTemplateStatuses extends Command
{
    protected $signature = 'templates:sync-meta';

    protected $description = 'Refresh the Meta review status of submitted WhatsApp templates.';

    public function handle(MetaTemplateSubmitter $meta, TemplateService $templates): int
    {
        $changed = 0;

        MessageTemplate::query()
            ->whereNotNull('meta_template_name')
            ->whereNotNull('account_id')
            ->where(fn ($q) => $q->where('status', 'pending_meta_approval')->orWhereIn('meta_template_status', ['PENDING', 'APPROVED', 'PAUSED']))
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($meta, $templates, &$changed) {
                foreach ($rows as $template) {
                    $account = Account::query()->find($template->account_id);
                    if ($account === null || $account->currentSubscription?->engine_type !== 'meta') {
                        continue;
                    }
                    $status = $meta->fetchStatus($template, $account);
                    if ($status !== null && $status !== $template->meta_template_status) {
                        $templates->applyMetaStatus($template, $status);
                        $changed++;
                    }
                }
            });

        $this->info("{$changed} template status change(s).");

        return self::SUCCESS;
    }
}
