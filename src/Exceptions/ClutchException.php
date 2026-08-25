<?php

declare(strict_types=1);

namespace Clutch\Laravel\Exceptions;

use RuntimeException;

/**
 * Base class for every exception the harness raises.
 *
 * Messages on these exceptions are safe to render. Provider bodies, credentials
 * and stack detail belong on the previous exception and in protected logs.
 */
abstract class ClutchException extends RuntimeException
{
    /**
     * Get the machine-readable code applications can switch on.
     */
    public function errorCode(): string
    {
        return 'harness_error';
    }

    /**
     * Get the HTTP status this exception should render as.
     */
    public function statusCode(): int
    {
        return 500;
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render(): \Illuminate\Http\JsonResponse
    {
        return new \Illuminate\Http\JsonResponse([
            'error' => [
                'code' => $this->errorCode(),
                'message' => $this->getMessage(),
            ],
        ], $this->statusCode());
    }
}
