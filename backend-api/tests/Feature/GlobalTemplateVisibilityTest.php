<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\MessageTemplate;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Global Templates (account_id null) are visible to eligible Admin/Agent lists without being copied into any tenant. */
class GlobalTemplateVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function tenant(?Account $agent = null, bool $subscribed = true): Account
    {
        $a = $agent ? Account::factory()->client($agent)->create() : Account::factory()->create();
        if ($subscribed) {
            Subscription::factory()->create(['account_id' => $a->id]);
        }

        return $a;
    }

    private function agent(): Account
    {
        $a = Account::factory()->agent()->create();
        Subscription::factory()->create(['account_id' => $a->id]);

        return $a;
    }

    private function userOf(Account $a, array|string $roles = 'admin'): User
    {
        $u = User::factory()->create(['account_id' => $a->id, 'is_active' => true]);
        $u->assignRole($roles);

        return $u;
    }

    private function agentUser(Account $agent): User
    {
        return $this->userOf($agent, ['admin', 'agent']);
    }

    private function as(User $u, string $method, string $uri, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u)->json($method, $uri, $data);
    }

    private function tpl(?Account $owner, string $title, string $status = 'approved', array $extra = []): MessageTemplate
    {
        return MessageTemplate::create([
            'account_id' => $owner?->id,
            'template_code' => MessageTemplate::generateTemplateCode($title),
            'title' => $title,
            'template_body' => 'Hello {{name}}.',
            'status' => $status,
        ] + $extra);
    }

    private function titles($res): array
    {
        return collect($res->json('data'))->pluck('title')->sort()->values()->all();
    }

    // 1 + 15
    public function test_super_admin_creates_a_global_template_as_one_record_with_no_tenant_copies(): void
    {
        $this->tenant();
        $this->tenant();
        $sa = $this->userOf(Account::factory()->create(), 'super_admin');
        $before = MessageTemplate::count();

        $res = $this->as($sa, 'POST', '/api/message-templates', ['title' => 'Welcome Customer', 'template_body' => 'Hi {{name}}'])->assertCreated();

        $this->assertNull($res->json('data.account_id'));
        $this->assertSame('pending', $res->json('data.status'), 'creation/approval rules are unchanged');
        $this->assertSame($before + 1, MessageTemplate::count(), 'exactly one row, never one per tenant');
        $this->assertSame(1, MessageTemplate::where('title', 'Welcome Customer')->count());
    }

    // 2, 3, 4
    public function test_an_agent_sees_global_templates_plus_its_own_tree_only(): void
    {
        $agentA = $this->agent();
        $childA = $this->tenant($agentA);
        $agentB = $this->agent();
        $childB = $this->tenant($agentB);
        $this->tpl(null, 'Global Welcome');
        $this->tpl($agentA, 'Agent A Own');
        $this->tpl($childA, 'Child A Private');
        $this->tpl($agentB, 'Agent B Own');
        $this->tpl($childB, 'Child B Private');

        $res = $this->as($this->agentUser($agentA), 'GET', '/api/message-templates')->assertOk();

        $this->assertSame(['Agent A Own', 'Child A Private', 'Global Welcome'], $this->titles($res)); // 8
        $this->assertTrue(collect($res->json('data'))->firstWhere('title', 'Global Welcome')['is_global']);
        $this->assertFalse(collect($res->json('data'))->firstWhere('title', 'Agent A Own')['is_global']);
    }

    public function test_a_global_template_that_is_not_approved_is_not_shared(): void
    {
        $agent = $this->agent();
        $t = $this->tenant();
        $this->tpl(null, 'Draft Global', 'pending');
        $this->tpl(null, 'Rejected Global', 'rejected');

        $this->assertSame([], $this->titles($this->as($this->agentUser($agent), 'GET', '/api/message-templates')));
        $this->assertSame([], $this->titles($this->as($this->userOf($t), 'GET', '/api/alerts/message-templates/mine')));
    }

    // 4, 5, 6, 7
    public function test_each_tenant_sees_global_plus_own_but_never_another_tenants_private_template(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $this->tpl(null, 'Global Order Confirmation');
        $this->tpl($a, 'A Private');
        $this->tpl($b, 'B Private', 'pending');

        $ra = $this->as($this->userOf($a), 'GET', '/api/alerts/message-templates/mine')->assertOk();
        $rb = $this->as($this->userOf($b), 'GET', '/api/alerts/message-templates/mine')->assertOk();

        $this->assertSame(['A Private', 'Global Order Confirmation'], $this->titles($ra));
        $this->assertSame(['B Private', 'Global Order Confirmation'], $this->titles($rb));
        $this->assertTrue(collect($ra->json('data'))->firstWhere('title', 'Global Order Confirmation')['is_global']);
        $this->assertArrayNotHasKey('account_id', $ra->json('data.0'), 'ownership ids are not exposed in the trimmed shape');
    }

    public function test_the_send_form_list_already_offers_global_and_own_only(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $this->tpl(null, 'Global X');
        $this->tpl($a, 'A Own');
        $this->tpl($b, 'B Own');

        $res = $this->as($this->userOf($a), 'GET', '/api/alerts/message-templates')->assertOk();

        $this->assertSame(['A Own', 'Global X'], collect($res->json('data'))->pluck('title')->sort()->values()->all());
    }

    // 9 -- visibility never replaces authorization / entitlement
    public function test_global_visibility_does_not_bypass_permission_or_entitlement(): void
    {
        $this->tpl(null, 'Global Y');
        $a = $this->tenant();

        // No permission: a role without send-messages cannot list or use anything, global or not.
        $noPerm = $this->userOf($a, []);
        $this->as($noPerm, 'GET', '/api/alerts/message-templates/mine')->assertForbidden();
        $this->as($noPerm, 'GET', '/api/message-templates')->assertForbidden();

        // A plain tenant admin cannot reach the management list (which is what carries the Agent global rows).
        $this->as($this->userOf($a), 'GET', '/api/message-templates')->assertForbidden();

        // No subscription/quota: the send path still refuses a global template.
        $noSub = $this->tenant(null, false);
        $global = MessageTemplate::where('title', 'Global Y')->first();
        $res = $this->as($this->userOf($noSub), 'POST', '/api/alerts/send-template', ['template_id' => $global->id, 'recipient_phone' => '919999999999', 'variables' => ['name' => 'X']]);
        $this->assertContains($res->getStatusCode(), [402, 403]);
    }

    // 10
    public function test_direct_access_to_other_trees_or_global_templates_by_id_is_refused(): void
    {
        $agentA = $this->agent();
        $agentB = $this->agent();
        $childB = $this->tenant($agentB);
        $global = $this->tpl(null, 'Global Z');
        $theirs = $this->tpl($childB, 'Child B Private');
        $u = $this->agentUser($agentA);

        $this->as($u, 'PUT', '/api/message-templates/'.$theirs->id, ['title' => 'Hijack'])->assertNotFound();
        $this->as($u, 'PATCH', '/api/message-templates/'.$theirs->id.'/reject', [])->assertNotFound();
        // Global is read-only for an Agent: the original record can't be edited, approved, rejected or deleted.
        $this->as($u, 'PUT', '/api/message-templates/'.$global->id, ['title' => 'Edited'])->assertNotFound();
        $this->as($u, 'PATCH', '/api/message-templates/'.$global->id.'/approve', [])->assertNotFound();
        $this->as($u, 'PATCH', '/api/message-templates/'.$global->id.'/reject', [])->assertNotFound();
        $this->assertSame('Global Z', $global->fresh()->title);
        $this->assertSame('Child B Private', $theirs->fresh()->title);
        // And an Agent still cannot author a global template.
        $this->as($u, 'POST', '/api/message-templates', ['title' => 'Mine global', 'template_body' => 'x'])->assertStatus(422);
    }

    // 11, 12, 13 -- the combined set is one query, so search/status/filters/ordering act on global + own together
    public function test_search_status_and_account_filters_apply_across_global_and_tenant_templates(): void
    {
        $agent = $this->agent();
        $child = $this->tenant($agent);
        $this->tpl(null, 'Payment Reminder Global');
        $this->tpl(null, 'Welcome Global');
        $this->tpl($child, 'Payment Receipt Child');
        $this->tpl($child, 'Payment Draft Child', 'pending_agent_review');
        $u = $this->agentUser($agent);

        $this->assertSame(['Payment Draft Child', 'Payment Receipt Child', 'Payment Reminder Global'], $this->titles($this->as($u, 'GET', '/api/message-templates?search=Payment')));
        $this->assertSame(['Payment Receipt Child', 'Payment Reminder Global', 'Welcome Global'], $this->titles($this->as($u, 'GET', '/api/message-templates?status=approved')));
        $this->assertSame(['Payment Draft Child', 'Payment Receipt Child'], $this->titles($this->as($u, 'GET', '/api/message-templates?account_id='.$child->id)));
        // Newest first across both sets (one ordering over the union, not two concatenated lists).
        $ids = collect($this->as($u, 'GET', '/api/message-templates')->json('data'))->pluck('id')->all();
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids);
    }

    // 14
    public function test_super_admin_view_is_unchanged(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        $this->tpl(null, 'G Approved');
        $this->tpl(null, 'G Pending', 'pending');
        $this->tpl($a, 'A One');
        $this->tpl($b, 'B One', 'rejected');
        $sa = $this->userOf(Account::factory()->create(), 'super_admin');

        $this->assertSame(['A One', 'B One', 'G Approved', 'G Pending'], $this->titles($this->as($sa, 'GET', '/api/message-templates')));
        $this->assertSame(['A One'], $this->titles($this->as($sa, 'GET', '/api/message-templates?account_id='.$a->id)));
        $this->assertSame(['B One'], $this->titles($this->as($sa, 'GET', '/api/message-templates?status=rejected')));
    }

    public function test_no_tenant_rows_are_created_by_listing_or_viewing(): void
    {
        $agent = $this->agent();
        $t = $this->tenant($agent);
        $this->tpl(null, 'Only Global');
        $count = MessageTemplate::count();

        $this->as($this->agentUser($agent), 'GET', '/api/message-templates')->assertOk();
        $this->as($this->userOf($t), 'GET', '/api/alerts/message-templates/mine')->assertOk();
        $this->as($this->userOf($t), 'GET', '/api/alerts/message-templates')->assertOk();

        $this->assertSame($count, MessageTemplate::count());
        $this->assertSame(0, MessageTemplate::where('account_id', $t->id)->count());
    }
}
