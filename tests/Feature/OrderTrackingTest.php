<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies OrderController::tracking exposes the delivery PIN to the customer
 * only while a trip is active (rider_assigned … arrived_dropoff), and hides it
 * before assignment / after delivery. Runs on an in-memory SQLite schema (the
 * suite has no DB-backed tests, and the real migrations use Postgres jsonb).
 */
final class OrderTrackingTest extends TestCase
{
    private const USER_ID = '7ae9da06-06f5-425b-881e-4e14821184e7';
    private const RIDER_USER_ID = '8ae9da06-06f5-425b-881e-4e14821184e8';
    private const ORDER_ID = '9ae9da06-06f5-425b-881e-4e14821184e7';
    private const DELIVERY_ID = '1ae9da06-06f5-425b-881e-4e14821184e7';
    private const RIDER_ID = '2ae9da06-06f5-425b-881e-4e14821184e7';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'supabase.url' => 'https://example.supabase.co',
            'supabase.publishable_key' => 'test-publishable-key',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        $this->createSchema();
        $this->seedFixtures();
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('role')->nullable();
            $table->string('referral_code')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('status')->default('pending');
            $table->timestamp('estimated_delivery_time')->nullable();
            $table->timestamps();
        });

        Schema::create('riders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('vehicle_type')->nullable();
            $table->string('plate_number')->nullable();
            $table->string('kyc_status')->nullable();
            $table->boolean('is_online')->default(false);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('location_updated_at')->nullable();
            $table->decimal('rating', 3, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('order_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->uuid('rider_id')->nullable();
            $table->string('status')->default('pending_dispatch');
            $table->string('dispatch_mode')->default('manual');
            $table->string('delivery_code', 4)->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('location_snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function seedFixtures(): void
    {
        $now = now();

        DB::table('users')->insert([
            ['id' => self::USER_ID, 'name' => 'Customer', 'phone_number' => '08010000000', 'created_at' => $now, 'updated_at' => $now],
            ['id' => self::RIDER_USER_ID, 'name' => 'Chidi O.', 'phone_number' => '08020000000', 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('orders')->insert([
            'id' => self::ORDER_ID,
            'user_id' => self::USER_ID,
            'status' => 'out_for_delivery',
            'estimated_delivery_time' => $now->copy()->addMinutes(20),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('riders')->insert([
            'id' => self::RIDER_ID,
            'user_id' => self::RIDER_USER_ID,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'LAG-123',
            'kyc_status' => 'approved',
            'is_online' => true,
            'rating' => 4.8,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('order_deliveries')->insert([
            'id' => self::DELIVERY_ID,
            'order_id' => self::ORDER_ID,
            'rider_id' => self::RIDER_ID,
            'status' => 'en_route_dropoff',
            'dispatch_mode' => 'manual',
            'delivery_code' => '7453',
            'assigned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function actingAsCustomer(): self
    {
        Http::fake([
            '*' => Http::response(['id' => self::USER_ID], 200),
        ]);

        return $this->withHeader('Authorization', 'Bearer test-token');
    }

    public function test_tracking_returns_delivery_code_while_trip_is_active(): void
    {
        $response = $this->actingAsCustomer()
            ->getJson('/api/v2/orders/'.self::ORDER_ID.'/tracking');

        $response->assertOk();
        $response->assertJsonPath('data.delivery.status', 'en_route_dropoff');
        $response->assertJsonPath('data.delivery.delivery_code', '7453');
        $response->assertJsonPath('data.delivery.rider.name', 'Chidi O.');
        $response->assertJsonPath('data.delivery.rider.phone_number', '08020000000');
    }

    public function test_tracking_hides_delivery_code_after_delivery(): void
    {
        DB::table('order_deliveries')
            ->where('id', self::DELIVERY_ID)
            ->update(['status' => 'delivered', 'delivered_at' => now()]);
        DB::table('orders')->where('id', self::ORDER_ID)->update(['status' => 'delivered']);

        $response = $this->actingAsCustomer()
            ->getJson('/api/v2/orders/'.self::ORDER_ID.'/tracking');

        $response->assertOk();
        $response->assertJsonPath('data.delivery.status', 'delivered');
        $response->assertJsonPath('data.delivery.delivery_code', null);
        $response->assertJsonPath('data.delivery.rider', null);
    }

    public function test_tracking_returns_null_delivery_when_no_delivery_exists(): void
    {
        DB::table('order_deliveries')->delete();

        $response = $this->actingAsCustomer()
            ->getJson('/api/v2/orders/'.self::ORDER_ID.'/tracking');

        $response->assertOk();
        $response->assertJsonPath('data.delivery', null);
    }
}
