<?php

namespace Tests\Feature\Delivery;

use App\Models\Delivery;
use App\Services\Delivery\DzpatchClient;
use App\Services\Delivery\Exceptions\DzpatchRejectedException;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Creates a real delivery on Dzpatch.
 *
 * Skipped unless DZPATCH_E2E=true, because it dispatches an actual job that a
 * real rider can see. It is not part of the ordinary suite: a test that puts
 * work in front of riders must be run deliberately, never as a side effect of
 * running the tests.
 *
 * Everything it creates is prefixed `e2e-test-` so it can be identified and
 * cancelled afterwards, and it cancels its own delivery in tearDown.
 */
class DzpatchEndToEndTest extends TestCase
{
    private ?string $createdDeliveryId = null;

    private ?string $localDeliveryId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('DZPATCH_E2E') !== true && env('DZPATCH_E2E') !== 'true') {
            $this->markTestSkipped(
                'Set DZPATCH_E2E=true to run this. It creates a real delivery visible to riders.'
            );
        }

        if (! config('dzpatch.api_key') || ! config('dzpatch.base_url')) {
            $this->markTestSkipped('DZPATCH_API_URL and DZPATCH_API_KEY are required.');
        }
    }

    protected function tearDown(): void
    {
        // Cancel on Dzpatch first: leaving a live job in a rider's feed is the
        // one consequence of this test that reaches real people.
        if ($this->createdDeliveryId !== null) {
            try {
                app(DzpatchClient::class)->cancelDelivery(
                    $this->createdDeliveryId,
                    'Automated end-to-end test cleanup.',
                );
            } catch (\Throwable $e) {
                fwrite(STDERR, "\nWARNING: could not cancel Dzpatch delivery "
                    ."{$this->createdDeliveryId}: {$e->getMessage()}\n"
                    ."Cancel it manually.\n");
            }
        }

        if ($this->localDeliveryId !== null) {
            Delivery::query()->whereKey($this->localDeliveryId)->delete();
        }

        parent::tearDown();
    }

    /**
     * A payload that satisfies Dzpatch's validation without depending on
     * FoodHunts data, so the test measures the integration rather than whether
     * a particular restaurant happens to be configured.
     */
    private function payload(string $externalOrderId): array
    {
        return [
            'external_order_id' => $externalOrderId,
            'external_reference' => 'e2e-test-reference',
            'pickup' => [
                'name' => 'E2E Test Restaurant',
                'phone' => '+2348034968730',
                'address' => '1 Test Pickup Street, Uyo, Akwa Ibom',
                'lat' => 5.0377,
                'lng' => 7.9128,
                'instructions' => 'Automated test - please ignore.',
            ],
            'dropoff' => [
                'name' => 'E2E Test Customer',
                'phone' => '+2348034968730',
                'address' => '2 Test Dropoff Road, Uyo, Akwa Ibom',
                'lat' => 5.0450,
                'lng' => 7.9200,
                'instructions' => 'Automated test - please ignore.',
            ],
            'items' => [
                ['name' => 'Test item', 'quantity' => 1],
            ],
            'items_summary' => '1 item(s) from E2E Test Restaurant',
            'customer' => [
                'name' => 'E2E Test Customer',
                'phone' => '+2348034968730',
            ],
            'pricing' => [
                'currency' => 'NGN',
                'partner_calculated_fee' => 1500,
            ],
            'meta' => [
                'source' => 'foodhunts',
                'automated_test' => true,
            ],
        ];
    }

    public function test_it_creates_a_real_delivery_on_dzpatch(): void
    {
        $client = app(DzpatchClient::class);
        $externalOrderId = 'e2e-test-'.Str::uuid();
        $idempotencyKey = (string) Str::uuid();

        $response = $client->createDelivery($this->payload($externalOrderId), $idempotencyKey);

        $this->assertArrayHasKey('delivery_id', $response, 'Dzpatch returned no delivery_id.');
        $this->createdDeliveryId = $response['delivery_id'];

        $this->assertSame($externalOrderId, $response['external_order_id']);
        $this->assertNotEmpty($response['status']);

        fwrite(STDERR, "\n=== CHECK DZPATCH DB ===\n");
        fwrite(STDERR, "  partner_deliveries.id              = {$response['delivery_id']}\n");
        fwrite(STDERR, "  partner_deliveries.external_order_id = {$externalOrderId}\n");
        fwrite(STDERR, "  status                             = {$response['status']}\n");
        fwrite(STDERR, "========================\n");
    }

    public function test_the_same_idempotency_key_returns_the_same_delivery(): void
    {
        $client = app(DzpatchClient::class);
        $externalOrderId = 'e2e-test-'.Str::uuid();
        $idempotencyKey = (string) Str::uuid();
        $payload = $this->payload($externalOrderId);

        $first = $client->createDelivery($payload, $idempotencyKey);
        $this->createdDeliveryId = $first['delivery_id'];

        // The property the whole retry design rests on: a repeated key must not
        // put a second rider on one order.
        $second = $client->createDelivery($payload, $idempotencyKey);

        $this->assertSame(
            $first['delivery_id'],
            $second['delivery_id'],
            'A repeated Idempotency-Key created a second delivery.',
        );
    }

    public function test_an_identical_request_returns_the_original_delivery(): void
    {
        $client = app(DzpatchClient::class);
        $externalOrderId = 'e2e-test-'.Str::uuid();
        $payload = $this->payload($externalOrderId);

        $first = $client->createDelivery($payload, (string) Str::uuid());
        $this->createdDeliveryId = $first['delivery_id'];

        // Same order, different idempotency key. Dzpatch fingerprints the
        // request rather than relying on the key alone, so an identical payload
        // resolves to the delivery that already exists.
        //
        // This is what protects an order when a dispatch is retried by a path
        // that has lost the original key - a queue redelivery, or a manual
        // retry after a timeout. Neither can produce a second rider.
        $second = $client->createDelivery($payload, (string) Str::uuid());

        $this->assertSame(
            $first['delivery_id'],
            $second['delivery_id'],
            'An identical request created a second delivery.',
        );
    }

    public function test_a_changed_request_for_the_same_order_is_refused(): void
    {
        $client = app(DzpatchClient::class);
        $externalOrderId = 'e2e-test-'.Str::uuid();

        $first = $client->createDelivery($this->payload($externalOrderId), (string) Str::uuid());
        $this->createdDeliveryId = $first['delivery_id'];

        // Same order id, different content. Silently returning the original
        // would hide the fact that the second request asked for something else,
        // so Dzpatch refuses instead.
        $changed = $this->payload($externalOrderId);
        $changed['dropoff']['address'] = 'Somewhere completely different, Uyo';
        $changed['pricing']['partner_calculated_fee'] = 2500;

        try {
            $client->createDelivery($changed, (string) Str::uuid());
            $this->fail('Expected Dzpatch to refuse a conflicting request for the same order.');
        } catch (DzpatchRejectedException $e) {
            $this->assertTrue(
                $e->isDuplicate(),
                "Expected a duplicate error code, got: {$e->errorCode}",
            );
        }
    }
}
