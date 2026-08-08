<?php

namespace Tests\Unit\Delivery;

use App\Services\Delivery\DeliveryPayloadBuilder;
use App\Services\Delivery\Exceptions\DeliveryNotDispatchableException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phone normalisation, tested directly.
 *
 * Dzpatch rejects anything that is not E.164, and Nigerian numbers are stored
 * in several shapes across FoodHunts. A number normalised incorrectly does not
 * fail loudly: it reaches a stranger, so these cases are checked exhaustively.
 */
class DeliveryPayloadBuilderTest extends TestCase
{
    private function normalize(?string $input): ?string
    {
        $method = new ReflectionMethod(DeliveryPayloadBuilder::class, 'normalizePhone');
        $method->setAccessible(true);

        return $method->invoke(new DeliveryPayloadBuilder, $input);
    }

    public function test_it_converts_a_local_nigerian_number(): void
    {
        $this->assertSame('+2348034968730', $this->normalize('08034968730'));
    }

    public function test_it_keeps_an_existing_e164_number(): void
    {
        $this->assertSame('+2348034968730', $this->normalize('+2348034968730'));
    }

    public function test_it_adds_the_plus_to_a_country_coded_number(): void
    {
        $this->assertSame('+2348034968730', $this->normalize('2348034968730'));
    }

    public function test_it_adds_the_country_code_to_a_bare_number(): void
    {
        $this->assertSame('+2348034968730', $this->normalize('8034968730'));
    }

    public function test_it_strips_formatting(): void
    {
        $this->assertSame('+2348034968730', $this->normalize('+234 803 496 8730'));
        $this->assertSame('+2348034968730', $this->normalize('0803-496-8730'));
        $this->assertSame('+2348034968730', $this->normalize('  08034968730  '));
    }

    public function test_it_returns_null_rather_than_guessing(): void
    {
        // A wrong number reaches a stranger's phone, which is worse than no
        // number at all. Anything ambiguous must resolve to null.
        $this->assertNull($this->normalize(null));
        $this->assertNull($this->normalize(''));
        $this->assertNull($this->normalize('   '));
        $this->assertNull($this->normalize('12345'));
        $this->assertNull($this->normalize('not a phone number'));
        $this->assertNull($this->normalize('+0803496873'));
    }

    public function test_the_exception_names_the_missing_field(): void
    {
        // 13 of 50 active restaurants have no coordinates, so this path runs
        // on day one. The message has to say which field is missing, because
        // the fix is always in FoodHunts data.
        $e = new DeliveryNotDispatchableException(
            'Restaurant abc has no pickup coordinates. Set its location before dispatching deliveries.'
        );

        $this->assertStringContainsString('coordinates', $e->getMessage());
    }
}
