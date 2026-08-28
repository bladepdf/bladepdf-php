<?php

declare(strict_types=1);

namespace BladePDF;

use BladePDF\Assets\AssetResolver;
use BladePDF\Contracts\HtmlSource;
use BladePDF\Contracts\RenderClient;
use BladePDF\Exceptions\InvalidRenderConfigurationException;
use BladePDF\Sources\StringHtmlSource;

class PendingRender
{
    protected const SOURCE_HTML = 'html';

    protected const SOURCE_TEMPLATE = 'template';

    protected ?string $source = null;

    protected ?HtmlSource $bodySource = null;

    protected ?HtmlSource $headerSource = null;

    protected ?HtmlSource $footerSource = null;

    protected ?string $templateId = null;

    /** @var array<string, mixed> */
    protected array $context = [];

    /** @var array<string, mixed> */
    protected array $pdfOptions = [];

    protected ?string $waitUntil = null;

    protected ?string $waitFunction = null;

    protected ?string $emulateMedia = null;

    protected ?string $reference = null;

    protected ?string $templateName = null;

    protected ?bool $storePdf = null;

    /** @var array{url:string,secret:string,events:list<string>}|null */
    protected ?array $webhook = null;

    /** @var list<array{path:string,target?:string,mime?:string}> */
    protected array $manualAssets = [];

    protected ?bool $autoResolveAssets = null;

    public function __construct(
        protected RenderClient $client,
        protected AssetResolver $assetResolver,
    ) {
    }

    public function fromHtml(string $html, ?string $baseDirectory = null): static
    {
        return $this->fromHtmlSource(new StringHtmlSource($html, $baseDirectory));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function fromTemplate(string $templateId, array $context = []): static
    {
        if (trim($templateId) === '') {
            throw new InvalidRenderConfigurationException('BladePDF template id cannot be empty.');
        }

        $this->source = self::SOURCE_TEMPLATE;
        $this->templateId = $templateId;
        $this->context = $context;
        $this->bodySource = null;
        $this->headerSource = null;
        $this->footerSource = null;

        return $this;
    }

    /** @param array<string, mixed> $context */
    public function context(array $context): static
    {
        $this->context = $context;

        return $this;
    }

    /** @param array<string, mixed> $context */
    public function withContext(array $context): static
    {
        $this->context = array_replace_recursive($this->context, $context);

        return $this;
    }

    public function withHeaderHtml(string $html, ?string $baseDirectory = null): static
    {
        return $this->withHeaderSource(new StringHtmlSource($html, $baseDirectory));
    }

    public function withFooterHtml(string $html, ?string $baseDirectory = null): static
    {
        return $this->withFooterSource(new StringHtmlSource($html, $baseDirectory));
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        $this->pdfOptions = array_replace_recursive($this->pdfOptions, $options);

        return $this;
    }

    public function waitUntil(?string $waitUntil): static
    {
        if ($waitUntil !== null && ! in_array($waitUntil, ['load', 'domcontentloaded', 'networkidle0', 'networkidle2', 'function'], true)) {
            throw new InvalidRenderConfigurationException('BladePDF waitUntil must be one of: load, domcontentloaded, networkidle0, networkidle2, function.');
        }

        $this->waitUntil = $waitUntil;

        return $this;
    }

    public function waitFunction(?string $waitFunction): static
    {
        $this->waitFunction = $waitFunction;

        if ($waitFunction !== null) {
            $this->waitUntil = 'function';
        } elseif ($this->waitUntil === 'function') {
            $this->waitUntil = null;
        }

        return $this;
    }

    public function emulateMedia(?string $media): static
    {
        if ($media !== null && ! in_array($media, ['screen', 'print'], true)) {
            throw new InvalidRenderConfigurationException('BladePDF emulateMedia must be screen or print.');
        }

        $this->emulateMedia = $media;

        return $this;
    }

    public function resolveAssets(bool $resolve = true): static
    {
        $this->autoResolveAssets = $resolve;

        return $this;
    }

    public function withoutAssetResolution(): static
    {
        return $this->resolveAssets(false);
    }

    public function withAsset(string $path, ?string $target = null, ?string $mime = null): static
    {
        $asset = ['path' => $path];

        if ($target !== null) {
            $asset['target'] = $this->normalizeAssetTarget($target);
        }

        if ($mime !== null) {
            $asset['mime'] = $mime;
        }

        $this->manualAssets[] = $asset;

        return $this;
    }

    public function overrideAsset(string $assetKey, string $path, ?string $mime = null): static
    {
        return $this->withAsset($path, $assetKey, $mime);
    }

    public function reference(?string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function withReference(?string $reference): static
    {
        return $this->reference($reference);
    }

    public function templateName(?string $templateName): static
    {
        $this->templateName = $templateName;

        return $this;
    }

    public function withTemplateName(?string $templateName): static
    {
        return $this->templateName($templateName);
    }

    /** @param array{reference?:?string,template_name?:?string} $metadata */
    public function metadata(array $metadata): static
    {
        return $this->withMetadata($metadata);
    }

    /** @param array{reference?:?string,template_name?:?string} $metadata */
    public function withMetadata(array $metadata): static
    {
        $unsupported = array_diff(array_keys($metadata), ['reference', 'template_name']);

        if ($unsupported !== []) {
            throw new InvalidRenderConfigurationException(sprintf(
                'Unsupported BladePDF metadata field(s): %s.',
                implode(', ', $unsupported),
            ));
        }

        if (array_key_exists('reference', $metadata)) {
            $this->reference($metadata['reference'] !== null ? (string) $metadata['reference'] : null);
        }

        if (array_key_exists('template_name', $metadata)) {
            $this->templateName($metadata['template_name'] !== null ? (string) $metadata['template_name'] : null);
        }

        return $this;
    }

    public function storePdf(bool $store = true): static
    {
        $this->storePdf = $store;

        return $this;
    }

    public function dontStorePdf(): static
    {
        return $this->storePdf(false);
    }

    /** @param list<string> $events */
    public function webhook(string $url, string $secret, array $events = ['pdf.rendered', 'pdf.failed']): static
    {
        $this->webhook = [
            'url' => $this->normalizeWebhookUrl($url),
            'secret' => $this->normalizeWebhookSecret($secret),
            'events' => $this->normalizeWebhookEvents($events),
        ];

        return $this;
    }

    /** @param list<string> $events */
    public function withWebhook(string $url, string $secret, array $events = ['pdf.rendered', 'pdf.failed']): static
    {
        return $this->webhook($url, $secret, $events);
    }

    public function format(string $format): static
    {
        return $this->withOptions(['format' => $format]);
    }

    public function paperSize(string|int|float $width, string|int|float $height, string $unit = 'px'): static
    {
        return $this->withOptions([
            'width' => $this->formatPdfLength($width, $unit),
            'height' => $this->formatPdfLength($height, $unit),
        ]);
    }

    public function margins(
        string|int|float $top,
        string|int|float $right,
        string|int|float $bottom,
        string|int|float $left,
        string $unit = 'px',
    ): static {
        return $this->withOptions(['margin' => [
            'top' => $this->formatPdfLength($top, $unit),
            'right' => $this->formatPdfLength($right, $unit),
            'bottom' => $this->formatPdfLength($bottom, $unit),
            'left' => $this->formatPdfLength($left, $unit),
        ]]);
    }

    public function landscape(bool $landscape = true): static
    {
        return $this->withOptions(['landscape' => $landscape]);
    }

    public function portrait(): static
    {
        return $this->landscape(false);
    }

    public function showBackground(bool $show = true): static
    {
        return $this->withOptions(['printBackground' => $show]);
    }

    public function hideBackground(): static
    {
        return $this->showBackground(false);
    }

    public function transparentBackground(bool $transparent = true): static
    {
        return $this->withOptions(['omitBackground' => $transparent]);
    }

    public function scale(float $scale): static
    {
        if ($scale < 0.1 || $scale > 2.0) {
            throw new InvalidRenderConfigurationException('BladePDF scale must be between 0.1 and 2.0.');
        }

        return $this->withOptions(['scale' => $scale]);
    }

    public function pages(string $pageRanges): static
    {
        return $this->pageRanges($pageRanges);
    }

    public function pageRanges(string $pageRanges): static
    {
        return $this->withOptions(['pageRanges' => $pageRanges]);
    }

    public function taggedPdf(bool $tagged = true): static
    {
        return $this->withOptions(['tagged' => $tagged]);
    }

    public function preferCssPageSize(bool $prefer = true): static
    {
        return $this->withOptions(['preferCSSPageSize' => $prefer]);
    }

    public function waitForFonts(bool $wait = true): static
    {
        return $this->withOptions(['waitForFonts' => $wait]);
    }

    public function outline(bool $outline = true): static
    {
        return $this->withOptions(['outline' => $outline]);
    }

    public function render(): RenderResult
    {
        return $this->client->render($this->buildRequest());
    }

    public function async(): RenderSubmission
    {
        if ($this->storePdf !== true) {
            throw new InvalidRenderConfigurationException(
                'BladePDF async renders require storePdf() so the generated PDF remains available after the request is accepted.',
            );
        }

        return $this->client->renderAsync($this->buildRequest());
    }

    protected function fromHtmlSource(HtmlSource $source): static
    {
        $this->source = self::SOURCE_HTML;
        $this->bodySource = $source;
        $this->templateId = null;
        $this->context = [];

        return $this;
    }

    protected function withHeaderSource(HtmlSource $source): static
    {
        $this->headerSource = $source;

        return $this;
    }

    protected function withFooterSource(HtmlSource $source): static
    {
        $this->footerSource = $source;

        return $this;
    }

    protected function bodySource(): ?HtmlSource
    {
        return $this->bodySource;
    }

    protected function isTemplateSource(): bool
    {
        return $this->source === self::SOURCE_TEMPLATE;
    }

    protected function buildRequest(): RenderRequest
    {
        if ($this->source === null) {
            throw new InvalidRenderConfigurationException('BladePDF render source has not been configured.');
        }

        if ($this->source === self::SOURCE_TEMPLATE) {
            return $this->buildTemplateRequest();
        }

        if ($this->bodySource === null) {
            throw new InvalidRenderConfigurationException('BladePDF HTML render requires an HTML source.');
        }

        $bodyHtml = $this->bodySource->render();
        $headerHtml = $this->headerSource?->render();
        $footerHtml = $this->footerSource?->render();
        $resolved = $this->assetResolver->resolve(
            html: $bodyHtml,
            headerHtml: $headerHtml,
            footerHtml: $footerHtml,
            manualAssets: $this->manualAssets,
            autoResolveAssets: $this->autoResolveAssets,
            htmlBaseDirectory: $this->bodySource->baseDirectory(),
            headerBaseDirectory: $this->headerSource?->baseDirectory(),
            footerBaseDirectory: $this->footerSource?->baseDirectory(),
        );

        return new RenderRequest(
            source: ['type' => self::SOURCE_HTML],
            html: $resolved->html,
            headerHtml: $resolved->headerHtml,
            footerHtml: $resolved->footerHtml,
            waitUntil: $this->waitUntil,
            waitFunction: $this->waitFunction,
            emulateMedia: $this->emulateMedia,
            metadata: $this->metadataForRequest(),
            storePdf: $this->storePdf,
            webhook: $this->webhook,
            pdfOptions: $this->pdfOptionsForRequest(),
            assets: $resolved->assets,
        );
    }

    protected function buildTemplateRequest(): RenderRequest
    {
        if ($this->templateId === null) {
            throw new InvalidRenderConfigurationException('BladePDF template source requires a template id.');
        }

        if ($this->headerSource !== null || $this->footerSource !== null) {
            throw new InvalidRenderConfigurationException('BladePDF cloud template renders do not support header_html or footer_html overrides.');
        }

        $resolved = $this->assetResolver->resolve(
            html: '',
            manualAssets: $this->manualAssets,
            autoResolveAssets: $this->autoResolveAssets,
        );

        return new RenderRequest(
            source: ['type' => self::SOURCE_TEMPLATE, 'templateId' => $this->templateId],
            context: $this->context,
            waitUntil: $this->waitUntil,
            waitFunction: $this->waitFunction,
            emulateMedia: $this->emulateMedia,
            metadata: $this->metadataForRequest(),
            storePdf: $this->storePdf,
            webhook: $this->webhook,
            pdfOptions: $this->pdfOptionsForRequest(),
            assets: $resolved->assets,
        );
    }

    /** @return array{reference?:string,template_name?:string}|null */
    protected function metadataForRequest(): ?array
    {
        if ($this->source === self::SOURCE_TEMPLATE && $this->templateName !== null) {
            throw new InvalidRenderConfigurationException('BladePDF template_name metadata is only supported for HTML renders.');
        }

        $metadata = [];

        if ($this->reference !== null) {
            $metadata['reference'] = $this->reference;
        }

        if ($this->templateName !== null) {
            $metadata['template_name'] = $this->templateName;
        }

        return $metadata === [] ? null : $metadata;
    }

    /** @return array<string, mixed>|null */
    protected function pdfOptionsForRequest(): ?array
    {
        return $this->pdfOptions === [] ? null : $this->pdfOptions;
    }

    protected function normalizeAssetTarget(string $target): string
    {
        $target = str_starts_with($target, 'asset:///')
            ? substr($target, strlen('asset:///'))
            : $target;
        $target = ltrim($target, '/');

        if (
            $target === ''
            || preg_match('/^[A-Za-z0-9._-]+$/', $target) !== 1
            || in_array($target, ['html', 'header_html', 'footer_html', 'context'], true)
        ) {
            throw new InvalidRenderConfigurationException(
                'BladePDF asset targets may only contain letters, numbers, dots, underscores, and hyphens and may not use a reserved file field name.',
            );
        }

        return $target;
    }

    protected function normalizeWebhookUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 1024 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidRenderConfigurationException('BladePDF webhook URL must be a valid http or https URL.');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new InvalidRenderConfigurationException('BladePDF webhook URL must be a valid http or https URL.');
        }

        return $url;
    }

    protected function normalizeWebhookSecret(string $secret): string
    {
        $secret = trim($secret);

        if ($secret === '' || strlen($secret) > 1024) {
            throw new InvalidRenderConfigurationException('BladePDF webhook secret must contain between 1 and 1024 characters.');
        }

        return $secret;
    }

    /**
     * @param  list<string>  $events
     * @return list<string>
     */
    protected function normalizeWebhookEvents(array $events): array
    {
        $allowed = ['pdf.rendered', 'pdf.failed'];
        $normalized = [];

        foreach ($events as $event) {
            if (! is_string($event) || ! in_array(trim($event), $allowed, true)) {
                throw new InvalidRenderConfigurationException('BladePDF webhook events must be one of: pdf.rendered, pdf.failed.');
            }

            if (! in_array(trim($event), $normalized, true)) {
                $normalized[] = trim($event);
            }
        }

        if ($normalized === []) {
            throw new InvalidRenderConfigurationException('BladePDF webhook events cannot be empty.');
        }

        return $normalized;
    }

    protected function formatPdfLength(string|int|float $value, string $unit): string
    {
        return is_string($value) ? $value : $this->formatNumber($value).$unit;
    }

    protected function formatNumber(int|float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }
}
