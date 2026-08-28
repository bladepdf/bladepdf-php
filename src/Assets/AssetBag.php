<?php

declare(strict_types=1);

namespace BladePDF\Assets;

use BladePDF\Exceptions\InvalidRenderConfigurationException;

final class AssetBag
{
    /**
     * @var array<string, array{filename:string,contents:?string,mimeType:string,sourcePath:?string}>
     */
    private array $assets = [];

    /**
     * @var array<string, string>
     */
    private array $sourceMap = [];

    public function uriForSource(string $sourcePath): ?string
    {
        $normalized = realpath($sourcePath) ?: $sourcePath;

        return $this->sourceMap[$normalized] ?? null;
    }

    public function reserveSource(
        string $sourcePath,
        string $filename,
        string $mimeType,
        ?string $fieldName = null,
    ): string {
        $normalized = realpath($sourcePath) ?: $sourcePath;

        if (isset($this->sourceMap[$normalized])) {
            return $this->sourceMap[$normalized];
        }

        $fieldName ??= $this->generatedFieldName($normalized, $filename);
        $this->sourceMap[$normalized] = $fieldName;
        $this->assets[$fieldName] = [
            'filename' => $filename,
            'contents' => null,
            'mimeType' => $mimeType,
            'sourcePath' => $normalized,
        ];

        return $fieldName;
    }

    public function completeSource(string $sourcePath, string $contents): void
    {
        $normalized = realpath($sourcePath) ?: $sourcePath;
        $fieldName = $this->sourceMap[$normalized] ?? null;

        if ($fieldName === null || ! isset($this->assets[$fieldName])) {
            throw new InvalidRenderConfigurationException(sprintf('BladePDF asset [%s] was completed before it was reserved.', $sourcePath));
        }

        $this->assets[$fieldName]['contents'] = $contents;
    }

    public function putManual(
        string $contents,
        string $filename,
        string $mimeType,
        string $sourcePath,
        ?string $fieldName = null,
    ): string {
        $normalized = realpath($sourcePath) ?: $sourcePath;

        if ($fieldName === null && isset($this->sourceMap[$normalized])) {
            $existingField = $this->sourceMap[$normalized];
            $existing = $this->assets[$existingField];
            $this->assets[$existingField] = [
                'filename' => $existing['filename'],
                'contents' => $contents,
                'mimeType' => $mimeType,
                'sourcePath' => $existing['sourcePath'],
            ];

            return $existingField;
        }

        $fieldName ??= $this->generatedFieldName($normalized, $filename);
        $this->assets[$fieldName] = [
            'filename' => $filename,
            'contents' => $contents,
            'mimeType' => $mimeType,
            'sourcePath' => $normalized,
        ];

        if (! isset($this->sourceMap[$normalized])) {
            $this->sourceMap[$normalized] = $fieldName;
        }

        return $fieldName;
    }

    /**
     * @return list<ResolvedAsset>
     */
    public function all(): array
    {
        $resolved = [];

        foreach ($this->assets as $fieldName => $asset) {
            if ($asset['contents'] === null) {
                throw new InvalidRenderConfigurationException(sprintf('BladePDF asset [%s] was reserved but not resolved.', $fieldName));
            }

            $resolved[] = new ResolvedAsset(
                fieldName: $fieldName,
                filename: $asset['filename'],
                contents: $asset['contents'],
                mimeType: $asset['mimeType'],
                sourcePath: $asset['sourcePath'],
            );
        }

        return $resolved;
    }

    private function generatedFieldName(string $sourcePath, string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $suffix = $extension !== '' ? '.'.$extension : '';

        return 'asset:///'.substr(hash('sha256', $sourcePath), 0, 32).$suffix;
    }
}
