<?php

namespace App\Services\HostedApi;

use Illuminate\Http\Client\Response;

class HostedApiErrorMapper
{
    public function responseData(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }

    public function messageFromPayload(array $payload): ?string
    {
        foreach (['message', 'error', 'detail'] as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if (is_array($payload['data'] ?? null)) {
            return $this->messageFromPayload($payload['data']);
        }

        $errors = $payload['errors'] ?? null;

        if (is_array($errors)) {
            foreach ($errors as $error) {
                $value = is_array($error) ? reset($error) : $error;

                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return null;
    }

    public function messageForResponse(Response $response, string $fallback): string
    {
        $payload = $response->json();

        if (is_array($payload) && ($message = $this->messageFromPayload($payload)) !== null) {
            return $message;
        }

        if ($response->status() === 429) {
            $retryAfter = (int) ($response->json('retry_after') ?? 0);

            return $retryAfter > 0
                ? "License key is rate-limited. Try again in {$retryAfter} seconds."
                : 'License key is rate-limited. Please wait and try again.';
        }

        return $fallback;
    }

    public function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
