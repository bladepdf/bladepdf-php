<?php

declare(strict_types=1);

namespace BladePDF\Exceptions;

use Throwable;

class RenderFailedException extends BladePdfException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly ?string $requestId = null,
        private readonly string $responseBody = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function fromResponse(int $statusCode, string $body, ?string $requestId = null): self
    {
        $excerpt = trim($body);

        if (strlen($excerpt) > 1024) {
            $excerpt = substr($excerpt, 0, 1024).'…';
        }

        $requestSuffix = $requestId !== null && $requestId !== ''
            ? sprintf(' Request ID: %s.', $requestId)
            : '';
        $bodySuffix = $excerpt !== '' ? sprintf(' Response: %s', $excerpt) : '';

        return new self(
            sprintf('BladePDF render request failed with status %d.%s%s', $statusCode, $requestSuffix, $bodySuffix),
            $statusCode,
            $requestId,
            $body,
        );
    }

    public static function fromTransport(Throwable $exception): self
    {
        return new self(
            'BladePDF render request could not be completed: '.$exception->getMessage(),
            previous: $exception,
        );
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function responseBody(): string
    {
        return $this->responseBody;
    }
}
