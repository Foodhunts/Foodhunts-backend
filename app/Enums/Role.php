<?php

namespace App\Enums;

enum Role: string
{
    case Customer = 'customer';
    case RestaurantOwner = 'restaurant_owner';
    case Admin = 'admin';
    case Courier = 'courier';
}
