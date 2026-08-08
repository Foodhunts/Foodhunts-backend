<?php

namespace App\Services\Delivery\Exceptions;

use RuntimeException;

/**
 * The order cannot be turned into a valid Dzpatch request.
 *
 * Raised before anything is sent, so nothing exists on Dzpatch's side when it
 * happens. The message names the missing field, because the fix is always in
 * FoodHunts data: a restaurant without coordinates, a customer without a phone
 * number, an order without items.
 */
class DeliveryNotDispatchableException extends RuntimeException
{
}
