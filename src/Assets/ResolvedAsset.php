<?php

declare(strict_types=1);

namespace BladePDF\Assets;

final readonly class ResolvedAsset
{
    public function __construct(
        public string $fieldName,
        public string $filename,
        public string $contents,
        public string $mimeType,
        public ?string $sourcePath = null,
    ) {
    }
}
