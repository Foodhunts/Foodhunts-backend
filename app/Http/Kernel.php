<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middlewareAliases = [
        'admin' => \App\Http\Middleware\EnsureAdminRole::class,
        'role' => \App\Http\Middleware\EnsureRole::class,
    ];
}
