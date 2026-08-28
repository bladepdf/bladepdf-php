<?php

declare(strict_types=1);

namespace BladePDF\Assets;

final class CssAssetRewriter
{
    public function __construct(private readonly AssetResolver $resolver)
    {
    }

    public function rewrite(string $css, AssetBag $assetBag, ?string $baseDirectory = null): string
    {
        $css = preg_replace_callback(
            '/url\(\s*(["\']?)(.*?)\1\s*\)/i',
            function (array $matches) use ($assetBag, $baseDirectory): string {
                $replacement = $this->resolver->registerReference(
                    reference: trim($matches[2]),
                    assetBag: $assetBag,
                    baseDirectory: $baseDirectory,
                );

                return $replacement === null ? $matches[0] : 'url('.$replacement.')';
            },
            $css,
        ) ?? $css;

        $css = preg_replace_callback(
            '/@import\s+(?:url\()?\s*(["\']?)(.*?)\1\s*\)?\s*;/i',
            function (array $matches) use ($assetBag, $baseDirectory): string {
                $replacement = $this->resolver->registerReference(
                    reference: trim($matches[2]),
                    assetBag: $assetBag,
                    baseDirectory: $baseDirectory,
                );

                return $replacement === null ? $matches[0] : '@import url('.$replacement.');';
            },
            $css,
        ) ?? $css;

        return $css;
    }
}
