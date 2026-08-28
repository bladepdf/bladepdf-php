<?php

declare(strict_types=1);

namespace BladePDF\Assets;

final readonly class LocatedAsset
{
    public function __construct(
        public string $path,
        public string $suffix = '',
    ) {
    }
}
