<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Sales Phase 1 — additive, nullable columns only (no rename, no data change):
 *
 * contacts  crm_company_id (the person's company), designation, preferred_channel,
 *           marketing_opt_in / opted_out_at (consent; nothing reads them for sending yet).
 * crm_leads qualification (company_name, requirement, budget, timeline, temperature,
 *           next_follow_up_at), attribution (json: campaign / ad / utm / landing page;
 *           written by the system, not the user), and the conversion record
 *           (crm_company_id, converted_by_user_id, previous_lead_id for a lead created
 *           by "Re-engage" from a converted one).
 *
 * contacts.crm_company_id gets the composite tenant FK on MySQL/MariaDB (RESTRICT;
 * CrmCompanyService detaches before deleting). The crm_leads links carry none, like the
 * deal links (see 2026_10_14_100100).
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = $this->onMySql();

        Schema::table('contacts', function (Blueprint $table) use ($mysql) {
            $table->unsignedBigInteger('crm_company_id')->nullable()->after('email');
            $table->string('designation', 100)->nullable()->after('crm_company_id');
            $table->string('preferred_channel', 20)->nullable()->after('designation');
            $table->boolean('marketing_opt_in')->nullable()->after('preferred_channel');
            $table->timestamp('opted_out_at')->nullable()->after('marketing_opt_in');
            $table->index(['account_id', 'crm_company_id']);

            if ($mysql) {
                $table->foreign(['crm_company_id', 'account_id'], 'contacts_company_account_foreign')
                    ->references(['id', 'account_id'])->on('crm_companies');
            }
        });

        Schema::table('crm_leads', function (Blueprint $table) {
            $table->unsignedBigInteger('crm_company_id')->nullable()->after('contact_id');
            $table->unsignedBigInteger('previous_lead_id')->nullable()->after('crm_company_id');
            $table->foreignId('converted_by_user_id')->nullable()->after('converted_at')->constrained('users')->nullOnDelete();
            $table->string('company_name', 191)->nullable();
            $table->text('requirement')->nullable();
            $table->decimal('budget', 14, 2)->nullable();
            $table->string('timeline', 50)->nullable();
            $table->string('temperature', 10)->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->json('attribution')->nullable();
            $table->index(['account_id', 'next_follow_up_at']);
            $table->index('previous_lead_id');
        });
    }

    public function down(): void
    {
        $mysql = $this->onMySql();

        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'next_follow_up_at']);
            $table->dropIndex(['previous_lead_id']);
            $table->dropConstrainedForeignId('converted_by_user_id');
            $table->dropColumn(['crm_company_id', 'previous_lead_id', 'company_name', 'requirement', 'budget', 'timeline', 'temperature', 'next_follow_up_at', 'attribution']);
        });

        Schema::table('contacts', function (Blueprint $table) use ($mysql) {
            if ($mysql) {
                $table->dropForeign('contacts_company_account_foreign');
            }
            $table->dropIndex(['account_id', 'crm_company_id']);
            $table->dropColumn(['crm_company_id', 'designation', 'preferred_channel', 'marketing_opt_in', 'opted_out_at']);
        });
    }

    private function onMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
