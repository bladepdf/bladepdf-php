<?php

declare(strict_types=1);

namespace BladePDF\Tests;

use BladePDF\Webhooks\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase
{
    public function test_it_verifies_exact_raw_body_and_timestamp_tolerance(): void
    {
        $body = '{"type":"pdf.rendered"}';
        $timestamp = '1787688000';
        $secret = 'whsec_test';
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        self::assertTrue(SignatureVerifier::isValid($body, $timestamp, $signature, $secret, 300, 1787688000));
        self::assertFalse(SignatureVerifier::isValid($body.' ', $timestamp, $signature, $secret, 300, 1787688000));
        self::assertFalse(SignatureVerifier::isValid($body, $timestamp, $signature, $secret, 300, 1787688301));
        self::assertTrue(SignatureVerifier::isValid($body, $timestamp, $signature, $secret, 0, 1));
    }

    public function test_it_rejects_malformed_headers_and_missing_secret(): void
    {
        self::assertFalse(SignatureVerifier::isValid('{}', null, null, 'secret'));
        self::assertFalse(SignatureVerifier::isValid('{}', 'nope', 'v1=bad', 'secret'));
        self::assertFalse(SignatureVerifier::isValid('{}', '1', 'v1='.str_repeat('a', 64), ''));
        self::assertFalse(SignatureVerifier::isValid('{}', '1', 'v1='.str_repeat('a', 64), 'secret', -1));
    }
}
