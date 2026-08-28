<?php

declare(strict_types=1);

namespace BladePDF\Contracts;

interface HtmlSource
{
    public function render(): string;

    public function baseDirectory(): ?string;
}
