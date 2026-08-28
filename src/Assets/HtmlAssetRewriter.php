<?php

declare(strict_types=1);

namespace BladePDF\Assets;

final class HtmlAssetRewriter
{
    public function __construct(
        private readonly AssetResolver $resolver,
        private readonly CssAssetRewriter $cssRewriter,
    ) {
    }

    public function rewrite(string $html, AssetBag $assetBag, ?string $baseDirectory = null): string
    {
        $html = preg_replace_callback(
            '/(<style\b[^>]*>)(.*?)(<\/style>)/is',
            fn (array $matches): string => $matches[1]
                .$this->cssRewriter->rewrite($matches[2], $assetBag, $baseDirectory)
                .$matches[3],
            $html,
        ) ?? $html;

        $html = preg_replace_callback(
            '/\sstyle=("|\')(.*?)\1/is',
            fn (array $matches): string => ' style='.$matches[1]
                .$this->cssRewriter->rewrite($matches[2], $assetBag, $baseDirectory)
                .$matches[1],
            $html,
        ) ?? $html;

        $html = preg_replace_callback(
            '/\s(srcset)=("|\')(.*?)\2/is',
            function (array $matches) use ($assetBag, $baseDirectory): string {
                $rewritten = array_map(
                    function (string $candidate) use ($assetBag, $baseDirectory): string {
                        $parts = preg_split('/\s+/', trim($candidate), 2) ?: [];
                        $reference = $parts[0] ?? '';
                        $descriptor = $parts[1] ?? '';
                        $replacement = $this->resolver->registerReference($reference, $assetBag, $baseDirectory);

                        return $replacement === null
                            ? $candidate
                            : trim($replacement.' '.$descriptor);
                    },
                    $this->splitSrcset($matches[3]),
                );

                return ' '.$matches[1].'='.$matches[2].implode(', ', $rewritten).$matches[2];
            },
            $html,
        ) ?? $html;

        $html = preg_replace_callback(
            '/\s(src|href|poster|data-src|data-href)=("|\')(.*?)\2/is',
            function (array $matches) use ($assetBag, $baseDirectory): string {
                $replacement = $this->resolver->registerReference($matches[3], $assetBag, $baseDirectory);

                return $replacement === null
                    ? $matches[0]
                    : ' '.$matches[1].'='.$matches[2].$replacement.$matches[2];
            },
            $html,
        ) ?? $html;

        return $html;
    }

    /**
     * @return list<string>
     */
    private function splitSrcset(string $srcset): array
    {
        $candidates = [];
        $current = '';
        $isDataUrl = false;
        $seenWhitespace = false;

        foreach (str_split($srcset) as $character) {
            if ($current === '') {
                $isDataUrl = false;
                $seenWhitespace = false;

                if (ctype_space($character)) {
                    continue;
                }
            }

            $current .= $character;

            if (strlen($current) === 5 && strtolower($current) === 'data:') {
                $isDataUrl = true;
            }

            if (ctype_space($character)) {
                $seenWhitespace = true;
            }

            if ($character === ',' && (! $isDataUrl || $seenWhitespace)) {
                $candidate = trim(substr($current, 0, -1));
                if ($candidate !== '') {
                    $candidates[] = $candidate;
                }
                $current = '';
            }
        }

        if (trim($current) !== '') {
            $candidates[] = trim($current);
        }

        return $candidates;
    }
}
