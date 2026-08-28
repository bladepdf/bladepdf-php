<?php

declare(strict_types=1);

namespace BladePDF\Assets;

use BladePDF\Exceptions\AssetNotFoundException;
use BladePDF\Exceptions\InvalidRenderConfigurationException;

final class AssetResolver
{
    private readonly FilesystemAssetLocator $locator;

    private readonly MimeTypeGuesser $mimeTypeGuesser;

    private readonly CssAssetRewriter $cssRewriter;

    private readonly HtmlAssetRewriter $htmlRewriter;

    public function __construct(private readonly AssetResolverOptions $options = new AssetResolverOptions())
    {
        $this->locator = new FilesystemAssetLocator($options);
        $this->mimeTypeGuesser = new MimeTypeGuesser();
        $this->cssRewriter = new CssAssetRewriter($this);
        $this->htmlRewriter = new HtmlAssetRewriter($this, $this->cssRewriter);
    }

    /**
     * @param  list<array{path:string,target?:string,mime?:string}>  $manualAssets
     */
    public function resolve(
        string $html,
        ?string $headerHtml = null,
        ?string $footerHtml = null,
        array $manualAssets = [],
        ?bool $autoResolveAssets = null,
        ?string $htmlBaseDirectory = null,
        ?string $headerBaseDirectory = null,
        ?string $footerBaseDirectory = null,
    ): ResolvedDocument {
        $assetBag = new AssetBag();
        $autoResolveAssets ??= $this->options->autoResolve;

        if ($autoResolveAssets) {
            $html = $this->htmlRewriter->rewrite($html, $assetBag, $htmlBaseDirectory ?? $this->options->normalizedDocumentRoot());
            $headerHtml = $headerHtml !== null
                ? $this->htmlRewriter->rewrite($headerHtml, $assetBag, $headerBaseDirectory ?? $this->options->normalizedDocumentRoot())
                : null;
            $footerHtml = $footerHtml !== null
                ? $this->htmlRewriter->rewrite($footerHtml, $assetBag, $footerBaseDirectory ?? $this->options->normalizedDocumentRoot())
                : null;
        }

        foreach ($manualAssets as $manualAsset) {
            $this->registerManualAsset($manualAsset, $assetBag, $autoResolveAssets);
        }

        return new ResolvedDocument(
            html: $html,
            headerHtml: $headerHtml,
            footerHtml: $footerHtml,
            assets: $assetBag->all(),
        );
    }

    public function registerReference(
        string $reference,
        AssetBag $assetBag,
        ?string $baseDirectory = null,
    ): ?string {
        $located = $this->locator->locate($reference, $baseDirectory);

        if ($located === null) {
            return null;
        }

        $existingUri = $assetBag->uriForSource($located->path);

        if ($existingUri !== null) {
            return $existingUri.$located->suffix;
        }

        $filename = basename($located->path);
        $mimeType = $this->mimeTypeGuesser->guess($located->path);
        $fieldName = $assetBag->reserveSource($located->path, $filename, $mimeType);
        $contents = $this->readFile($located->path);

        if ($this->isCssFile($located->path)) {
            $contents = $this->cssRewriter->rewrite($contents, $assetBag, dirname($located->path));
        }

        $assetBag->completeSource($located->path, $contents);

        return $fieldName.$located->suffix;
    }

    /**
     * @param  array{path:string,target?:string,mime?:string}  $manualAsset
     */
    private function registerManualAsset(array $manualAsset, AssetBag $assetBag, bool $autoResolve): void
    {
        $path = $manualAsset['path'];
        $resolvedPath = realpath($path);

        if ($resolvedPath === false || ! is_file($resolvedPath)) {
            throw new AssetNotFoundException(sprintf('Manual BladePDF asset [%s] was not found.', $path));
        }

        $target = $manualAsset['target'] ?? null;
        $fieldName = $target !== null ? 'asset:///'.ltrim($target, '/') : null;
        $filename = basename($resolvedPath);
        $mimeType = $manualAsset['mime'] ?? $this->mimeTypeGuesser->guess($resolvedPath);

        if ($fieldName !== null) {
            $this->validateTarget(substr($fieldName, strlen('asset:///')));
        }

        if ($autoResolve && $this->isCssFile($resolvedPath) && $assetBag->uriForSource($resolvedPath) === null) {
            $reserved = $assetBag->reserveSource($resolvedPath, $filename, $mimeType, $fieldName);
            $contents = $this->cssRewriter->rewrite($this->readFile($resolvedPath), $assetBag, dirname($resolvedPath));
            $assetBag->completeSource($resolvedPath, $contents);

            if ($fieldName === null || $reserved === $fieldName) {
                return;
            }
        }

        $contents = $this->readFile($resolvedPath);

        if ($autoResolve && $this->isCssFile($resolvedPath)) {
            $contents = $this->cssRewriter->rewrite($contents, $assetBag, dirname($resolvedPath));
        }

        $assetBag->putManual($contents, $filename, $mimeType, $resolvedPath, $fieldName);
    }

    private function readFile(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new AssetNotFoundException(sprintf('BladePDF asset [%s] could not be read.', $path));
        }

        return $contents;
    }

    private function isCssFile(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'css';
    }

    private function validateTarget(string $target): void
    {
        if (
            preg_match('/^[A-Za-z0-9._-]+$/', $target) !== 1
            || in_array($target, ['html', 'header_html', 'footer_html', 'context'], true)
        ) {
            throw new InvalidRenderConfigurationException(
                'BladePDF asset targets may only contain letters, numbers, dots, underscores, and hyphens and may not use a reserved file field name.',
            );
        }
    }
}
