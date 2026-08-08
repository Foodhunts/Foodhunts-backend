<?php

namespace Tests\Unit\Delivery;

use App\Services\Delivery\DzpatchWebhookVerifier;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The webhook endpoint is publicly reachable, so this signature check is the
 * only thing preventing anyone from marking an arbitrary order delivered.
 * These tests exist to prove it fails closed.
 */
class DzpatchWebhookVerifierTest extends TestCase
{
    private const SECRET = 'test-webhook-secret-value';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('dzpatch.webhook_secret', self::SECRET);
        config()->set('dzpatch.webhook_tolerance_seconds', 300);
    }

    private function request(
        string $body,
        ?string $timestamp = null,
        ?string $signature = null,
        ?string $secret = null,
    ): Request {
        $timestamp ??= (string) time();

        $signature ??= 'v1='.hash_hmac(
            'sha256',
            $timestamp.'.'.$body,
            $secret ?? self::SECRET,
        );

        $request = Request::create(
            '/api/v2/webhooks/dzpatch',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body,
        );

        $request->headers->set('X-Dzpatch-Signature', $signature);
        $request->headers->set('X-Dzpatch-Timestamp', $timestamp);

        return $request;
    }

    public function test_it_accepts_a_correctly_signed_request(): void
    {
        $verifier = new DzpatchWebhookVerifier;

        $this->assertTrue(
            $verifier->verify($this->request('{"event_type":"delivery.status_changed"}'))
        );
    }

    public function test_it_rejects_a_tampered_body(): void
    {
        $verifier = new DzpatchWebhookVerifier;

        // Signed as one order, delivered as another. This is the attack the
        // signature exists to stop.
        $timestamp = (string) time();
        $signed = '{"delivery":{"external_order_id":"order-a"}}';
        $sent = '{"delivery":{"external_order_id":"order-b"}}';

        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$signed, self::SECRET);

        $this->assertFalse(
            $verifier->verify($this->request($sent, $timestamp, $signature))
        );
    }

    public function test_it_rejects_a_signature_made_with_the_wrong_secret(): void
    {
        $verifier = new DzpatchWebhookVerifier;

        $this->assertFalse(
            $verifier->verify($this->request('{}', null, null, 'not-the-real-secret'))
        );
    }

    public function test_it_rejects_a_stale_timestamp(): void
    {
        $verifier = new DzpatchWebhookVerifier;

        // Correctly signed, but captured an hour ago. A valid signature stays
        // valid forever, so without a freshness window a captured request
        // could be replayed indefinitely.
        $stale = (string) (time() - 3600);

        $this->assertFalse($verifier->verify($this->request('{}', $stale)));
    }

    public function test_it_rejects_a_timestamp_from_the_future(): void
    {
        $verifier = new DzpatchWebhookVerifier;

        $this->assertFalse(
            $verifier->verify($this->request('{}', (string) (time() + 3600)))
        );
    }

    public function test_it_fails_closed_when_no_secret_is_configured(): void
    {
        config()->set('dzpatch.webhook_secret', '');

        $verifier = new DzpatchWebhookVerifier;

        // An unconfigured secret must reject everything. Accepting unsigned
        // webhooks because none can be verified would defeat the point.
        $this->assertFalse($verifier->verify($this->request('{}')));
    }

    public function test_it_rejects_a_request_with_no_signature_header(): void
    {
        $verifier = new DzpatchWebhookVerifier;

        $request = Request::create('/api/v2/webhooks/dzpatch', 'POST', [], [], [], [], '{}');
        $request->headers->set('X-Dzpatch-Timestamp', (string) time());

        $this->assertFalse($verifier->verify($request));
    }

    public function test_it_accepts_a_signature_without_the_v1_prefix(): void
    {
        $verifier = new DzpatchWebhookVerifier;

        $timestamp = (string) time();
        $body = '{"ok":true}';
        $bare = hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        $this->assertTrue($verifier->verify($this->request($body, $timestamp, $bare)));
    }

    public function test_whitespace_changes_invalidate_the_signature(): void
    {
        $verifier = new DzpatchWebhookVerifier;

        // Guards the decision to verify against the raw body: re-encoding a
        // decoded payload changes spacing and key order, and would never match.
        $timestamp = (string) time();
        $signed = '{"a":1,"b":2}';
        $reencoded = '{"a": 1, "b": 2}';

        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$signed, self::SECRET);

        $this->assertFalse(
            $verifier->verify($this->request($reencoded, $timestamp, $signature))
        );
    }
}
