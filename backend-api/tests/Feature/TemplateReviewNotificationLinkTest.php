<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\InAppNotification;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\Templates\TemplateService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** "Template awaiting your review" notifications open the Template Manager when clicked. */
class TemplateReviewNotificationLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_review_notification_links_to_the_template_manager(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $superAdmin = User::factory()->create(['account_id' => null]);
        $superAdmin->assignRole('super_admin');
        $account = Account::factory()->create();

        $template = new MessageTemplate(['title' => 'Diwali offer', 'template_body' => 'Hi']);
        $template->forceFill(['account_id' => $account->id, 'status' => 'pending_admin_review']);
        $template->setRelation('account', $account);

        app(TemplateService::class)->notifyPendingReview($template);

        $notification = InAppNotification::where('user_id', $superAdmin->id)->firstOrFail();
        $this->assertSame('Template awaiting your review', $notification->title);
        $this->assertSame('/admin/templates', $notification->link);
    }

    public function test_old_review_notifications_without_a_link_are_backfilled(): void
    {
        $user = User::factory()->create();
        DB::table('in_app_notifications')->insert([
            ['user_id' => $user->id, 'title' => 'Template awaiting your review', 'body' => 'x', 'category' => 'template_review', 'link' => null, 'is_read' => false, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'title' => 'Other', 'body' => 'y', 'category' => 'billing', 'link' => null, 'is_read' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        (require database_path('migrations/2026_10_13_100000_backfill_template_review_notification_links.php'))->up();

        $this->assertSame('/admin/templates', DB::table('in_app_notifications')->where('category', 'template_review')->value('link'));
        $this->assertNull(DB::table('in_app_notifications')->where('category', 'billing')->value('link'));
    }
}
