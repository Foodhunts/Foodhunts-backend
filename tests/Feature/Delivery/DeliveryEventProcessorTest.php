<?php

namespace Tests\Feature\Delivery;

use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryEvent;
use App\Services\Delivery\DeliveryEventProcessor;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Webhook processing against a real database.
 *
 * Both guarantees under test are enforced by database constraints rather than
 * PHP checks, because two webhook deliveries can be processed at the same
 * instant. Testing them without a database would prove nothing.
 *
 * These tests create and delete their own rows rather than using
 * RefreshDatabase: the target database is shared staging, and dropping its
 * tables would destroy data that is not ours.
 */
class DeliveryEventProcessorTest extends TestCase
{
    private array $createdDeliveryIds = [];

    private array $createdEventIds = [];

    protected function tearDown(): void
    {
        DeliveryEvent::query()->whereIn('dzpatch_event_id', $this->createdEventIds)->delete();
        Delivery::query()->whereIn('id', $this->createdDeliveryIds)->delete();

        parent::tearDown();
    }

    private function delivery(array $attributes = []): Delivery
    {
        $delivery = Delivery::create(array_merge([
            'order_id' => null,
            'external_order_id' => 'test-'.Str::uuid(),
            'dzpatch_delivery_id' => (string) Str::uuid(),
            'status' => DeliveryStatus::Accepted->value,
            'idempotency_key' => (string) Str::uuid(),
            'last_event_sequence' => 0,
        ], $attributes));

        $this->createdDeliveryIds[] = $delivery->id;

        return $delivery;
    }

    private function payload(Delivery $delivery, string $status, int $sequence): array
    {
        return [
            'event_type' => 'delivery.status_changed',
            'sequence' => $sequence,
            'occurred_at' => now()->toIso8601String(),
            'delivery' => [
                'delivery_id' => $delivery->dzpatch_delivery_id,
                'external_order_id' => $delivery->external_order_id,
                'status' => $status,
            ],
        ];
    }

    private function eventId(): string
    {
        $id = 'evt_'.Str::uuid();
        $this->createdEventIds[] = $id;

        return $id;
    }

    public function test_it_applies_a_status_change(): void
    {
        $delivery = $this->delivery();
        $processor = app(DeliveryEventProcessor::class);

        $event = $processor->process(
            $this->eventId(),
            $this->payload($delivery, DeliveryStatus::PickedUp->value, 1),
        );

        $this->assertSame('processed', $event->processing_status);
        $this->assertSame(DeliveryStatus::PickedUp->value, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->picked_up_at);
    }

    public function test_the_same_event_twice_changes_state_once(): void
    {
        $delivery = $this->delivery();
        $processor = app(DeliveryEventProcessor::class);
        $eventId = $this->eventId();
        $payload = $this->payload($delivery, DeliveryStatus::PickedUp->value, 1);

        $processor->process($eventId, $payload);

        // Dzpatch retries on any non-2xx, so this arrives in normal operation.
        $second = $processor->process($eventId, $payload);

        $this->assertSame(
            1,
            DeliveryEvent::query()->where('dzpatch_event_id', $eventId)->count(),
            'A retried webhook must not create a second event row.',
        );

        $this->assertSame('processed', $second->processing_status);
    }

    public function test_a_stale_event_cannot_move_the_delivery_backwards(): void
    {
        $delivery = $this->delivery();
        $processor = app(DeliveryEventProcessor::class);

        $processor->process(
            $this->eventId(),
            $this->payload($delivery, DeliveryStatus::Delivered->value, 5),
        );

        // A picked_up that was delayed in transit and arrives after delivered.
        // Applying it would tell the customer their delivered food is still on
        // its way.
        $late = $processor->process(
            $this->eventId(),
            $this->payload($delivery, DeliveryStatus::PickedUp->value, 3),
        );

        $this->assertSame('ignored', $late->processing_status);
        $this->assertSame(DeliveryStatus::Delivered->value, $delivery->fresh()->status);
    }

    public function test_it_advances_through_a_normal_delivery(): void
    {
        $delivery = $this->delivery();
        $processor = app(DeliveryEventProcessor::class);

        foreach ([
            [1, DeliveryStatus::RiderAssigned->value],
            [2, DeliveryStatus::ArrivedPickup->value],
            [3, DeliveryStatus::PickedUp->value],
            [4, DeliveryStatus::InTransit->value],
            [5, DeliveryStatus::Delivered->value],
        ] as [$sequence, $status]) {
            $processor->process($this->eventId(), $this->payload($delivery, $status, $sequence));
        }

        $fresh = $delivery->fresh();

        $this->assertSame(DeliveryStatus::Delivered->value, $fresh->status);
        $this->assertSame(5, $fresh->last_event_sequence);
        $this->assertNotNull($fresh->picked_up_at);
        $this->assertNotNull($fresh->delivered_at);
    }

    public function test_it_stores_rider_details(): void
    {
        $delivery = $this->delivery();
        $processor = app(DeliveryEventProcessor::class);

        $payload = $this->payload($delivery, DeliveryStatus::RiderAssigned->value, 1);
        $payload['delivery']['rider'] = [
            'name' => 'Test Rider',
            'phone' => '+2348034968730',
            'lat' => 6.5244,
            'lng' => 3.3792,
        ];

        $processor->process($this->eventId(), $payload);

        $fresh = $delivery->fresh();

        $this->assertSame('Test Rider', $fresh->rider_name);
        $this->assertSame('+2348034968730', $fresh->rider_phone);
        $this->assertNotNull($fresh->rider_location_updated_at);
    }

    public function test_an_event_for_an_unknown_delivery_is_kept_not_discarded(): void
    {
        $processor = app(DeliveryEventProcessor::class);

        $eventId = $this->eventId();

        $event = $processor->process($eventId, [
            'event_type' => 'delivery.status_changed',
            'sequence' => 1,
            'delivery' => [
                'delivery_id' => (string) Str::uuid(),
                'external_order_id' => 'nothing-matches-this',
                'status' => DeliveryStatus::Delivered->value,
            ],
        ]);

        // Storing the orphan makes it visible. Discarding it would hide a real
        // delivery that exists on Dzpatch's side but not on ours.
        $this->assertSame('failed', $event->processing_status);
        $this->assertNotNull($event->id);
    }

    public function test_it_adopts_a_delivery_that_has_no_dzpatch_id_yet(): void
    {
        // The window where Dzpatch accepted the delivery but the response never
        // reached us. The first webhook should repair the gap.
        $delivery = $this->delivery(['dzpatch_delivery_id' => null]);
        $remoteId = (string) Str::uuid();

        $processor = app(DeliveryEventProcessor::class);

        $processor->process($this->eventId(), [
            'event_type' => 'delivery.status_changed',
            'sequence' => 1,
            'delivery' => [
                'delivery_id' => $remoteId,
                'external_order_id' => $delivery->external_order_id,
                'status' => DeliveryStatus::RiderAssigned->value,
            ],
        ]);

        $this->assertSame($remoteId, $delivery->fresh()->dzpatch_delivery_id);
    }
}
