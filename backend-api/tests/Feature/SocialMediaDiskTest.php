<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\Phase1FoundationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 12 Task 1 — social uploads go through a configurable disk (social.media.disk, default `public`).
 * The first group of tests pins the behaviour that must NOT change (authorization, validation, paths, public
 * serving); the second proves the disk is configurable and that a non-local disk is served too.
 */
class SocialMediaDiskTest extends TestCase
{
    use RefreshDatabase;

    private const UPLOAD = '/api/social/media/upload';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(Phase1FoundationSeeder::class);
    }

    private function tenantAdmin(string $role = 'admin'): User
    {
        $account = Account::factory()->create();
        Subscription::factory()->create(['account_id' => $account->id]);
        $user = User::factory()->create(['account_id' => $account->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function image(string $name = 'creative.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 40, 40);
    }

    // ------------------------------------------------------------------ unchanged behaviour (default disk)

    public function test_default_upload_lands_on_the_public_disk_at_the_same_path_and_url_as_before(): void
    {
        $this->assertSame('public', config('social.media.disk'));
        Storage::fake('public');
        $user = $this->tenantAdmin();

        $response = $this->actingAs($user)->post(self::UPLOAD, ['media' => $this->image()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['url', 'path', 'type', 'mime', 'size']])
            ->assertJsonPath('data.type', 'image');

        $path = $response->json('data.path');
        $this->assertMatchesRegularExpression('#^social-media/'.$user->account_id.'/[0-9a-f-]{36}\.png$#', $path);
        $this->assertSame(url('/api/media/social/'.$path), $response->json('data.url'));
        Storage::disk('public')->assertExists($path);
    }

    public function test_upload_validation_is_unchanged(): void
    {
        Storage::fake('public');
        $user = $this->tenantAdmin();
        $json = ['Accept' => 'application/json'];

        $this->actingAs($user)->post(self::UPLOAD, [], $json)->assertStatus(422)->assertJsonValidationErrors('media');
        $this->actingAs($user)->post(self::UPLOAD, ['media' => UploadedFile::fake()->create('notes.txt', 5, 'text/plain')], $json)->assertStatus(422)->assertJsonValidationErrors('media');
        $this->actingAs($user)->post(self::UPLOAD, ['media' => UploadedFile::fake()->create('script.php', 5, 'application/x-php')], $json)->assertStatus(422)->assertJsonValidationErrors('media');
        $this->actingAs($user)->post(self::UPLOAD, ['media' => UploadedFile::fake()->create('big.mp4', 51201, 'video/mp4')], $json)->assertStatus(422)->assertJsonValidationErrors('media');
        $this->assertSame([], Storage::disk('public')->allFiles(), 'a rejected upload stores nothing');

        $this->actingAs($user)->post(self::UPLOAD, ['media' => UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4')], $json)->assertCreated()->assertJsonPath('data.type', 'video');
    }

    public function test_upload_authorization_is_unchanged(): void
    {
        Storage::fake('public');
        $json = ['Accept' => 'application/json'];

        $this->post(self::UPLOAD, ['media' => $this->image()], $json)->assertUnauthorized();

        Role::firstOrCreate(['name' => 'no_launch', 'guard_name' => 'web'])->syncPermissions(['view-analytics']);
        $this->actingAs($this->tenantAdmin('no_launch'))->post(self::UPLOAD, ['media' => $this->image()], $json)->assertForbidden();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_storage_directory_comes_from_the_authenticated_account_never_the_request(): void
    {
        Storage::fake('public');
        $mine = $this->tenantAdmin();
        $other = $this->tenantAdmin();

        $path = $this->actingAs($mine)->post(self::UPLOAD.'?account_id='.$other->account_id, ['media' => $this->image(), 'account_id' => $other->account_id], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.path');

        $this->assertStringStartsWith("social-media/{$mine->account_id}/", $path);
        $this->assertSame([], Storage::disk('public')->allFiles("social-media/{$other->account_id}"));
    }

    public function test_show_stays_public_and_keeps_its_guards_and_range_support(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('social-media/1/asset.png', '0123456789');

        $this->get('/api/media/social/social-media/1/asset.png')->assertOk();
        $this->get('/api/media/social/social-media/1/asset.png', ['Range' => 'bytes=0-3'])->assertStatus(206);
        $this->get('/api/media/social/social-media/1/missing.png')->assertNotFound();
        $this->get('/api/media/social/other-dir/asset.png')->assertNotFound();
        $this->get('/api/media/social/social-media/..%2F..%2Fsecret.txt')->assertNotFound();
        $this->get('/api/media/social/social-media/1/../../secret.txt')->assertNotFound();
    }

    // ------------------------------------------------------------------ the configurable disk

    public function test_upload_and_serve_follow_the_configured_disk(): void
    {
        Storage::fake('public');
        Storage::fake('shared_media');
        config(['social.media.disk' => 'shared_media']);
        $user = $this->tenantAdmin();

        $path = $this->actingAs($user)->post(self::UPLOAD, ['media' => $this->image()], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.path');

        Storage::disk('shared_media')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->get('/api/media/social/'.$path)->assertOk();
        // the old disk is no longer consulted
        Storage::disk('public')->put('social-media/1/only-public.png', 'x');
        $this->get('/api/media/social/social-media/1/only-public.png')->assertNotFound();
    }

    public function test_a_non_local_disk_is_streamed_through_the_disk(): void
    {
        $root = sys_get_temp_dir().'/social-media-disk-'.uniqid();
        Storage::extend('shared_probe', function ($app, array $config) {
            $adapter = new LocalFilesystemAdapter($config['root']);

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
        config(['filesystems.disks.shared_probe' => ['driver' => 'shared_probe', 'root' => $root, 'throw' => false], 'social.media.disk' => 'shared_probe']);
        $user = $this->tenantAdmin();

        try {
            $path = $this->actingAs($user)->post(self::UPLOAD, ['media' => $this->image()], ['Accept' => 'application/json'])
                ->assertCreated()->json('data.path');
            $this->assertFileExists("{$root}/{$path}");

            $response = $this->get('/api/media/social/'.$path)->assertOk();
            $this->assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $response->baseResponse);
            $this->get('/api/media/social/social-media/9/nope.png')->assertNotFound();
        } finally {
            Storage::disk('shared_probe')->deleteDirectory('social-media');
            @rmdir($root);
        }
    }

    public function test_the_disk_comes_from_config_and_is_never_a_request_value(): void
    {
        Storage::fake('public');
        Storage::fake('shared_media');
        $user = $this->tenantAdmin();

        $path = $this->actingAs($user)->post(self::UPLOAD.'?disk=shared_media', ['media' => $this->image(), 'disk' => 'shared_media', 'social_media_disk' => 'shared_media'], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.path');

        Storage::disk('public')->assertExists($path);
        $this->assertSame([], Storage::disk('shared_media')->allFiles());
    }
}
