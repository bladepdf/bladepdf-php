<?php

declare(strict_types=1);

namespace BladePDF;

use BladePDF\Assets\ResolvedAsset;

final readonly class RenderRequest
{
    /**
     * @param  array{type:'html'}|array{type:'template',templateId:string}  $source
     * @param  array<string, mixed>|null  $context
     * @param  array{reference?:string,template_name?:string}|null  $metadata
     * @param  array{url:string,secret:string,events:list<string>}|null  $webhook
     * @param  array<string, mixed>|null  $pdfOptions
     * @param  list<ResolvedAsset>  $assets
     */
    public function __construct(
        public array $source,
        public ?string $html = null,
        public ?string $headerHtml = null,
        public ?string $footerHtml = null,
        public ?array $context = null,
        public ?string $waitUntil = null,
        public ?string $waitFunction = null,
        public ?string $emulateMedia = null,
        public ?array $metadata = null,
        public ?bool $storePdf = null,
        public ?array $webhook = null,
        public ?array $pdfOptions = null,
        public array $assets = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return [
            'source' => $this->source,
            'wait_until' => $this->waitUntil,
            'wait_function' => $this->waitFunction,
            'emulate_media' => $this->emulateMedia,
            'metadata' => $this->metadata,
            'store_pdf' => $this->storePdf,
            'webhook' => $this->webhook,
            'html' => $this->html,
            'header_html' => $this->headerHtml,
            'footer_html' => $this->footerHtml,
            'context' => $this->context,
            'pdf_options' => $this->pdfOptions,
        ];
    }
}
