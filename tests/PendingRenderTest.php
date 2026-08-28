<?php

declare(strict_types=1);

namespace BladePDF\Tests;

use BladePDF\Assets\AssetResolver;
use BladePDF\Contracts\RenderClient;
use BladePDF\Exceptions\InvalidRenderConfigurationException;
use BladePDF\PendingRender;
use BladePDF\RenderRequest;
use BladePDF\RenderResult;
use BladePDF\RenderSubmission;
use PHPUnit\Framework\TestCase;

final class PendingRenderTest extends TestCase
{
    public function test_it_builds_a_sync_html_request_with_options_and_metadata(): void
    {
        $client = new CapturingRenderClient();

        $result = (new PendingRender($client, new AssetResolver()))
            ->fromHtml('<h1>Hello</h1>')
            ->withHeaderHtml('<p>Header</p>')
            ->withFooterHtml('<p>Footer</p>')
            ->reference('INV-1')
            ->templateName('Invoice')
            ->format('A4')
            ->margins(10, 12, 14, 16, 'mm')
            ->showBackground()
            ->waitFunction('window.ready === true')
            ->render();

        self::assertSame('pdf-bytes', $result->pdf());
        self::assertNotNull($client->request);
        self::assertSame(['type' => 'html'], $client->request->source);
        self::assertSame('<h1>Hello</h1>', $client->request->html);
        self::assertSame('<p>Header</p>', $client->request->headerHtml);
        self::assertSame('<p>Footer</p>', $client->request->footerHtml);
        self::assertSame(['reference' => 'INV-1', 'template_name' => 'Invoice'], $client->request->metadata);
        self::assertSame('function', $client->request->waitUntil);
        self::assertSame('window.ready === true', $client->request->waitFunction);
        self::assertSame('A4', $client->request->pdfOptions['format']);
        self::assertSame('14mm', $client->request->pdfOptions['margin']['bottom']);
    }

    public function test_it_builds_a_cloud_template_request_and_async_submission(): void
    {
        $client = new CapturingRenderClient();

        $submission = (new PendingRender($client, new AssetResolver()))
            ->fromTemplate('invoice.standard', ['invoice' => ['number' => 'INV-1']])
            ->withContext(['locale' => 'en'])
            ->reference('INV-1')
            ->storePdf()
            ->async();

        self::assertSame('request-1', $submission->requestId);
        self::assertNotNull($client->request);
        self::assertSame(['type' => 'template', 'templateId' => 'invoice.standard'], $client->request->source);
        self::assertSame([
            'invoice' => ['number' => 'INV-1'],
            'locale' => 'en',
        ], $client->request->context);
    }

    public function test_async_requires_storage(): void
    {
        $this->expectException(InvalidRenderConfigurationException::class);
        $this->expectExceptionMessage('require storePdf()');

        (new PendingRender(new CapturingRenderClient(), new AssetResolver()))
            ->fromHtml('<p>Hello</p>')
            ->async();
    }

    public function test_invalid_wait_media_and_reserved_asset_targets_are_rejected(): void
    {
        $render = new PendingRender(new CapturingRenderClient(), new AssetResolver());

        try {
            $render->waitUntil('idle');
            self::fail('Invalid wait mode was accepted.');
        } catch (InvalidRenderConfigurationException) {
            self::assertTrue(true);
        }

        try {
            $render->emulateMedia('mobile');
            self::fail('Invalid media was accepted.');
        } catch (InvalidRenderConfigurationException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidRenderConfigurationException::class);
        $render->withAsset(__FILE__, 'context');
    }
}

final class CapturingRenderClient implements RenderClient
{
    public ?RenderRequest $request = null;

    public function render(RenderRequest $request): RenderResult
    {
        $this->request = $request;

        return new RenderResult('pdf-bytes', requestId: 'request-1');
    }

    public function renderAsync(RenderRequest $request): RenderSubmission
    {
        $this->request = $request;

        return new RenderSubmission('request-1', $request->metadata['reference'] ?? null);
    }
}
