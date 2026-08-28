<?php

declare(strict_types=1);

namespace BladePDF\Webhooks;

final class SignatureVerifier
{
    public const DEFAULT_TOLERANCE = 300;

    public static function isValid(
        string $rawBody,
        ?string $timestamp,
        ?string $signature,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $currentTimestamp = null,
    ): bool {
        if (
            $timestamp === null
            || $signature === null
            || trim($secret) === ''
            || $tolerance < 0
        ) {
            return false;
        }

        $timestamp = trim($timestamp);
        $signature = trim($signature);

        if (
            preg_match('/\A[1-9][0-9]*\z/D', $timestamp) !== 1
            || preg_match('/\Av1=[a-f0-9]{64}\z/D', $signature) !== 1
        ) {
            return false;
        }

        $timestampValue = filter_var(
            $timestamp,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($timestampValue === false) {
            return false;
        }

        $currentTimestamp ??= time();

        if ($tolerance > 0 && abs($currentTimestamp - $timestampValue) > $tolerance) {
            return false;
        }

        $expected = 'v1='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, $signature);
    }
}
