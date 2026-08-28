<?php

declare(strict_types=1);

namespace BladePDF;

use BladePDF\Exceptions\UnableToWritePdfException;

readonly class RenderResult
{
    public function __construct(
        private string $pdf,
        public ?string $storedPdfUrl = null,
        public ?string $requestId = null,
    ) {
    }

    public function pdf(): string
    {
        return $this->pdf;
    }

    public function storedPdfUrl(): ?string
    {
        return $this->storedPdfUrl;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function save(string $path): string
    {
        $bytes = @file_put_contents($path, $this->pdf);

        if ($bytes === false || $bytes !== strlen($this->pdf)) {
            throw new UnableToWritePdfException(sprintf('Unable to write the generated PDF to [%s].', $path));
        }

        return $path;
    }

    public function base64Pdf(): string
    {
        return base64_encode($this->pdf);
    }

    public function base64(): string
    {
        return $this->base64Pdf();
    }
}
