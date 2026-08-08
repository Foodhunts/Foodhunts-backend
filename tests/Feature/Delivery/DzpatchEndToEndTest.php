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
            'dispatch_attempt' => 1,
            'external_reference' => 'e2e-test-reference',
            'pickup' => [
                'name' => 'E2E Test Restaurant',
                'phone' => '+2348034968730',
                // Lagos, not Uyo. Dzpatch staging serves one area (Lagos,
                // centre 6.5244/3.3792) and caps a delivery at 8km
                // pickup-to-dropoff; anything else is refused with
                // partner_pricing_rejected before the test can assert anything.
                'address' => '1 Test Pickup Street, Lagos',
                'lat' => 6.5244,
                'lng' => 3.3792,
                'instructions' => 'Automated test - please ignore.',
            ],
            'dropoff' => [
                'name' => 'E2E Test Customer',
                'phone' => '+2348034968730',
                // ~700m from pickup, well inside the 8km cap.
                'address' => '2 Test Dropoff Road, Lagos',
                'lat' => 6.5300,
                'lng' => 3.3800,
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
                // Integer kobo, in whole N100 increments: N1,500.
                'partner_calculated_fee_minor' => 150000,
            ],
            'meta' => [
                // v1 matches these keys exactly and rejects any extra, so
                // there is no room for an automated_test flag here.
                'source' => 'foodhunt',
                'restaurant_id' => '11111111-1111-4111-8111-111111111111',
                'checkout_reference' => 'e2e-test-reference',
                'food_ready_at' => now()->toIso8601String(),
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

        $idempotencyKey = (string) Str::uuid();

        $first = $client->createDelivery($payload, $idempotencyKey);
        $this->createdDeliveryId = $first['delivery_id'];

        // Reusing the same key with the same payload returns the original
        // delivery instead of creating a second one. This is what protects an
        // order when a dispatch is retried after a timeout: the caller cannot
        // put two riders on one order.
        //
        // v1 requires the key AND the request fingerprint to match. A retry
        // that has lost the original key is a conflict, not a replay - which is
        // why DeliveryDispatchService persists the key on the local row before
        // it calls out, and reuses it on every retry.
        $second = $client->createDelivery($payload, $idempotencyKey);

        $this->assertSame(
            $first['delivery_id'],
            $second['delivery_id'],
            'An identical request created a second delivery.',
        );
    }

    public function test_a_lost_idempotency_key_is_a_conflict_not_a_second_delivery(): void
    {
        $client = app(DzpatchClient::class);
        $externalOrderId = 'e2e-test-'.Str::uuid();
        $payload = $this->payload($externalOrderId);

        $first = $client->createDelivery($payload, (string) Str::uuid());
        $this->createdDeliveryId = $first['delivery_id'];

        // Same order and attempt, different key. v1 refuses rather than
        // silently returning the original, so a caller that lost its key learns
        // it must look the delivery up instead of dispatching again. The
        // important guarantee is the same either way: no second rider.
        try {
            $client->createDelivery($payload, (string) Str::uuid());
            $this->fail('Expected a conflict for a reused order with a new idempotency key.');
        } catch (DzpatchRejectedException $e) {
            $this->assertTrue(
                $e->isDuplicate(),
                "Expected a duplicate/conflict code, got: {$e->errorCode}",
            );
        }
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
        $changed['dropoff']['address'] = 'Somewhere completely different, Lagos';
        $changed['pricing']['partner_calculated_fee_minor'] = 250000;

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
