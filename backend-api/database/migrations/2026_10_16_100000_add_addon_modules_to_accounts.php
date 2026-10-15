<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QA fixes (2026-10-14) — paid module add-ons get their own list.
 *
 * A paid module add-on used to be written INTO accounts.allowed_modules:
 * [old ?? [], module]. For an account with allowed_modules = null ("every
 * module") that produced [module] — and for a client on a plan
 * (modules_from_plan) the plan's modules were then narrowed to that single
 * module, so buying Custom Contact Groups wiped the client's whole menu.
 * Add-on modules now live in accounts.addon_modules and are ADDED on top of
 * what the plan / checklist gives (Account::resolveModules).
 *
 * Repair: a client whose allowed_modules holds only add-on modules (the
 * shape the bug leaves behind) gets allowed_modules = null back — for a plan
 * client also the empty list a term end left. Add-on modules with a paid
 * term still running move to addon_modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounts', 'addon_modules')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->json('addon_modules')->nullable()->after('allowed_modules');
            });
        }

        if (! Schema::hasTable('module_addon_offers')) {
            return;
        }

        $offerModules = DB::table('module_addon_offers')->pluck('module')->map(fn ($m) => (string) $m)->all();
        $offerModules[] = 'contact_groups';

        $running = Schema::hasTable('module_addon_requests')
            ? DB::table('module_addon_requests')
                ->where('status', 'paid')
                ->where('term_ends_at', '>', now())
                ->get(['account_id', 'module'])
                ->groupBy('account_id')
            : collect();

        DB::table('accounts')->where('account_type', 'client')->orderBy('id')->each(function ($row) use ($offerModules, $running) {
            $allowed = $row->allowed_modules === null ? null : json_decode((string) $row->allowed_modules, true);
            $updates = [];

            if (is_array($allowed)) {
                $onlyAddons = array_diff($allowed, $offerModules) === [];
                if (($onlyAddons && $allowed !== []) || ($allowed === [] && (bool) ($row->modules_from_plan ?? false))) {
                    $updates['allowed_modules'] = null;
                }
            }

            $addons = collect($running->get($row->id, []))
                ->pluck('module')
                ->reject(fn ($m) => $m === 'contact_groups')
                ->unique()->values()->all();
            if ($addons !== []) {
                $updates['addon_modules'] = json_encode($addons);
            }

            if ($updates !== []) {
                DB::table('accounts')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounts', 'addon_modules')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropColumn('addon_modules');
            });
        }
    }
};
