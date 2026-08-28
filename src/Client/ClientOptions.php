<?php

declare(strict_types=1);

namespace BladePDF\Client;

use InvalidArgumentException;

final readonly class ClientOptions
{
    public function __construct(
        public string $baseUrl = 'https://api.bladepdf.com',
        public int $timeout = 60,
        public int $connectTimeout = 10,
        public int $retryTimes = 1,
        public int $retrySleepMilliseconds = 1000,
        public bool $verifySsl = true,
        public string $userAgent = 'bladepdf-php/1.0',
    ) {
        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new InvalidArgumentException('BladePDF timeouts must be positive integers.');
        }

        if ($retryTimes < 0 || $retrySleepMilliseconds < 0) {
            throw new InvalidArgumentException('BladePDF retry values may not be negative.');
        }

        if (trim($baseUrl) === '') {
            throw new InvalidArgumentException('BladePDF base URL cannot be empty.');
        }

        if (trim($userAgent) === '') {
            throw new InvalidArgumentException('BladePDF user agent cannot be empty.');
        }
    }
}
