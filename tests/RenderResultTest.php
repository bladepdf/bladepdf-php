<?php

declare(strict_types=1);

namespace BladePDF\Tests;

use BladePDF\Exceptions\UnableToWritePdfException;
use BladePDF\RenderResult;
use PHPUnit\Framework\TestCase;

final class RenderResultTest extends TestCase
{
    public function test_it_exposes_and_saves_pdf_data(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bladepdf-result-');
        self::assertIsString($path);

        try {
            $result = new RenderResult('pdf-bytes', 'https://example.test/pdf', 'request-1');
            self::assertSame('pdf-bytes', $result->pdf());
            self::assertSame('request-1', $result->requestId());
            self::assertSame(base64_encode('pdf-bytes'), $result->base64());
            self::assertSame($path, $result->save($path));
            self::assertSame('pdf-bytes', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function test_save_failure_is_reported(): void
    {
        $this->expectException(UnableToWritePdfException::class);

        (new RenderResult('pdf'))->save(sys_get_temp_dir().'/missing-'.bin2hex(random_bytes(8)).'/file.pdf');
    }
}
