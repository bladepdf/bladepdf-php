<?php

declare(strict_types=1);

namespace BladePDF\Assets;

final class MimeTypeGuesser
{
    /**
     * @var array<string, string>
     */
    private const WEB_ASSET_MIME_TYPES = [
        'avif' => 'image/avif',
        'bmp' => 'image/bmp',
        'css' => 'text/css',
        'eot' => 'application/vnd.ms-fontobject',
        'gif' => 'image/gif',
        'htm' => 'text/html',
        'html' => 'text/html',
        'ico' => 'image/x-icon',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'text/javascript',
        'json' => 'application/json',
        'map' => 'application/json',
        'mjs' => 'text/javascript',
        'otf' => 'font/otf',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'ttf' => 'font/ttf',
        'wasm' => 'application/wasm',
        'webmanifest' => 'application/manifest+json',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    public function guess(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (isset(self::WEB_ASSET_MIME_TYPES[$extension])) {
            return self::WEB_ASSET_MIME_TYPES[$extension];
        }

        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($path);

        return is_string($mimeType) && $mimeType !== ''
            ? $mimeType
            : 'application/octet-stream';
    }
}
