<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\Api\Media\RestaurantMediaController;
use App\Http\Requests\Media\UploadRestaurantLogoRequest;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Policies\MediaPolicy;
use App\Services\Media\Data\StoredMedia;
use App\Services\Media\Exceptions\MediaConfigurationException;
use App\Services\Media\Exceptions\MediaStorageException;
use App\Services\Media\MediaStorageService;
use App\Services\Media\RestaurantMediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Mockery;
use Tests\TestCase;

final class MediaUploadTest extends TestCase
{
    private const RESTAURANT_ID = '7ae9da06-06f5-425b-881e-4e14821184e7';

    private const OTHER_RESTAURANT_ID = '8ae9da06-06f5-425b-881e-4e14821184e8';

    public function test_unauthenticated_upload_is_rejected(): void
    {
        $this->postJson('/api/v2/restaurants/'.self::RESTAURANT_ID.'/media/logo')
            ->assertUnauthorized();
    }

    /**
     * Regression: the media routes must be authenticated (supabase.auth) but must
     * NOT sit behind the generic role:restaurant_owner middleware. The shared
     * production users table has no `role` column, so that middleware 403s every
     * uploader BEFORE MediaPolicy can decide ownership. This asserts the route
     * middleware directly so the policy fix cannot be silently bypassed again.
     */
    public function test_media_routes_are_authenticated_but_not_role_gated(): void
    {
        $router = app('router');
        $paths = [
            'api/v2/restaurants/{restaurant}/media/logo',
            'api/v2/restaurants/{restaurant}/media/cover',
            'api/v2/restaurants/{restaurant}/menu-items/{menuItem}/media',
        ];

        foreach ($router->getRoutes() as $route) {
            if (! in_array($route->uri(), $paths, true)) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $this->assertContains(
                'supabase.auth',
                $middleware,
                "Route {$route->uri()} must require Supabase authentication.",
            );
            foreach ($middleware as $m) {
                $this->assertStringStartsNotWith(
                    'role:',
                    (string) $m,
                    "Route {$route->uri()} must not be gated by the role middleware.",
                );
            }
        }
    }

    public function test_owner_policy_allows_only_the_matching_restaurant_owner(): void
    {
        $policy = app(MediaPolicy::class);
        $owner = new User(['role' => Role::RestaurantOwner->value]);
        $owner->id = '9ae9da06-06f5-425b-881e-4e14821184e9';
        $restaurant = new Restaurant(['owner_id' => $owner->id]);
        $restaurant->id = self::RESTAURANT_ID;
        $unrelated = new User(['role' => Role::RestaurantOwner->value]);
        $unrelated->id = 'aae9da06-06f5-425b-881e-4e14821184ea';

        $this->assertTrue($policy->updateRestaurantMedia($owner, $restaurant));
        $this->assertFalse($policy->updateRestaurantMedia($unrelated, $restaurant));
    }

    public function test_owner_without_a_role_is_still_allowed_by_id(): void
    {
        // Production regression: the shared users table has no `role` column, so
        // $user->role is null. Ownership must be decided by id, not role.
        $policy = app(MediaPolicy::class);
        $owner = new User;
        $owner->id = '9ae9da06-06f5-425b-881e-4e14821184e9';
        $restaurant = new Restaurant(['owner_id' => $owner->id]);
        $restaurant->id = self::RESTAURANT_ID;

        $this->assertNull($owner->role);
        $this->assertTrue($policy->updateRestaurantMedia($owner, $restaurant));
    }

    public function test_email_linked_owner_is_allowed_even_without_owner_id_match(): void
    {
        // Email-linked stores (store app services/menus.ts) own by owner_email.
        $policy = app(MediaPolicy::class);
        $user = new User(['email' => 'Store@Example.com']);
        $user->id = 'cae9da06-06f5-425b-881e-4e14821184ec';
        $restaurant = new Restaurant(['owner_id' => 'dae9da06-06f5-425b-881e-4e14821184ed']); // different user
        $restaurant->id = self::RESTAURANT_ID;
        $restaurant->owner_email = 'store@example.com';

        $this->assertTrue($policy->updateRestaurantMedia($user, $restaurant));
    }

    public function test_foreign_user_is_denied_when_no_ownership_signal_matches(): void
    {
        $policy = app(MediaPolicy::class);
        $stranger = new User(['email' => 'stranger@example.com']);
        $stranger->id = 'eae9da06-06f5-425b-881e-4e14821184ee';
        $restaurant = new Restaurant(['owner_id' => 'fae9da06-06f5-425b-881e-4e14821184ef']);
        $restaurant->id = self::RESTAURANT_ID;
        $restaurant->owner_email = 'realowner@example.com';

        $this->assertFalse($policy->updateRestaurantMedia($stranger, $restaurant));
    }

    public function test_cross_restaurant_menu_item_is_rejected_by_policy(): void
    {
        $policy = app(MediaPolicy::class);
        $owner = new User(['role' => Role::RestaurantOwner->value]);
        $owner->id = '9ae9da06-06f5-425b-881e-4e14821184e9';
        $restaurant = new Restaurant(['owner_id' => $owner->id]);
        $restaurant->id = self::RESTAURANT_ID;
        $menuItem = new MenuItem(['restaurant_id' => self::OTHER_RESTAURANT_ID]);
        $menuItem->id = 'bae9da06-06f5-425b-881e-4e14821184eb';

        $this->assertFalse($policy->updateMenuItemMedia($owner, $restaurant, $menuItem));
    }

    public function test_public_image_request_rejects_svg_pdf_and_oversized_files(): void
    {
        $request = new UploadRestaurantLogoRequest;
        config(['media.limits.public_image_kb' => 1]);

        foreach ([
            UploadedFile::fake()->create('image.svg', 1, 'image/svg+xml'),
            UploadedFile::fake()->create('document.pdf', 1, 'application/pdf'),
            UploadedFile::fake()->create('large.jpg', 2, 'image/jpeg'),
        ] as $file) {
            $validator = Validator::make(['file' => $file], $request->rules());
            $this->assertTrue($validator->fails());
        }
    }

    public function test_controller_returns_normalized_success_response(): void
    {
        $stored = new StoredMedia(
            disk: 'r2',
            objectKey: 'restaurants/'.self::RESTAURANT_ID.'/logo/uuid.jpg',
            contentType: 'image/jpeg',
            size: 123,
            isPublic: true,
            url: 'https://media.example.test/restaurants/logo.jpg',
        );
        $media = Mockery::mock(RestaurantMediaService::class);
        $media->shouldReceive('updateLogo')->once()->andReturn($stored);
        $policy = app(MediaPolicy::class);
        $controller = new RestaurantMediaController($media, $policy);
        $request = UploadRestaurantLogoRequest::create(
            '/api/v2/restaurants/'.self::RESTAURANT_ID.'/media/logo',
            'POST',
            [],
            [],
            ['file' => UploadedFile::fake()->image('original.jpg')],
        );
        $owner = new User(['role' => Role::RestaurantOwner->value]);
        $owner->id = '9ae9da06-06f5-425b-881e-4e14821184e9';
        $request->setUserResolver(static function () use ($owner): User {
            return $owner;
        });
        $restaurant = new Restaurant(['owner_id' => $owner->id]);
        $restaurant->id = self::RESTAURANT_ID;

        $response = $controller->logo($request, $restaurant);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('logo_url', $payload['data']['field']);
        $this->assertStringStartsWith('https://media.example.test/', $payload['data']['url']);
        $this->assertSame('image/jpeg', $payload['data']['content_type']);
        $this->assertArrayNotHasKey('secret', $payload);
        $this->assertArrayNotHasKey('access_key', $payload);
    }

    public function test_legacy_driver_returns_controlled_unavailable_response(): void
    {
        $media = Mockery::mock(RestaurantMediaService::class);
        $media->shouldReceive('updateLogo')->once()->andThrow(new MediaConfigurationException('disabled'));
        $policy = app(MediaPolicy::class);
        $controller = new RestaurantMediaController($media, $policy);
        $request = UploadRestaurantLogoRequest::create(
            '/api/v2/restaurants/'.self::RESTAURANT_ID.'/media/logo',
            'POST',
            [],
            [],
            ['file' => UploadedFile::fake()->image('logo.jpg')],
        );
        $owner = new User(['role' => Role::RestaurantOwner->value]);
        $owner->id = '9ae9da06-06f5-425b-881e-4e14821184e9';
        $request->setUserResolver(static function () use ($owner): User {
            return $owner;
        });
        $restaurant = new Restaurant(['owner_id' => $owner->id]);
        $restaurant->id = self::RESTAURANT_ID;

        $response = $controller->logo($request, $restaurant);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringNotContainsString('disabled', (string) $response->getContent());
    }

    public function test_provider_failure_does_not_update_existing_database_reference(): void
    {
        config(['media.driver' => 'r2']);
        config(['media.public_base_url' => 'https://media.example.test']);
        config(['filesystems.disks.r2' => [
            'driver' => 's3',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'auto',
            'bucket' => 'test-bucket',
            'endpoint' => 'https://r2.example.test',
        ]]);
        Storage::shouldReceive('disk')->with('r2')->andThrow(new \RuntimeException('provider failure'));
        $service = app(RestaurantMediaService::class);
        $restaurant = new Restaurant;
        $restaurant->id = self::RESTAURANT_ID;

        $this->expectException(MediaStorageException::class);
        $service->updateLogo($restaurant, UploadedFile::fake()->image('logo.jpg'));
    }

    public function test_database_failure_preserves_previous_reference_and_does_not_delete_media(): void
    {
        config(['media.driver' => 'r2']);
        config(['media.public_base_url' => 'https://media.example.test']);
        config(['filesystems.disks.r2' => [
            'driver' => 's3',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'auto',
            'bucket' => 'test-bucket',
            'endpoint' => 'https://r2.example.test',
        ]]);
        Storage::fake('r2');
        $restaurant = Mockery::mock(Restaurant::class)->makePartial();
        $restaurant->shouldReceive('getKey')->andReturn(self::RESTAURANT_ID);
        $restaurant->shouldReceive('update')->once()->andReturnFalse();
        DB::shouldReceive('transaction')->once()->andReturnUsing(static fn (callable $callback) => $callback());

        $this->expectException(MediaStorageException::class);
        (new RestaurantMediaService(app(MediaStorageService::class)))->updateLogo(
            $restaurant,
            UploadedFile::fake()->image('logo.jpg'),
        );
    }
}
