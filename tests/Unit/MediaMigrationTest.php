<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MediaMigrationManifest;
use App\Models\Restaurant;
use App\Services\Media\PublicMediaMigrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MediaMigrationTest extends TestCase
{
    private string $sourceUrl = 'https://project.supabase.co/storage/v1/object/public/restaurant-images/logo.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'supabase.url' => 'https://project.supabase.co',
            'media.driver' => 'r2',
            'media.public_base_url' => 'https://media.example.test',
            'media.limits.public_image_kb' => 5120,
            'filesystems.disks.r2' => [
                'driver' => 'local',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'bucket' => 'foodhunts-test',
                'endpoint' => 'https://r2.example.test',
                'region' => 'auto',
            ],
        ]);

        \DB::purge('sqlite');
        \DB::reconnect('sqlite');
        Schema::create('restaurants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('owner_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->string('logo_url')->nullable();
            $table->string('header_image_url')->nullable();
            $table->timestamps();
        });
        Schema::create('media_migration_manifests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('source_url');
            $table->string('source_provider');
            $table->text('source_path')->nullable();
            $table->text('destination_key')->nullable();
            $table->text('destination_url')->nullable();
            $table->string('model_type');
            $table->uuid('model_id');
            $table->string('field_name');
            $table->string('status');
            $table->string('checksum')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('migrated_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('media_migration_manifests');
        Schema::dropIfExists('restaurants');
        parent::tearDown();
    }

    public function test_dry_run_does_not_download_upload_or_update_a_record(): void
    {
        $restaurant = $this->restaurant();
        Http::fake();

        $this->artisan('media:migrate-to-r2', ['--dry-run' => true, '--entity' => 'restaurant'])
            ->assertSuccessful()
            ->expectsOutputToContain('Records found: 1');

        $this->assertSame($this->sourceUrl, $restaurant->fresh()->logo_url);
        $this->assertDatabaseCount('media_migration_manifests', 0);
        Http::assertNothingSent();
    }

    public function test_successful_migration_updates_the_url_after_verified_upload(): void
    {
        $restaurant = $this->restaurant();
        Http::fake([$this->sourceUrl => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);
        Storage::fake('r2');

        $candidate = app(PublicMediaMigrationService::class)->candidates('restaurant', (string) $restaurant->id)[0];
        $status = app(PublicMediaMigrationService::class)->migrate($candidate);

        $this->assertSame(MediaMigrationManifest::STATUS_COMPLETED, $status);
        $this->assertStringStartsWith('https://media.example.test/restaurants/'.$restaurant->id.'/logo/', $restaurant->fresh()->logo_url);
        $manifest = MediaMigrationManifest::query()->firstOrFail();
        $this->assertSame($this->sourceUrl, $manifest->source_url);
        $this->assertSame('image/jpeg', $manifest->mime_type);
        Storage::disk('r2')->assertExists($manifest->destination_key);
    }

    public function test_failed_validation_keeps_the_old_url(): void
    {
        $restaurant = $this->restaurant();
        Http::fake([$this->sourceUrl => Http::response('not-an-image', 200)]);

        $candidate = app(PublicMediaMigrationService::class)->candidates('restaurant', (string) $restaurant->id)[0];
        $status = app(PublicMediaMigrationService::class)->migrate($candidate);

        $this->assertSame(MediaMigrationManifest::STATUS_FAILED, $status);
        $this->assertSame($this->sourceUrl, $restaurant->fresh()->logo_url);
        $this->assertSame(MediaMigrationManifest::STATUS_FAILED, MediaMigrationManifest::query()->firstOrFail()->status);
    }

    public function test_duplicate_migration_is_skipped_and_rollback_restores_the_source_url(): void
    {
        $restaurant = $this->restaurant();
        Http::fake([$this->sourceUrl => Http::response($this->jpeg(), 200)]);
        Storage::fake('r2');
        $service = app(PublicMediaMigrationService::class);
        $candidate = $service->candidates('restaurant', (string) $restaurant->id)[0];

        $this->assertSame(MediaMigrationManifest::STATUS_COMPLETED, $service->migrate($candidate));
        $this->assertSame(MediaMigrationManifest::STATUS_SKIPPED, $service->migrate($candidate));
        $manifest = MediaMigrationManifest::query()->firstOrFail();
        $this->assertSame('rolled_back', $service->rollback($manifest));
        $this->assertSame($this->sourceUrl, $restaurant->fresh()->logo_url);
        Storage::disk('r2')->assertExists($manifest->destination_key);
    }

    private function restaurant(): Restaurant
    {
        return Restaurant::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Canary Restaurant',
            'slug' => 'canary-restaurant',
            'logo_url' => $this->sourceUrl,
        ]);
    }

    private function jpeg(): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/AT//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/AT//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Aqf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/Iqf/2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z', true);
    }
}
