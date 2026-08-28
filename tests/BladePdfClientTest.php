<?php

declare(strict_types=1);

namespace BladePDF\Tests;

use BladePDF\Assets\ResolvedAsset;
use BladePDF\Client\BladePdfClient;
use BladePDF\Client\ClientOptions;
use BladePDF\Exceptions\RenderFailedException;
use BladePDF\RenderRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class BladePdfClientTest extends TestCase
{
    public function test_it_sends_sync_multipart_and_parses_result_headers(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [
                'Content-Type' => 'application/pdf',
                'x-request-id' => 'request-1',
                'Link' => '<https://app.bladepdf.test/render.pdf?signature=a,b>; rel="stored-pdf"; type="application/pdf"',
            ], 'pdf-bytes'),
        ], $history);

        $result = $client->render(new RenderRequest(
            source: ['type' => 'html'],
            html: '<h1>Hello</h1>',
            storePdf: true,
            pdfOptions: ['format' => 'A4'],
        ));

        self::assertSame('pdf-bytes', $result->pdf());
        self::assertSame('request-1', $result->requestId());
        self::assertSame('https://app.bladepdf.test/render.pdf?signature=a,b', $result->storedPdfUrl());
        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('Bearer test-key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/pdf', $request->getHeaderLine('Accept'));
        $body = (string) $request->getBody();
        self::assertStringContainsString('name="source"', $body);
        self::assertStringContainsString('{"type":"html"}', $body);
        self::assertStringContainsString('filename="html.html"', $body);
    }

    public function test_it_sends_empty_context_as_an_object_and_parses_async_submission(): void
    {
        $history = [];
        $client = $this->client([
            new Response(202, ['x-request-id' => 'gateway-request'], json_encode([
                'request_id' => 'request-async',
                'reference' => 'INV-1',
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $submission = $client->renderAsync(new RenderRequest(
            source: ['type' => 'template', 'templateId' => 'invoice.standard'],
            context: [],
            metadata: ['reference' => 'INV-1'],
            storePdf: true,
        ));

        self::assertSame('request-async', $submission->requestId);
        self::assertSame('INV-1', $submission->reference);
        $request = $history[0]['request'];
        self::assertSame('respond-async', $request->getHeaderLine('Prefer'));
        self::assertStringContainsString("\r\n{}\r\n", (string) $request->getBody());
    }

    public function test_multipart_preserves_all_file_parts_mime_types_and_core_user_agent(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], 'pdf')], $history);

        $client->render(new RenderRequest(
            source: ['type' => 'html'],
            html: '<main>Body</main>',
            headerHtml: '<header>Header</header>',
            footerHtml: '<footer>Footer</footer>',
            waitUntil: 'function',
            waitFunction: 'window.ready === true',
            emulateMedia: 'print',
            metadata: ['reference' => 'INV-1'],
            storePdf: true,
            webhook: ['url' => 'https://example.test/hook', 'secret' => 'whsec_test', 'events' => ['pdf.rendered']],
            pdfOptions: ['format' => 'A4'],
            assets: [new ResolvedAsset(
                fieldName: 'asset:///logo.svg',
                filename: 'logo.svg',
                contents: '<svg></svg>',
                mimeType: 'image/svg+xml',
                sourcePath: null,
            )],
        ));

        $request = $history[0]['request'];
        $body = (string) $request->getBody();

        self::assertSame('bladepdf-php/1.0', $request->getHeaderLine('User-Agent'));
        self::assertStringContainsString('name="header_html"; filename="header.html"', $body);
        self::assertStringContainsString('name="footer_html"; filename="footer.html"', $body);
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $body);
        self::assertStringContainsString('name="asset:///logo.svg"; filename="logo.svg"', $body);
        self::assertStringContainsString('Content-Type: image/svg+xml', $body);
        self::assertStringContainsString('window.ready === true', $body);
    }

    public function test_retryable_responses_are_retried_but_validation_errors_are_not(): void
    {
        $retryHistory = [];
        $retrying = $this->client([
            new Response(503),
            new Response(200, [], 'pdf'),
        ], $retryHistory, retries: 1);
        $retrying->render(new RenderRequest(source: ['type' => 'html'], html: 'ok'));
        self::assertCount(2, $retryHistory);

        $failureHistory = [];
        $failing = $this->client([new Response(400, ['x-request-id' => 'bad-1'], 'bad input')], $failureHistory, retries: 3);

        try {
            $failing->render(new RenderRequest(source: ['type' => 'html'], html: 'bad'));
            self::fail('Validation error did not throw.');
        } catch (RenderFailedException $exception) {
            self::assertSame(400, $exception->statusCode());
            self::assertSame('bad-1', $exception->requestId());
            self::assertSame('bad input', $exception->responseBody());
        }

        self::assertCount(1, $failureHistory);
    }

    public function test_every_retryable_status_is_retried_after_the_initial_attempt(): void
    {
        foreach ([429, 502, 503, 504] as $status) {
            $history = [];
            $client = $this->client([
                new Response($status, ['Retry-After' => '0']),
                new Response(200, [], 'pdf'),
            ], $history, retries: 1);

            self::assertSame('pdf', $client->render(new RenderRequest(
                source: ['type' => 'html'],
                html: '<p>retry</p>',
            ))->pdf());
            self::assertCount(2, $history, 'HTTP '.$status.' should be retried once.');
        }
    }

    public function test_transport_error_message_bounds_the_response_but_preserves_typed_body(): void
    {
        $history = [];
        $body = str_repeat('x', 2048);
        $client = $this->client([new Response(422, ['x-request-id' => 'request-bad'], $body)], $history);

        try {
            $client->render(new RenderRequest(source: ['type' => 'html'], html: 'bad'));
            self::fail('Render failure was not thrown.');
        } catch (RenderFailedException $exception) {
            self::assertSame($body, $exception->responseBody());
            self::assertSame('request-bad', $exception->requestId());
            self::assertLessThan(1200, strlen($exception->getMessage()));
        }
    }

    /**
     * @param  list<Response>  $responses
     * @param  array<int, mixed>  $history
     */
    private function client(array $responses, array &$history, int $retries = 0): BladePdfClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new BladePdfClient(
            'test-key',
            new ClientOptions(baseUrl: 'https://api.bladepdf.test', retryTimes: $retries, retrySleepMilliseconds: 0),
            new Client(['handler' => $stack]),
        );
    }
}
