# BladePDF PHP SDK

Framework-agnostic PHP client for the [BladePDF](https://bladepdf.com) rendering API. It provides the fluent render builder, Guzzle transport, secure local asset pipeline, typed results, structured exceptions, and webhook signature verification used by all BladePDF PHP integrations.

## Requirements

- PHP 8.2 or newer
- A BladePDF API key

## Installation

```bash
composer require bladepdf/php
```

The SDK does not load `.env` or framework configuration. Pass credentials and filesystem permissions explicitly.

## Quickstart

```php
use BladePDF\BladePdf;

$bladePdf = BladePdf::create($_ENV['BLADEPDF_API_KEY']);

$result = $bladePdf
    ->fromHtml('<h1>Invoice INV-42</h1>')
    ->format('A4')
    ->showBackground()
    ->render();

$result->save(__DIR__.'/invoice.pdf');
```

Template engines such as Twig and Latte are supported by rendering them to an HTML string first and passing that string to `fromHtml()`.

## Local assets

Automatic filesystem access is disabled unless allowed roots are configured:

```php
use BladePDF\Assets\AssetResolverOptions;
use BladePDF\BladePdf;

$bladePdf = BladePdf::create(
    apiKey: $_ENV['BLADEPDF_API_KEY'],
    assetOptions: new AssetResolverOptions(
        documentRoot: __DIR__.'/public',
        searchRoots: [__DIR__.'/public', __DIR__.'/storage/pdf-assets'],
        localHosts: ['localhost', 'app.example.test'],
    ),
);

$result = $bladePdf
    ->fromHtml('<link rel="stylesheet" href="/css/pdf.css"><img src="/images/logo.svg#mark">')
    ->render();
```

Resolved files must remain inside an allowed real path; traversal and symlink escapes are rejected. Use `withAsset($path, $target)` when a caller intentionally grants one file outside the configured roots.

HTML attributes, `srcset`, inline styles, stylesheets, nested CSS `url()` references, and `@import` are resolved recursively. JavaScript referenced by `<script src>` and external SVG files are uploaded as opaque files. The SDK does not parse JavaScript imports, `fetch()`, runtime URLs, SVG contents, or dependencies inside SVG.

## Async rendering

```php
$submission = $bladePdf
    ->fromTemplate('invoice.standard', ['invoice' => ['number' => 'INV-42']])
    ->reference('INV-42')
    ->storePdf()
    ->webhook('https://example.test/webhooks/bladepdf', 'whsec_...')
    ->async();

echo $submission->requestId;
```

## Webhook verification

```php
use BladePDF\Webhooks\SignatureVerifier;

$valid = SignatureVerifier::isValid(
    rawBody: file_get_contents('php://input'),
    timestamp: $_SERVER['HTTP_BLADEPDF_TIMESTAMP'] ?? null,
    signature: $_SERVER['HTTP_BLADEPDF_SIGNATURE'] ?? null,
    secret: $_ENV['BLADEPDF_WEBHOOK_SECRET'],
);
```

## Advanced dependency injection

`BladePDF\BladePdf` can be constructed with any `BladePDF\Contracts\RenderClient` and `BladePDF\Assets\AssetResolver`. `BladePdf::create()` also accepts `ClientOptions`, `AssetResolverOptions`, and a PSR-compatible `GuzzleHttp\ClientInterface` for transport customization and tests.

## Errors

Catch `BladePDF\Exceptions\BladePdfException` for SDK errors. `RenderFailedException` exposes the HTTP status, request ID, and response body through typed accessors. Failed filesystem writes throw `UnableToWritePdfException`.

## License

BladePDF PHP is open-source software licensed under the [MIT License](LICENSE).
