<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A request Pixelforge rejected (or that never reached it). Branch on $errorCode,
 * as the docs recommend; $retryAfter comes from the Retry-After header.
 */
class PixelforgeException extends RuntimeException
{
    /** Errors where nothing was created and the same request may succeed later. */
    private const RETRYABLE = [
        'rate_limit_exceeded',
        'concurrency_limit_exceeded',
        'overloaded',
        'connection_error',
        'server_error',
    ];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorType = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $param = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error') ?? [];
        $retryAfter = $response->header('Retry-After');

        return new self(
            errorCode: $error['code'] ?? ($response->serverError() ? 'server_error' : 'unknown_error'),
            message: $error['message'] ?? "Pixelforge responded with HTTP {$response->status()}.",
            httpStatus: $response->status(),
            errorType: $error['type'] ?? null,
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
            param: $error['param'] ?? null,
        );
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE, true);
    }

    /** Message safe to show to the end user. */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'content_policy_violation' => 'Your prompt was rejected by the content policy.',
            'validation_failed' => 'Pixelforge rejected the request: '.$this->getMessage(),
            'daily_limit_exceeded' => 'The daily generation limit has been reached. Please try again tomorrow.',
            'rate_limit_exceeded', 'concurrency_limit_exceeded' => 'Pixelforge is busy right now and we could not submit your image after several attempts.',
            'overloaded', 'server_error', 'connection_error', 'submission_abandoned' => 'Pixelforge is temporarily unavailable and we could not submit your image after several attempts.',
            default => 'Pixelforge could not accept this request.',
        };
    }
}
