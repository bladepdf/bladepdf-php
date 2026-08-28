<?php

declare(strict_types=1);

namespace BladePDF\Sources;

use BladePDF\Contracts\HtmlSource;

final readonly class StringHtmlSource implements HtmlSource
{
    public function __construct(
        private string $html,
        private ?string $baseDirectory = null,
    ) {
    }

    public function render(): string
    {
        return $this->html;
    }

    public function baseDirectory(): ?string
    {
        return $this->baseDirectory;
    }
}
