<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Social Media Marketing & Meta Ads Automation Expansion — Final
     * Phase (White-Label Automated PDF Reporting).
     *
     * ROOT-CAUSE FINDING: the spec asks the monthly PDF report to
     * "Automatically attach Tenant Branding (Company Name, Logo, Primary
     * Accent Color)." accounts.company_name already exists (Module 3) —
     * logo/accent color do not exist ANYWHERE in this schema, and no
     * tenant self-service "company profile" endpoint exists either
     * (verified: AccountController::update() is the only place
     * company_name is ever edited, and it is Super-Admin-only, same
     * tier as primary_phone/status). These two nullable columns follow
     * that exact same precedent — set by a Super Admin via
     * AccountController::update() / the AccountsPage.tsx edit form —
     * rather than introducing a new tenant self-service settings surface
     * that nothing else in this app has either.
     *
     * `logo_url` is a plain URL string, not an uploaded file — this app
     * has no file/media upload pipeline for accounts anywhere (checked:
     * no Storage::disk() usage tied to Account), and building one is
     * beyond this phase's scope; a tenant/Super Admin hosts the logo
     * image themselves (e.g. their own site) and pastes the URL. See
     * SocialReportController's docblock for how (and how far) this is
     * actually used in the generated PDF — SimplePdfWriter has no image
     * embedding support, so this URL is NOT rendered as a picture there.
     *
     * `brand_accent_color` is a 6-hex-digit color string (e.g.
     * "4F46E5"), validated in AccountController::update(); falls back to
     * the platform's own default indigo accent when null/invalid so the
     * report never renders with an undefined color.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('logo_url')->nullable()->after('company_name');
            $table->string('brand_accent_color', 7)->nullable()->after('logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['logo_url', 'brand_accent_color']);
        });
    }
};
