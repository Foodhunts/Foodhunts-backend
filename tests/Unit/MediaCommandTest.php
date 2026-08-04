<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class MediaCommandTest extends TestCase
{
    public function test_r2_verification_command_refuses_when_r2_is_disabled(): void
    {
        config(['media.driver' => 'legacy']);

        $exitCode = Artisan::call('foodhunts:test-r2');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('R2 media storage is disabled', Artisan::output());
    }
}
