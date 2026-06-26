<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('foodhunts:heartbeat', function (): void {
    $this->comment('Foodhunts backend is ready.');
})->purpose('Print a backend readiness message');
