<?php

namespace App\Services\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class TrustedHttpClient
{
    public function options(array $options = []): array
    {
        return array_replace(['verify' => $this->caBundlePath()], $options);
    }

    public function caBundlePath(): string
    {
        return (string) config('services.http.ca_bundle', base_path('php/extras/ssl/cacert.pem'));
    }

    public function logConnectionFailure(string $message, string $url, Throwable $exception, array $context = []): void
    {
        Log::error($message, array_merge($context, [
            'url' => $url,
            'ca_bundle' => $this->caBundlePath(),
            'ca_bundle_exists' => is_file($this->caBundlePath()),
            'exception_chain' => $this->exceptionChain($exception),
            'handler_context' => $this->handlerContext($exception),
        ]));
    }

    public function logHttpFailure(string $message, string $url, Response $response, array $context = []): void
    {
        Log::error($message, array_merge($context, [
            'url' => $url,
            'status' => $response->status(),
            'response' => mb_substr($response->body(), 0, 4000),
        ]));
    }

    /** @return array<int, array{class: string, message: string, code: int|string}> */
    private function exceptionChain(Throwable $exception): array
    {
        $chain = [];

        do {
            $chain[] = [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ];
            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);

        return $chain;
    }

    private function handlerContext(Throwable $exception): array
    {
        do {
            if (method_exists($exception, 'getHandlerContext')) {
                return $exception->getHandlerContext();
            }

            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);

        return [];
    }
}
