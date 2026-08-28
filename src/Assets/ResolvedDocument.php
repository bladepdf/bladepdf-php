<?php

declare(strict_types=1);

namespace BladePDF\Assets;

final readonly class ResolvedDocument
{
    /**
     * @param  list<ResolvedAsset>  $assets
     */
    public function __construct(
        public string $html,
        public ?string $headerHtml,
        public ?string $footerHtml,
        public array $assets,
    ) {
    }
}
