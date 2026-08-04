<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\MediaCategory;
use App\Services\Media\Exceptions\PrivateMediaUrlException;
use App\Services\Media\MediaUrlResolver;
use Tests\TestCase;

final class MediaUrlResolverTest extends TestCase
{
    public function test_absolute_http_urls_are_preserved(): void
    {
        $resolver = app(MediaUrlResolver::class);

        $this->assertSame(
            'https://supabase.example.test/storage/object.jpg',
            $resolver->resolve('  https://supabase.example.test/storage/object.jpg  '),
        );
        $this->assertSame('https://media.example.test/object.jpg', $resolver->resolve('https://media.example.test/object.jpg'));
    }

    public function test_public_r2_keys_are_resolved_without_storage_checks(): void
    {
        config(['media.public_base_url' => 'https://media.example.test///']);
        $resolver = app(MediaUrlResolver::class);
        $key = 'restaurants/7ae9da06-06f5-425b-881e-4e14821184e7/logo/8ae9da06-06f5-425b-881e-4e14821184e8.jpg';

        $this->assertSame('https://media.example.test/restaurants/7ae9da06-06f5-425b-881e-4e14821184e7/logo/8ae9da06-06f5-425b-881e-4e14821184e8.jpg', $resolver->resolve($key));
    }

    public function test_null_empty_invalid_and_private_values_are_not_resolved(): void
    {
        $resolver = app(MediaUrlResolver::class);

        $this->assertNull($resolver->resolve(null));
        $this->assertNull($resolver->resolve('   '));
        $this->assertNull($resolver->resolve('file:///tmp/object.jpg'));
        $this->assertNull($resolver->resolve('private/restaurants/not-a-key/kyc/file.pdf'));

        $this->expectException(PrivateMediaUrlException::class);
        $resolver->resolveForCategory(
            MediaCategory::RestaurantKyc,
            'private/restaurants/7ae9da06-06f5-425b-881e-4e14821184e7/kyc/8ae9da06-06f5-425b-881e-4e14821184e8.pdf',
        );
    }
}
