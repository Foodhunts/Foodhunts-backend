<?php

namespace Tests\Feature;

use Tests\TestCase;

class MobileApiContractTest extends TestCase
{
    public function test_mobile_profile_requires_supabase_authentication(): void
    {
        $this->getJson('/api/v2/auth/me')
            ->assertUnauthorized();
    }

    public function test_mobile_addresses_require_supabase_authentication(): void
    {
        $this->getJson('/api/v2/addresses')
            ->assertUnauthorized();
    }

    public function test_public_restaurant_page_size_is_bounded(): void
    {
        $this->getJson('/api/v2/restaurants?per_page=101')
            ->assertUnprocessable();
    }
}
