<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Sales Phase 1 — deal pipeline stages and deals.
 *
 * Stages are per tenant (kind open / won / lost; default set created lazily by
 * CrmPipelineStageService). A deal is one specific sale: optional links to the lead
 * it was converted from (unique: converting twice never makes two deals), a contact
 * and a company; a running number per tenant (DEAL-0001, `sequence` unique per
 * account); money as decimal(14,2).
 *
 * Foreign keys: the tenant-proving composite FKs to stages and companies are added on
 * MySQL/MariaDB only (SQLite cannot), RESTRICT, so a stage or company in use cannot
 * be deleted underneath a deal (the services detach first). Links to crm_leads and
 * contacts carry no FK on purpose: deleting a lead or contact is an existing action
 * that must keep working; CrmLead / Contact null the deal link when they are deleted,
 * and CrmDeal's saving guard checks every link belongs to the same tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = $this->onMySql();

        Schema::create('crm_pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('position')->default(0);
            $table->unsignedTinyInteger('probability')->default(0);
            $table->string('kind', 10)->default('open');
            $table->timestamps();

            $table->unique(['id', 'account_id']);
            $table->index(['account_id', 'position']);
        });

        Schema::create('crm_deals', function (Blueprint $table) use ($mysql) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('number', 30);
            $table->string('name', 191);
            $table->unsignedBigInteger('crm_lead_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('crm_company_id')->nullable();
            $table->unsignedBigInteger('stage_id');
            $table->decimal('value', 14, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->date('expected_close_date')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 10)->default('open');
            $table->string('source', 20)->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->string('lost_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['id', 'account_id']);
            $table->unique(['account_id', 'sequence']);
            $table->unique('crm_lead_id');
            $table->index(['account_id', 'status']);
            $table->index(['account_id', 'stage_id']);
            $table->index(['account_id', 'contact_id']);
            $table->index(['account_id', 'crm_company_id']);

            if ($mysql) {
                $table->foreign(['stage_id', 'account_id'], 'crm_deals_stage_account_foreign')
                    ->references(['id', 'account_id'])->on('crm_pipeline_stages');
                $table->foreign(['crm_company_id', 'account_id'], 'crm_deals_company_account_foreign')
                    ->references(['id', 'account_id'])->on('crm_companies');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_deals');
        Schema::dropIfExists('crm_pipeline_stages');
    }

    private function onMySql(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
