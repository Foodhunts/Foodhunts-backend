<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicRestaurantVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_listings_only_include_active_approved_restaurants(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'phone' => '08000000000',
            'password' => 'password123',
            'role' => 'restaurant_owner',
        ]);

        $visibleRestaurant = Restaurant::create([
            'owner_id' => $owner->id,
            'name' => 'Visible Spot',
            'slug' => 'visible-spot',
            'is_active' => true,
            'kyc_status' => 'approved',
        ]);

        Restaurant::create([
            'owner_id' => $owner->id,
            'name' => 'Pending Spot',
            'slug' => 'pending-spot',
            'is_active' => true,
            'kyc_status' => 'pending',
        ]);

        Restaurant::create([
            'owner_id' => $owner->id,
            'name' => 'Inactive Spot',
            'slug' => 'inactive-spot',
            'is_active' => false,
            'kyc_status' => 'approved',
        ]);

        $response = $this->getJson('/api/restaurants');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $visibleRestaurant->id);
    }

    public function test_public_details_and_menu_items_are_hidden_for_non_visible_restaurants(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner2@example.com',
            'phone' => '08000000001',
            'password' => 'password123',
            'role' => 'restaurant_owner',
        ]);

        $restaurant = Restaurant::create([
            'owner_id' => $owner->id,
            'name' => 'Hidden Spot',
            'slug' => 'hidden-spot',
            'is_active' => true,
            'kyc_status' => 'pending',
        ]);

        $response = $this->getJson('/api/restaurants/'.$restaurant->id);
        $response->assertNotFound();

        $menuResponse = $this->getJson('/api/restaurants/'.$restaurant->id.'/menu-items');
        $menuResponse->assertNotFound();
    }
}
