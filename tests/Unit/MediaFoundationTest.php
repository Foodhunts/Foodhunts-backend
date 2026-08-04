<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\MediaCategory;
use App\Services\Media\Data\MediaObjectContext;
use App\Services\Media\Exceptions\InvalidMediaObjectKeyException;
use App\Services\Media\Exceptions\MediaConfigurationException;
use App\Services\Media\Exceptions\MediaStorageException;
use App\Services\Media\Exceptions\PrivateMediaUrlException;
use App\Services\Media\MediaConfigurationValidator;
use App\Services\Media\MediaObjectKeyGenerator;
use App\Services\Media\MediaStorageService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

final class MediaFoundationTest extends TestCase
{
    private const RESTAURANT_ID = '7ae9da06-06f5-425b-881e-4e14821184e7';

    private const MENU_ID = '8ae9da06-06f5-425b-881e-4e14821184e8';

    private const MENU_ITEM_ID = '9ae9da06-06f5-425b-881e-4e14821184e9';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'media.driver' => 'legacy',
            'media.public_base_url' => null,
            'media.private_url_ttl_minutes' => 10,
            'media.limits.public_image_kb' => 5120,
            'media.limits.private_document_kb' => 10240,
        ]);
    }

    public function test_legacy_driver_does_not_require_r2_configuration(): void
    {
        app(MediaConfigurationValidator::class)->validate();

        $this->assertSame('legacy', app(MediaConfigurationValidator::class)->driver());
    }

    public function test_r2_configuration_requires_all_safe_values_without_exposing_secrets(): void
    {
        config(['media.driver' => 'r2']);

        $exception = $this->catchException(static function (): void {
            app(MediaConfigurationValidator::class)->validate();
        });

        $this->assertInstanceOf(MediaConfigurationException::class, $exception);
        $this->assertStringNotContainsString('secret-value', $exception->getMessage());
        $this->assertStringContainsString('access key', $exception->getMessage());
    }

    public function test_each_required_r2_value_is_validated(): void
    {
        $required = ['key' => 'access key', 'secret' => 'secret', 'bucket' => 'bucket', 'endpoint' => 'endpoint'];

        foreach ($required as $missing => $label) {
            $this->enableR2();
            $disk = (array) config('filesystems.disks.r2');
            unset($disk[$missing]);
            config(['filesystems.disks.r2' => $disk]);

            $exception = $this->catchException(static function (): void {
                app(MediaConfigurationValidator::class)->validate();
            });

            $this->assertInstanceOf(MediaConfigurationException::class, $exception);
            $this->assertStringContainsString($label, $exception->getMessage());
        }
    }

    public function test_r2_configuration_accepts_valid_endpoint_and_public_url(): void
    {
        $this->enableR2();

        app(MediaConfigurationValidator::class)->validate(true);

        $this->addToAssertionCount(1);
    }

    public function test_invalid_driver_is_rejected(): void
    {
        config(['media.driver' => 'cloudinary']);
        $this->expectException(MediaConfigurationException::class);
        app(MediaConfigurationValidator::class)->validate();
    }

    public function test_invalid_r2_endpoint_and_missing_public_url_are_rejected(): void
    {
        $this->enableR2();
        config(['filesystems.disks.r2.endpoint' => 'not-a-url']);
        $this->expectException(MediaConfigurationException::class);
        app(MediaConfigurationValidator::class)->validate();
    }

    public function test_public_r2_operations_require_a_public_base_url(): void
    {
        $this->enableR2();
        config(['media.public_base_url' => null]);

        $this->expectException(MediaConfigurationException::class);
        app(MediaConfigurationValidator::class)->validate(true);
    }

    public function test_public_and_private_categories_have_fixed_visibility(): void
    {
        $this->assertTrue(MediaCategory::RestaurantLogo->isPublic());
        $this->assertTrue(MediaCategory::MenuItemImage->allowsPermanentUrl());
        $this->assertFalse(MediaCategory::RestaurantKyc->isPublic());
        $this->assertFalse(MediaCategory::OwnerNationalId->allowsPermanentUrl());
        $this->assertSame(['image/jpeg', 'image/png', 'image/webp'], MediaCategory::RestaurantLogo->allowedContentTypes());
        $this->assertContains('application/pdf', MediaCategory::RestaurantKyc->allowedContentTypes());
    }

    public function test_object_keys_use_expected_namespaces_and_random_uuid_filenames(): void
    {
        $generator = app(MediaObjectKeyGenerator::class);
        $context = MediaObjectContext::restaurant(self::RESTAURANT_ID);

        $first = $generator->generate(MediaCategory::RestaurantLogo, $context, 'image/jpeg');
        $second = $generator->generate(MediaCategory::RestaurantLogo, $context, 'image/jpeg');

        $this->assertStringStartsWith('restaurants/'.self::RESTAURANT_ID.'/logo/', $first);
        $this->assertNotSame($first, $second);
        $this->assertStringNotContainsString('original-filename', $first);
        $this->assertTrue(MediaObjectKeyGenerator::isAllowed($first));

        $menuItem = $generator->generate(
            MediaCategory::MenuItemImage,
            MediaObjectContext::menuItem(self::RESTAURANT_ID, self::MENU_ITEM_ID),
            'image/webp',
        );
        $kyc = $generator->generate(
            MediaCategory::RestaurantKyc,
            $context,
            'application/pdf',
        );

        $this->assertStringContainsString('/menu-items/'.self::MENU_ITEM_ID.'/', $menuItem);
        $this->assertStringStartsWith('private/restaurants/'.self::RESTAURANT_ID.'/kyc/', $kyc);
        $this->assertTrue(MediaObjectKeyGenerator::isPrivate($kyc));
    }

    public function test_key_generator_rejects_invalid_context_and_content_type(): void
    {
        $generator = app(MediaObjectKeyGenerator::class);

        $unsupported = $this->catchException(static function () use ($generator): void {
            $generator->generate(
                MediaCategory::RestaurantLogo,
                MediaObjectContext::restaurant(self::RESTAURANT_ID),
                'image/svg+xml',
            );
        });
        $this->assertInstanceOf(InvalidMediaObjectKeyException::class, $unsupported);

        $this->expectException(InvalidMediaObjectKeyException::class);
        $generator->generate(
            MediaCategory::RestaurantLogo,
            MediaObjectContext::restaurant('../escape'),
            'image/jpeg',
        );
    }

    public function test_public_and_private_media_store_with_expected_urls(): void
    {
        $this->enableR2();
        Storage::fake('r2');
        $service = app(MediaStorageService::class);

        $public = $service->store(
            MediaCategory::RestaurantLogo,
            MediaObjectContext::restaurant(self::RESTAURANT_ID),
            UploadedFile::fake()->image('original-filename.jpg'),
        );
        $private = $service->store(
            MediaCategory::RestaurantKyc,
            MediaObjectContext::restaurant(self::RESTAURANT_ID),
            UploadedFile::fake()->create('identity.pdf', 20, 'application/pdf'),
        );

        Storage::disk('r2')->assertExists($public->objectKey);
        Storage::disk('r2')->assertExists($private->objectKey);
        $this->assertTrue($public->isPublic);
        $this->assertNotNull($public->url);
        $this->assertFalse($private->isPublic);
        $this->assertNull($private->url);
        $this->assertSame('r2', $public->disk);
    }

    public function test_service_rejects_storage_when_legacy_driver_is_selected(): void
    {
        Storage::fake('r2');

        $this->expectException(MediaConfigurationException::class);
        app(MediaStorageService::class)->store(
            MediaCategory::RestaurantLogo,
            MediaObjectContext::restaurant(self::RESTAURANT_ID),
            UploadedFile::fake()->image('logo.jpg'),
        );
    }

    public function test_storage_failure_is_controlled_and_delete_is_idempotent(): void
    {
        $this->enableR2();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('r2')->andReturn($disk);

        $this->expectException(MediaStorageException::class);
        app(MediaStorageService::class)->store(
            MediaCategory::RestaurantLogo,
            MediaObjectContext::restaurant(self::RESTAURANT_ID),
            UploadedFile::fake()->image('logo.jpg'),
        );
    }

    public function test_arbitrary_key_deletion_and_public_private_url_misuse_are_rejected(): void
    {
        $this->enableR2();
        $service = app(MediaStorageService::class);

        $this->expectException(InvalidMediaObjectKeyException::class);
        $service->delete('../unrelated/object');
    }

    public function test_private_temporary_url_has_bounded_expiry(): void
    {
        $this->enableR2();
        $key = 'private/restaurants/'.self::RESTAURANT_ID.'/kyc/'.self::MENU_ID.'.pdf';
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('temporaryUrl')
            ->once()
            ->with($key, Mockery::on(static fn ($expires): bool => $expires instanceof \DateTimeInterface))
            ->andReturn('https://signed.example.test/object');
        Storage::shouldReceive('disk')->with('r2')->andReturn($disk);

        $this->assertSame('https://signed.example.test/object', app(MediaStorageService::class)->temporaryPrivateUrl($key, 15));

        $this->expectException(PrivateMediaUrlException::class);
        app(MediaStorageService::class)->temporaryPrivateUrl($key, 16);
    }

    private function enableR2(): void
    {
        config([
            'media.driver' => 'r2',
            'media.public_base_url' => 'https://media.example.test/',
            'filesystems.disks.r2' => [
                'driver' => 'local',
                'key' => 'access-key',
                'secret' => 'secret-value',
                'bucket' => 'foodhunts-test',
                'endpoint' => 'https://r2.example.test',
            ],
        ]);
    }

    private function catchException(callable $callback): ?\Throwable
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            return $exception;
        }

        return null;
    }
}
