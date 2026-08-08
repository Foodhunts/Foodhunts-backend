<?php

return [
    /*
     * Dzpatch partner API v1. Points at the partner-api-v1 function; the client
     * appends "/deliveries" and the route beneath it.
     *
     * v1 is the authoritative surface: it authenticates against partner_api_keys
     * and moves money in a single transaction. The older partner-deliveries
     * function authenticates against the legacy partner_accounts.api_key_hash
     * column, which Dzpatch's own Phase 0 decision record marks as retained for
     * compatibility only.
     */
    'base_url' => env('DZPATCH_API_URL', ''),

    'api_key' => env('DZPATCH_API_KEY', ''),

    /*
     * Shared secret for verifying inbound webhooks. Signatures are computed
     * over "{timestamp}.{raw body}" with HMAC-SHA256 and arrive as
     * X-Dzpatch-Signature: v1=<hex>.
     */
    'webhook_secret' => env('DZPATCH_WEBHOOK_SECRET', ''),

    /*
     * How far a webhook's timestamp may drift from ours before it is refused.
     * Bounds how long a captured request stays replayable; the inbox already
     * stops a replay from applying twice, so this is a second line rather than
     * the only one.
     */
    'webhook_tolerance_seconds' => (int) env('DZPATCH_WEBHOOK_TOLERANCE', 300),

    'timeout' => (int) env('DZPATCH_TIMEOUT', 20),

    'connect_timeout' => (int) env('DZPATCH_CONNECT_TIMEOUT', 10),

    /*
     * Master switch. Off by default so that merging this code cannot start
     * dispatching real deliveries anywhere it has not been deliberately
     * enabled.
     */
    'enabled' => filter_var(env('DZPATCH_ENABLED', false), FILTER_VALIDATE_BOOL),

    /*
     * Where the pickup happens when a restaurant has no coordinates of its own.
     * A dispatch with no usable pickup point is refused rather than sent with a
     * fallback, so these are used only to fill in a partial address.
     */
    'default_pickup_instructions' => env('DZPATCH_DEFAULT_PICKUP_INSTRUCTIONS'),
];
