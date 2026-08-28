<?php

declare(strict_types=1);

namespace BladePDF;

use BladePDF\Assets\AssetResolver;
use BladePDF\Assets\AssetResolverOptions;
use BladePDF\Client\BladePdfClient;
use BladePDF\Client\ClientOptions;
use BladePDF\Contracts\RenderClient;
use GuzzleHttp\ClientInterface;

final readonly class BladePdf
{
    public function __construct(
        private RenderClient $client,
        private AssetResolver $assetResolver,
    ) {
    }

    public static function create(
        string $apiKey,
        ?ClientOptions $clientOptions = null,
        ?AssetResolverOptions $assetOptions = null,
        ?ClientInterface $httpClient = null,
    ): self {
        return new self(
            new BladePdfClient($apiKey, $clientOptions ?? new ClientOptions(), $httpClient),
            new AssetResolver($assetOptions ?? new AssetResolverOptions()),
        );
    }

    public function make(): PendingRender
    {
        return new PendingRender($this->client, $this->assetResolver);
    }

    public function fromHtml(string $html, ?string $baseDirectory = null): PendingRender
    {
        return $this->make()->fromHtml($html, $baseDirectory);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function fromTemplate(string $templateId, array $context = []): PendingRender
    {
        return $this->make()->fromTemplate($templateId, $context);
    }
}
