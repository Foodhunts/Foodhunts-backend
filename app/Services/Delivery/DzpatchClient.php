<?php

namespace App\Services\Delivery;

use App\Services\Delivery\Exceptions\DzpatchRejectedException;
use App\Services\Delivery\Exceptions\DzpatchUnavailableException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HTTP client for the Dzpatch partner API.
 *
 * Speaks only to Dzpatch and knows nothing about orders. The distinction it
 * does draw is between two kinds of failure, because they need opposite
 * responses:
 *
 *   - DzpatchRejectedException: Dzpatch understood the request and refused it.
 *     Sending it again unchanged will fail again.
 *   - DzpatchUnavailableException: we never got a verdict. The delivery may or
 *     may not exist on their side, so a retry must reuse the idempotency key.
 *
 * Collapsing these into one exception would mean either retrying a request that
 * can never succeed, or abandoning a delivery that was in fact created.
 */
class DzpatchClient
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $apiKey = null,
        private readonly ?int $timeout = null,
    ) {
    }

    private function baseUrl(): string
    {
        $url = $this->baseUrl ?? (string) config('dzpatch.base_url');

        if ($url === '') {
            throw new DzpatchUnavailableException('Dzpatch base URL is not configured.');
        }

        return rtrim($url, '/');
    }

    private function apiKey(): string
    {
        $key = $this->apiKey ?? (string) config('dzpatch.api_key');

        if ($key === '') {
            throw new DzpatchUnavailableException('Dzpatch API key is not configured.');
        }

        return $key;
    }

    /**
     * Create a delivery, or return the existing one for this idempotency key.
     *
     * Dzpatch treats a repeated Idempotency-Key as a reference to the original
     * request, so this is safe to call again after a timeout.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createDelivery(array $payload, string $idempotencyKey): array
    {
        return $this->send('POST', '', $payload, $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDelivery(string $deliveryId): array
    {
        return $this->send('GET', '/'.urlencode($deliveryId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getDeliveryByExternalOrderId(string $externalOrderId): array
    {
        return $this->send('GET', '/by-external/'.urlencode($externalOrderId));
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelDelivery(string $deliveryId, ?string $reason = null): array
    {
        return $this->send('POST', '/'.urlencode($deliveryId).'/cancel', [
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function send(
        string $method,
        string $path,
        ?array $payload = null,
        ?string $idempotencyKey = null,
    ): array {
        $headers = ['Accept' => 'application/json'];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $timeout = $this->timeout ?? (int) config('dzpatch.timeout', 20);

        try {
            $request = Http::withToken($this->apiKey())
                ->withHeaders($headers)
                ->timeout($timeout)
                ->connectTimeout((int) config('dzpatch.connect_timeout', 10))
                ->asJson();

            $response = $method === 'GET'
                ? $request->get($this->baseUrl().$path)
                : $request->post($this->baseUrl().$path, $payload ?? []);
        } catch (Throwable $e) {
            // No response at all. Whether Dzpatch acted on the request is
            // unknown, so this must stay distinct from an explicit refusal.
            throw new DzpatchUnavailableException(
                'Could not reach Dzpatch: '.$e->getMessage(),
                previous: $e,
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            // A 2xx with an unreadable body is not a success we can act on.
            throw new DzpatchUnavailableException(
                'Dzpatch returned a non-JSON response (HTTP '.$response->status().').',
            );
        }

        if ($response->successful()) {
            return $body;
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = is_string($error['code'] ?? null) ? $error['code'] : 'unknown_error';
        $message = is_string($error['message'] ?? null)
            ? $error['message']
            : 'Dzpatch rejected the request.';

        // 5xx, 408 and 429 are transient by nature: the same request may well
        // succeed later, so they are reported as unavailability rather than
        // refusal.
        if ($response->status() >= 500 || in_array($response->status(), [408, 429], true)) {
            throw new DzpatchUnavailableException(
                'Dzpatch is unavailable (HTTP '.$response->status().'): '.$message,
            );
        }

        Log::warning('Dzpatch rejected a partner request', [
            'status' => $response->status(),
            'code' => $code,
            'path' => $path,
        ]);

        throw new DzpatchRejectedException($message, $code, $response->status(), $error);
    }
}
