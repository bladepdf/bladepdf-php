<?php

declare(strict_types=1);

namespace BladePDF\Assets;

use BladePDF\Exceptions\AssetAccessDeniedException;

final class FilesystemAssetLocator
{
    public function __construct(private readonly AssetResolverOptions $options)
    {
    }

    public function locate(string $reference, ?string $baseDirectory = null): ?LocatedAsset
    {
        $reference = trim(html_entity_decode($reference, ENT_QUOTES | ENT_HTML5));

        if ($reference === '' || $this->shouldSkip($reference)) {
            return null;
        }

        $isWindowsPath = preg_match('/^[A-Za-z]:[\\\\\/]/', $reference) === 1;
        $isProtocolRelative = str_starts_with($reference, '//');
        $parsed = $isWindowsPath ? [] : (parse_url($reference) ?: []);
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parsed['host'] ?? ''), '[]'));

        if ($isProtocolRelative || in_array($scheme, ['http', 'https'], true)) {
            if (! $this->isLocalHost($host)) {
                return null;
            }

            $path = (string) ($parsed['path'] ?? '');

            return $this->locateUrlPath($path, $this->suffix($parsed));
        }

        if ($scheme !== '' && $scheme !== 'file' && ! $isWindowsPath) {
            return null;
        }

        $path = $scheme === 'file'
            ? (string) ($parsed['path'] ?? '')
            : ($isWindowsPath ? $reference : (string) ($parsed['path'] ?? $reference));
        $suffix = $isWindowsPath ? '' : $this->suffix($parsed);

        if ($path === '' || $path === '/') {
            return null;
        }

        $path = rawurldecode($path);

        if ($this->isAbsoluteFilesystemPath($path)) {
            $absolute = $this->existingFile($path);

            if ($absolute !== null) {
                $this->assertAllowed($absolute, $reference);

                return new LocatedAsset($absolute, $suffix);
            }

            if (str_starts_with($path, '/')) {
                $fromRoot = $this->fromDocumentRoot($path);

                return $fromRoot !== null ? new LocatedAsset($fromRoot, $suffix) : null;
            }

            return null;
        }

        $candidates = [];

        if ($baseDirectory !== null && trim($baseDirectory) !== '') {
            $candidates[] = rtrim($baseDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
        }

        foreach ($this->options->searchRoots as $root) {
            $candidates[] = $root.DIRECTORY_SEPARATOR.$path;
        }

        foreach ($candidates as $candidate) {
            $resolved = $this->existingFile($candidate);

            if ($resolved === null) {
                continue;
            }

            $this->assertAllowed($resolved, $reference);

            return new LocatedAsset($resolved, $suffix);
        }

        return null;
    }

    private function locateUrlPath(string $path, string $suffix): ?LocatedAsset
    {
        if ($path === '' || $path === '/') {
            return null;
        }

        $resolved = $this->fromDocumentRoot(rawurldecode($path));

        return $resolved !== null ? new LocatedAsset($resolved, $suffix) : null;
    }

    private function fromDocumentRoot(string $path): ?string
    {
        $documentRoot = $this->options->normalizedDocumentRoot();

        if ($documentRoot === null) {
            return null;
        }

        $resolved = $this->existingFile($documentRoot.DIRECTORY_SEPARATOR.ltrim($path, '/\\'));

        if ($resolved === null) {
            return null;
        }

        $this->assertAllowed($resolved, $path);

        return $resolved;
    }

    private function existingFile(string $path): ?string
    {
        $resolved = realpath($path);

        return $resolved !== false && is_file($resolved) ? $resolved : null;
    }

    private function assertAllowed(string $path, string $reference): void
    {
        foreach ($this->options->searchRoots as $root) {
            if ($this->isWithinRoot($path, $root)) {
                return;
            }
        }

        throw new AssetAccessDeniedException(sprintf(
            'Automatic BladePDF asset [%s] resolves outside the configured asset roots. Attach it explicitly with withAsset() if this access is intentional.',
            $reference,
        ));
    }

    private function isWithinRoot(string $path, string $root): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR);
    }

    private function isAbsoluteFilesystemPath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function isLocalHost(string $host): bool
    {
        return $host !== '' && in_array($host, $this->options->localHosts, true);
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function suffix(array $parsed): string
    {
        $suffix = '';

        if (isset($parsed['query']) && is_string($parsed['query'])) {
            $suffix .= '?'.$parsed['query'];
        }

        if (isset($parsed['fragment']) && is_string($parsed['fragment'])) {
            $suffix .= '#'.$parsed['fragment'];
        }

        return $suffix;
    }

    private function shouldSkip(string $reference): bool
    {
        $lower = strtolower($reference);

        foreach (['#', 'data:', 'blob:', 'javascript:', 'mailto:', 'tel:', 'asset:///'] as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
