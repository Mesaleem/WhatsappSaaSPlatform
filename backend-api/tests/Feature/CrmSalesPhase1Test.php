<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\Capability;
use App\Models\Contact;
use App\Models\CrmActivity;
use App\Models\CrmCompany;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmPipelineStage;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Crm\CrmLeadService;
use App\Services\Crm\PlatformCrmAccount;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRM Sales Phase 1 (owner-approved 2026-10-14): companies, deal pipeline stages,
 * deals, activities, Convert Lead, Re-engage, qualification fields and the website
 * enquiry -> CRM lead path.
 */
class CrmSalesPhase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    /** @return array{account: Account, user: User} */
    private function tenant(): array
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);
        AccountEntitlement::firstOrCreate(
            ['account_id' => $account->id, 'capability_id' => Capability::where('slug', 'crm')->firstOrFail()->id],
            ['source' => 'manual_grant', 'granted_by_account_id' => null],
        );
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole('admin');

        return ['account' => $account->fresh(), 'user' => $user];
    }

    private function lead(Account $account, array $attributes = []): CrmLead
    {
        return app(CrmLeadService::class)->create($account, ['phone_number' => '98'.random_int(10000000, 99999999), 'name' => 'Rahul'] + $attributes);
    }

    // ------------------------------------------------------------ convert

    public function test_convert_creates_company_and_deal_and_is_idempotent(): void
    {
        $t = $this->tenant();
        $lead = $this->lead($t['account'], ['company_name' => 'Sharma Travels', 'budget' => '60000.50', 'requirement' => 'WhatsApp automation']);

        $payload = ['company' => ['name' => 'Sharma Travels', 'gstin' => '27ABCDE1234F1Z5'], 'create_deal' => true];
        $first = $this->actingAs($t['user'])->postJson("/api/crm/leads/{$lead->id}/convert", $payload)->assertOk();
        $second = $this->actingAs($t['user'])->postJson("/api/crm/leads/{$lead->id}/convert", $payload)->assertOk();

        $first->assertJsonPath('data.status', 'converted')->assertJsonPath('data.company.name', 'Sharma Travels');
        $this->assertSame($first->json('data.deal.id'), $second->json('data.deal.id'));
        $this->assertSame(1, CrmDeal::count(), 'converting twice never makes a second deal');
        $this->assertSame(1, CrmCompany::count());

        $deal = CrmDeal::firstOrFail();
        $this->assertSame('DEAL-0001', $deal->number);
        $this->assertSame('60000.50', (string) $deal->value, 'the deal value defaults to the lead budget, exactly');
        $this->assertSame('open', $deal->status);
        $this->assertSame($lead->contact_id, $deal->contact_id);
        $this->assertSame($deal->crm_company_id, $lead->contact->fresh()->crm_company_id, 'the contact joins the company');

        $fresh = $lead->fresh();
        $this->assertSame($t['user']->id, $fresh->converted_by_user_id);
        $this->assertSame('manual', $fresh->source, 'the source attribution survives conversion');
        $this->assertSame(1, CrmActivity::where('crm_lead_id', $lead->id)->where('subject', 'like', 'Lead converted%')->count(), 'one conversion event');
    }

    public function test_convert_reuses_a_company_by_gstin_then_by_name(): void
    {
        $t = $this->tenant();
        $byGstin = CrmCompany::create(['account_id' => $t['account']->id, 'name' => 'Old Name Pvt Ltd', 'gstin' => '27ABCDE1234F1Z5']);
        $byName = CrmCompany::create(['account_id' => $t['account']->id, 'name' => 'Blue Dart']);

        $a = $this->lead($t['account']);
        $b = $this->lead($t['account']);
        $this->actingAs($t['user'])->postJson("/api/crm/leads/{$a->id}/convert", ['company' => ['name' => 'Different', 'gstin' => '27abcde1234f1z5']])->assertOk();
        $this->actingAs($t['user'])->postJson("/api/crm/leads/{$b->id}/convert", ['company' => ['name' => 'blue dart']])->assertOk();

        $this->assertSame($byGstin->id, $a->fresh()->crm_company_id);
        $this->assertSame($byName->id, $b->fresh()->crm_company_id);
        $this->assertSame(2, CrmCompany::count());
    }

    public function test_another_tenants_company_cannot_be_used(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $foreign = CrmCompany::create(['account_id' => $other['account']->id, 'name' => 'Theirs']);
        $lead = $this->lead($t['account']);

        $this->actingAs($t['user'])->postJson("/api/crm/leads/{$lead->id}/convert", ['crm_company_id' => $foreign->id])
            ->assertStatus(422)->assertJsonValidationErrors('crm_company_id');
        $this->assertSame('new', $lead->fresh()->status, 'nothing changed');

        $this->actingAs($t['user'])->getJson("/api/crm/companies/{$foreign->id}")->assertNotFound();
        $this->actingAs($t['user'])->getJson('/api/crm/companies')->assertOk()->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------------------ re-engage

    public function test_re_engage_creates_a_new_linked_lead_and_only_from_converted(): void
    {
        $t = $this->tenant();
        $lead = $this->lead($t['account']);

        $this->actingAs($t['user'])->postJson("/api/crm/leads/{$lead->id}/re-engage")->assertStatus(422);

        $this->actingAs($t['user'])->postJson("/api/crm/leads/{$lead->id}/convert", [])->assertOk();
        $new = $this->actingAs($t['user'])->postJson("/api/crm/leads/{$lead->id}/re-engage", ['requirement' => 'Second branch'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.previous_lead_id', $lead->id)
            ->assertJsonPath('data.requirement', 'Second branch')
            ->json('data');

        $this->assertSame($lead->contact_id, $new['contact']['id'], 'same customer');
        $this->assertSame('converted', $lead->fresh()->status, 'the converted lead is untouched');
        $this->assertSame(1, Contact::count(), 'no duplicate contact');
    }

    // ------------------------------------------------------------ stages and deals

    public function test_default_stages_are_created_once_and_keep_every_kind(): void
    {
        $t = $this->tenant();

        $stages = $this->actingAs($t['user'])->getJson('/api/crm/stages')->assertOk()->json('data');
        $this->actingAs($t['user'])->getJson('/api/crm/stages')->assertOk();

        $this->assertSame(array_column(CrmPipelineStage::DEFAULTS, 0), array_column($stages, 'name'));
        $this->assertSame(count(CrmPipelineStage::DEFAULTS), CrmPipelineStage::count());

        $won = collect($stages)->firstWhere('kind', 'won');
        $this->actingAs($t['user'])->deleteJson("/api/crm/stages/{$won['id']}")->assertStatus(422);
        $this->actingAs($t['user'])->patchJson("/api/crm/stages/{$won['id']}", ['kind' => 'open'])->assertStatus(422);
    }

    public function test_moving_a_deal_keeps_status_in_step_and_won_is_final(): void
    {
        $t = $this->tenant();
        $deal = $this->actingAs($t['user'])->postJson('/api/crm/deals', ['name' => 'Annual plan', 'value' => '12000'])->assertCreated()->json('data');
        $stages = collect($this->actingAs($t['user'])->getJson('/api/crm/stages')->json('data'));
        $lost = $stages->firstWhere('kind', 'lost');
        $won = $stages->firstWhere('kind', 'won');
        $open = $stages->firstWhere('kind', 'open');

        $this->actingAs($t['user'])->patchJson("/api/crm/deals/{$deal['id']}/stage", ['stage_id' => $lost['id'], 'lost_reason' => 'Budget'])
            ->assertOk()->assertJsonPath('data.status', 'lost')->assertJsonPath('data.lost_reason', 'Budget');
        $this->actingAs($t['user'])->patchJson("/api/crm/deals/{$deal['id']}/stage", ['stage_id' => $open['id']])
            ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.lost_reason', null);
        $this->actingAs($t['user'])->patchJson("/api/crm/deals/{$deal['id']}/stage", ['stage_id' => $won['id']])
            ->assertOk()->assertJsonPath('data.status', 'won');
        $this->actingAs($t['user'])->patchJson("/api/crm/deals/{$deal['id']}/stage", ['stage_id' => $open['id']])
            ->assertStatus(422);

        $this->assertNotNull(CrmDeal::find($deal['id'])->won_at);
        $this->assertGreaterThanOrEqual(4, CrmActivity::where('crm_deal_id', $deal['id'])->where('type', 'event')->count());

        // The general update can never change the stage or status.
        $this->actingAs($t['user'])->patchJson("/api/crm/deals/{$deal['id']}", ['status' => 'open', 'stage_id' => $open['id'], 'name' => 'Renamed'])->assertOk();
        $this->assertSame('won', CrmDeal::find($deal['id'])->status);
    }

    public function test_deal_numbers_are_per_tenant_and_the_board_sums_values_exactly(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();

        $this->actingAs($a['user'])->postJson('/api/crm/deals', ['name' => 'A1', 'value' => '0.10'])->assertJsonPath('data.number', 'DEAL-0001');
        $this->actingAs($a['user'])->postJson('/api/crm/deals', ['name' => 'A2', 'value' => '0.20'])->assertJsonPath('data.number', 'DEAL-0002');
        $this->actingAs($b['user'])->postJson('/api/crm/deals', ['name' => 'B1'])->assertJsonPath('data.number', 'DEAL-0001');

        $board = collect($this->actingAs($a['user'])->getJson('/api/crm/deals/board')->assertOk()->json('data'));
        $first = $board->first();
        $this->assertSame('0.30', $first['total_value'], 'no float rounding (0.1 + 0.2)');
        $this->assertSame(2, $first['deals_count']);

        $bDeal = CrmDeal::where('account_id', $b['account']->id)->firstOrFail();
        $this->actingAs($a['user'])->getJson("/api/crm/deals/{$bDeal->id}")->assertNotFound();
        $this->actingAs($a['user'])->patchJson("/api/crm/deals/{$bDeal->id}/stage", ['stage_id' => 1])->assertNotFound();
    }

    // ------------------------------------------------------------ activities

    public function test_activities_are_linked_tenant_checked_and_listed_as_tasks(): void
    {
        $t = $this->tenant();
        $other = $this->tenant();
        $lead = $this->lead($t['account']);
        $foreignLead = $this->lead($other['account']);

        $this->actingAs($t['user'])->postJson('/api/crm/activities', ['type' => 'call', 'subject' => 'Call back'])->assertStatus(422);
        $this->actingAs($t['user'])->postJson('/api/crm/activities', ['type' => 'call', 'subject' => 'x', 'crm_lead_id' => $foreignLead->id])
            ->assertStatus(422)->assertJsonValidationErrors('crm_lead_id');
        $this->actingAs($t['user'])->postJson('/api/crm/activities', ['type' => 'event', 'subject' => 'x', 'crm_lead_id' => $lead->id])->assertStatus(422);

        $task = $this->actingAs($t['user'])->postJson('/api/crm/activities', [
            'type' => 'task', 'subject' => 'Send demo', 'crm_lead_id' => $lead->id,
            'due_at' => now()->subHour()->toIso8601String(), 'assigned_user_id' => $t['user']->id,
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.is_overdue', true)->json('data');
        $this->actingAs($t['user'])->postJson('/api/crm/activities', ['type' => 'note', 'subject' => 'Prefers WhatsApp', 'crm_lead_id' => $lead->id])
            ->assertCreated()->assertJsonPath('data.status', 'done');

        $this->actingAs($t['user'])->getJson('/api/crm/activities?due=overdue&assigned_user_id=me')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($t['user'])->getJson("/api/crm/activities?crm_lead_id={$lead->id}")->assertOk()->assertJsonCount(2, 'data');

        $this->actingAs($t['user'])->patchJson("/api/crm/activities/{$task['id']}", ['status' => 'done', 'outcome' => 'Demo booked'])
            ->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.is_overdue', false);
        $this->actingAs($other['user'])->patchJson("/api/crm/activities/{$task['id']}", ['status' => 'cancelled'])->assertNotFound();

        // System events cannot be edited or deleted.
        $this->actingAs($t['user'])->postJson("/api/crm/leads/{$lead->id}/convert", [])->assertOk();
        $event = CrmActivity::where('type', 'event')->firstOrFail();
        $this->actingAs($t['user'])->deleteJson("/api/crm/activities/{$event->id}")->assertStatus(422);
    }

    // ------------------------------------------------------------ deletes keep history

    public function test_deleting_a_lead_or_company_keeps_deals_and_activities(): void
    {
        $t = $this->tenant();
        $lead = $this->lead($t['account']);
        $this->actingAs($t['user'])->postJson("/api/crm/leads/{$lead->id}/convert", ['company' => ['name' => 'Acme'], 'create_deal' => true])->assertOk();
        $deal = CrmDeal::firstOrFail();
        $company = CrmCompany::firstOrFail();

        $this->actingAs($t['user'])->deleteJson("/api/crm/companies/{$company->id}")->assertOk();
        $this->assertNull($deal->fresh()->crm_company_id);
        $this->assertNull($lead->contact->fresh()->crm_company_id);

        $this->actingAs($t['user'])->deleteJson("/api/crm/leads/{$lead->id}")->assertOk();
        $this->assertNotNull($deal->fresh(), 'the deal stays');
        $this->assertNull($deal->fresh()->crm_lead_id);
        $this->assertGreaterThan(0, CrmActivity::count());
        $this->assertSame(0, CrmActivity::whereNotNull('crm_lead_id')->count());

        // A contact with a deal cannot be deleted (move or delete the deal first).
        $this->actingAs($t['user'])->deleteJson("/api/crm/contacts/{$lead->contact_id}")->assertStatus(409)
            ->assertJsonPath('dependents.crm_deals', 1);
    }

    // ------------------------------------------------------------ qualification + website

    public function test_qualification_fields_are_saved_and_validated(): void
    {
        $t = $this->tenant();

        $id = $this->actingAs($t['user'])->postJson('/api/crm/leads', [
            'phone_number' => '919811112222', 'name' => 'Rahul', 'company_name' => 'Sharma Travels',
            'budget' => '5000', 'temperature' => 'hot', 'timeline' => 'Within 30 days',
            'next_follow_up_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.temperature', 'hot')->assertJsonPath('data.budget', '5000.00')->json('data.id');

        $this->actingAs($t['user'])->patchJson("/api/crm/leads/{$id}", ['temperature' => 'boiling'])->assertStatus(422);
        $this->actingAs($t['user'])->patchJson("/api/crm/leads/{$id}", ['requirement' => 'Reminders'])->assertOk()->assertJsonPath('data.requirement', 'Reminders');
        $this->actingAs($t['user'])->postJson('/api/crm/leads', ['phone_number' => '919811113333', 'attribution' => ['campaign' => 'fake']])->assertCreated();
        $this->assertNull(CrmLead::latest('id')->first()->attribution, 'a user cannot write attribution');
    }

    public function test_a_website_enquiry_with_a_phone_becomes_a_platform_crm_lead(): void
    {
        $platform = app(PlatformCrmAccount::class)->ensure()['account'];

        $this->postJson('/api/public/contact', [
            'name' => 'Rahul', 'email' => 'rahul@example.com', 'phone' => '919800001111',
            'company' => 'Sharma Travels', 'topic' => 'sales', 'message' => 'Need WhatsApp reminders',
        ])->assertCreated();
        $this->postJson('/api/public/contact', ['name' => 'No Phone', 'email' => 'np@example.com', 'message' => 'Hi'])->assertCreated();

        $lead = CrmLead::where('account_id', $platform->id)->firstOrFail();
        $this->assertSame(1, CrmLead::count());
        $this->assertSame('website', $lead->source);
        $this->assertSame('Sharma Travels', $lead->company_name);
        $this->assertSame('website_contact_form', $lead->attribution['channel']);
    }
}
