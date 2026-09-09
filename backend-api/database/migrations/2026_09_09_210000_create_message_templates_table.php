<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dynamic Templates & Variables System — Super Admin authors a
     * template with {{placeholder}} tokens (rendered by the existing
     * App\Support\TemplateRenderer, the same simple strtr() substitution
     * already used by the Mail Template Manager) and either leaves it
     * global (account_id null, usable by every client) or scopes it to
     * one specific client account. status starts 'pending' so a newly
     * authored template is never immediately live — a Super Admin must
     * explicitly approve() it before it appears in any tenant's Send
     * Alert dropdown or is acceptable to the external
     * /v1/messages/send-template endpoint.
     *
     * account_id cascades on delete rather than nulling out — an
     * account-specific template (e.g. a hospital's exact dosage-reminder
     * wording) has no meaningful life as a "global" template once the
     * account that owns it is gone, so it is deleted with it rather than
     * silently becoming available to every other tenant.
     */
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('industry_type')->nullable();
            $table->string('title');
            $table->text('template_body');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['account_id', 'status']);
            $table->index('status');
            $table->index('industry_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
