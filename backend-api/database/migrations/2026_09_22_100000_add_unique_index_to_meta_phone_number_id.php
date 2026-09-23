<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 Task 1 -- Meta WhatsApp Provider Foundation.
     *
     * whatsapp_sessions.meta_phone_number_id is the ONLY correlation key
     * Meta's inbound webhook payload offers: MetaWebhookController
     * resolves the owning tenant with
     *
     *     WhatsAppSession::query()
     *         ->where('meta_phone_number_id', $phoneNumberId)
     *         ->value('account_id');
     *
     * ...which takes an arbitrary first match. Nothing prevented two
     * tenants from saving the SAME phone_number_id, and if that ever
     * happened every inbound message for that number would be delivered
     * to whichever account the database returned first -- a silent
     * cross-tenant leak of message content, and the reason this index is
     * part of the provider foundation rather than a later hardening step.
     *
     * A Meta phone number belongs to exactly one WABA and therefore to
     * exactly one tenant here, so uniqueness is the correct shape for the
     * data, not merely a guard. The column stays nullable and both MySQL
     * and SQLite permit unlimited NULLs in a unique index, so every
     * account without Meta configured (including every QR account) is
     * completely unaffected.
     *
     * MetaConfigController::store() checks for the conflict first and
     * returns a 409 with an actionable message; this index is the
     * concurrency backstop behind that check, exactly as
     * unique(['account_id','payment_ref']) backs PaymentAlert::
     * isDuplicate() and unique(['account_id','idempotency_key']) backs
     * the Idempotency-Key claim.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('whatsapp_sessions', 'meta_phone_number_id')) {
            return;
        }

        if ($this->indexExists('whatsapp_sessions', 'whatsapp_sessions_meta_phone_number_id_unique')) {
            return;
        }

        // Refuse to apply over pre-existing duplicates rather than failing
        // with a raw driver error: an operator hitting this needs to know
        // WHICH numbers collide before deciding which tenant keeps them.
        $duplicates = DB::table('whatsapp_sessions')
            ->select('meta_phone_number_id')
            ->whereNotNull('meta_phone_number_id')
            ->where('meta_phone_number_id', '!=', '')
            ->groupBy('meta_phone_number_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('meta_phone_number_id')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Cannot add the unique index: these meta_phone_number_id values are shared by more than one account — '
                .implode(', ', $duplicates)
                .'. Resolve the duplicates (only one tenant may own a Meta phone number) and re-run this migration.'
            );
        }

        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->unique('meta_phone_number_id');
        });
    }

    public function down(): void
    {
        if (! $this->indexExists('whatsapp_sessions', 'whatsapp_sessions_meta_phone_number_id_unique')) {
            return;
        }

        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->dropUnique('whatsapp_sessions_meta_phone_number_id_unique');
        });
    }

    /** Same driver-aware helper the add_template_code migration already established. */
    private function indexExists(string $table, string $indexName): bool
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach (DB::select("PRAGMA index_list(\"{$table}\")") as $index) {
                if ($index->name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $result = DB::selectOne(
            'SELECT COUNT(1) AS cnt FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $indexName]
        );

        return (int) ($result->cnt ?? 0) > 0;
    }
};
