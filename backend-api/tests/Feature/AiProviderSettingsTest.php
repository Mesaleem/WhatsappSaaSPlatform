<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AiProviderSettings;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\AiManager;
use App\Services\Ai\Billing\AiCreditPricing;
use App\Services\Ai\Data\AiRequest;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 8 Task 12 (continued) — the DB-level override seam
 * (AiProviderSettings -> AiManager::resolve()) and its admin API
 * (AiGatewayController). This is the actual ask behind Task 12: a Super
 * Admin fixing a deprecated/renamed model, a leaked key, or a kill
 * switch, from the DB, with no env edit or redeploy.
 *
 * UNVERIFIED: written without a reachable PHP/phpunit runtime in the
 * authoring session — run `php artisan test --filter=AiProviderSettings`
 * before trusting this file; see Phase 8 Task 12's row in PROJECT_STATE.md.
 */
class AiProviderSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
        config([
            'ai.enabled' => ['openai', 'anthropic', 'gemini', 'groq'],
            'ai.providers.groq.api_key' => 'env-key',
            'ai.providers.groq.model' => 'env-model',
            'ai.providers.groq.base_url' => 'https://api.groq.com/openai/v1',
            'ai.credits.tokens_per_credit_overrides' => [],
        ]);
        app(AiManager::class)->flush();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['account_id' => null]);
        $user->assignRole('super_admin');

        return $user;
    }

    private function plainAdmin(): User
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $user->assignRole('admin');

        return $user;
    }

    // ==================================================================
    // AiManager wiring
    // ==================================================================

    public function test_no_row_leaves_the_env_configuration_exactly_as_it_was(): void
    {
        $this->assertTrue(app(AiManager::class)->available('groq'));
        $this->assertSame('groq', app(AiManager::class)->provider('groq')->name());
        $this->assertSame('env-model', config('ai.providers.groq.model'), 'untouched — no AiProviderSettings row exists for groq');
    }

    public function test_an_explicit_false_disables_a_provider_the_env_list_still_enables(): void
    {
        AiProviderSettings::create(['provider' => 'groq', 'is_enabled' => false]);
        app(AiManager::class)->flush();

        $this->assertFalse(app(AiManager::class)->available('groq'));

        try {
            app(AiManager::class)->provider('groq');
            $this->fail('Expected AiException.');
        } catch (AiException $e) {
            $this->assertSame(AiException::PROVIDER_UNAVAILABLE, $e->errorCode);
        }
    }

    public function test_an_explicit_true_enables_a_provider_the_env_list_leaves_out(): void
    {
        config(['ai.enabled' => ['openai']]); // groq not env-enabled
        AiProviderSettings::create(['provider' => 'groq', 'is_enabled' => true, 'api_key' => 'db-key', 'model' => 'db-model']);
        app(AiManager::class)->flush();

        $this->assertTrue(app(AiManager::class)->available('groq'));
    }

    public function test_a_null_is_enabled_defers_to_the_env_list_either_way(): void
    {
        AiProviderSettings::create(['provider' => 'groq', 'is_enabled' => null, 'model' => 'db-model-only']);
        app(AiManager::class)->flush();
        $this->assertTrue(app(AiManager::class)->available('groq'), 'still env-enabled');

        config(['ai.enabled' => ['openai']]);
        app(AiManager::class)->flush();
        $this->assertFalse(app(AiManager::class)->available('groq'), 'env turned it off, row never said otherwise');
    }

    public function test_the_model_this_is_the_actual_fix_for_a_deprecated_model_is_overridden_from_the_db(): void
    {
        // Simulates the exact ConnexxaIQ incident: the configured model
        // (env, or a prior DB value) has been retired by the vendor, and
        // a Super Admin fixes it from the DB with no redeploy.
        Http::fake(fn (HttpRequest $r) => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ], 200));

        AiProviderSettings::create(['provider' => 'groq', 'model' => 'llama-3.1-70b-replacement']);
        app(AiManager::class)->flush();

        app(AiManager::class)->provider('groq')->generateText(new AiRequest('hi'));

        Http::assertSent(fn (HttpRequest $r) => $r['model'] === 'llama-3.1-70b-replacement');
    }

    public function test_the_api_key_and_base_url_are_overridden_from_the_db(): void
    {
        Http::fake(fn (HttpRequest $r) => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ], 200));

        AiProviderSettings::create(['provider' => 'groq', 'api_key' => 'rotated-key', 'base_url' => 'https://groq.internal-proxy.example/openai/v1']);
        app(AiManager::class)->flush();

        app(AiManager::class)->provider('groq')->generateText(new AiRequest('hi'));

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://groq.internal-proxy.example/openai/v1/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer rotated-key'));
    }

    public function test_a_null_field_in_the_row_leaves_that_one_env_default_untouched(): void
    {
        Http::fake(fn (HttpRequest $r) => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ], 200));

        // model overridden, api_key/base_url left null -> env values still used for those two
        AiProviderSettings::create(['provider' => 'groq', 'model' => 'only-the-model-changed']);
        app(AiManager::class)->flush();

        app(AiManager::class)->provider('groq')->generateText(new AiRequest('hi'));

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer env-key')
            && $r['model'] === 'only-the-model-changed');
    }

    public function test_tokens_per_credit_override_reaches_billing_without_billing_code_knowing_this_table_exists(): void
    {
        AiProviderSettings::create(['provider' => 'groq', 'tokens_per_credit_override' => 250]);
        app(AiManager::class)->flush();

        // Resolving the provider is what folds the override into config() —
        // mirrors how AiManager::resolve() runs ahead of every call.
        app(AiManager::class)->provider('groq');

        $this->assertSame(250, app(AiCreditPricing::class)->tokensPerCredit('groq', 'any-model'));

        foreach (['Billing/AiCreditPricing.php', 'Billing/MeteredAiService.php'] as $file) {
            $this->assertStringNotContainsString('AiProviderSettings', file_get_contents(app_path("Services/Ai/{$file}")), "{$file} must stay unaware of AiProviderSettings — provider-neutral billing is the whole point");
        }
    }

    public function test_saving_or_deleting_a_row_invalidates_its_cache(): void
    {
        $settings = AiProviderSettings::create(['provider' => 'groq', 'model' => 'first']);
        AiProviderSettings::findByProviderCached('groq');
        $this->assertTrue(Cache::has('ai_provider_settings:groq'));

        $settings->update(['model' => 'second']);
        $this->assertFalse(Cache::has('ai_provider_settings:groq'), 'save() must forget the cache key');
        $this->assertSame('second', AiProviderSettings::findByProviderCached('groq')->model);

        $settings->delete();
        $this->assertFalse(Cache::has('ai_provider_settings:groq'), 'delete() must forget the cache key');
        $this->assertNull(AiProviderSettings::findByProviderCached('groq'));
    }

    // ==================================================================
    // Admin API
    // ==================================================================

    public function test_super_admin_can_list_and_update_provider_settings(): void
    {
        $this->actingAs($this->superAdmin())->getJson('/api/admin/ai/provider-settings')
            ->assertOk()
            ->assertJsonCount(4, 'data');

        $this->actingAs($this->superAdmin())->postJson('/api/admin/ai/provider-settings/groq', [
            'is_enabled' => true, 'model' => 'llama-new', 'api_key' => 'super-secret-key', 'tokens_per_credit_override' => 500,
        ])->assertOk()->assertJsonPath('data.model', 'llama-new')->assertJsonPath('data.api_key_set', true);

        $this->assertArrayNotHasKey('api_key', $this->actingAs($this->superAdmin())->getJson('/api/admin/ai/provider-settings')->json('data.3'));

        $row = AiProviderSettings::firstWhere('provider', 'groq');
        $this->assertSame('super-secret-key', $row->api_key, 'persisted and decrypts back correctly');
    }

    public function test_omitting_api_key_on_update_leaves_the_stored_one_untouched(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user)->postJson('/api/admin/ai/provider-settings/groq', ['api_key' => 'original-key'])->assertOk();

        $this->actingAs($user)->postJson('/api/admin/ai/provider-settings/groq', ['model' => 'new-model-only'])->assertOk();

        $row = AiProviderSettings::firstWhere('provider', 'groq');
        $this->assertSame('original-key', $row->api_key);
        $this->assertSame('new-model-only', $row->model);
    }

    public function test_a_non_super_admin_is_forbidden(): void
    {
        $this->actingAs($this->plainAdmin())->getJson('/api/admin/ai/provider-settings')->assertForbidden();
        $this->actingAs($this->plainAdmin())->postJson('/api/admin/ai/provider-settings/groq', ['model' => 'x'])->assertForbidden();
    }

    public function test_an_unguessed_provider_name_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())->postJson('/api/admin/ai/provider-settings/not-a-real-provider', ['model' => 'x'])->assertNotFound();
    }

    public function test_a_guest_is_unauthorized(): void
    {
        $this->getJson('/api/admin/ai/provider-settings')->assertUnauthorized();
    }
}
