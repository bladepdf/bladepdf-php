<?php

declare(strict_types=1);

namespace BladePDF\Client;

use BladePDF\Assets\ResolvedAsset;
use BladePDF\Contracts\RenderClient;
use BladePDF\Exceptions\MissingApiKeyException;
use BladePDF\Exceptions\RenderFailedException;
use BladePDF\RenderRequest;
use BladePDF\RenderResult;
use BladePDF\RenderSubmission;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class BladePdfClient implements RenderClient
{
    /**
     * @var array<string, array{filename:string,contentType:string,json?:bool}>
     */
    private const FILE_FIELDS = [
        'html' => ['filename' => 'html.html', 'contentType' => 'text/html; charset=UTF-8'],
        'header_html' => ['filename' => 'header.html', 'contentType' => 'text/html; charset=UTF-8'],
        'footer_html' => ['filename' => 'footer.html', 'contentType' => 'text/html; charset=UTF-8'],
        'context' => ['filename' => 'context.json', 'contentType' => 'application/json; charset=UTF-8', 'json' => true],
    ];

    public function __construct(
        string $apiKey,
        private readonly ClientOptions $options = new ClientOptions(),
        ?ClientInterface $httpClient = null,
    ) {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new MissingApiKeyException('Missing BladePDF API key.');
        }

        $this->apiKey = $apiKey;
        $this->httpClient = $httpClient ?? new Client();
    }

    private readonly string $apiKey;

    private readonly ClientInterface $httpClient;

    public function render(RenderRequest $request): RenderResult
    {
        $response = $this->send($request, false);
        $requestId = $this->header($response, 'x-request-id');

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw RenderFailedException::fromResponse(
                $response->getStatusCode(),
                (string) $response->getBody(),
                $requestId,
            );
        }

        return new RenderResult(
            pdf: (string) $response->getBody(),
            storedPdfUrl: $this->storedPdfUrl($response),
            requestId: $requestId,
        );
    }

    public function renderAsync(RenderRequest $request): RenderSubmission
    {
        $response = $this->send($request, true);
        $requestIdHeader = $this->header($response, 'x-request-id');

        if ($response->getStatusCode() !== 202) {
            throw RenderFailedException::fromResponse(
                $response->getStatusCode(),
                (string) $response->getBody(),
                $requestIdHeader,
            );
        }

        try {
            $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RenderFailedException(
                'BladePDF async render response is not valid JSON.',
                202,
                $requestIdHeader,
                (string) $response->getBody(),
                $exception,
            );
        }

        $requestId = is_array($payload) ? ($payload['request_id'] ?? null) : null;

        if (! is_string($requestId) || trim($requestId) === '') {
            throw new RenderFailedException(
                'BladePDF async render response is missing a valid request_id.',
                202,
                $requestIdHeader,
                (string) $response->getBody(),
            );
        }

        $reference = $payload['reference'] ?? null;

        return new RenderSubmission($requestId, is_string($reference) ? $reference : null);
    }

    private function send(RenderRequest $request, bool $async): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->httpClient->request('POST', $this->endpoint('/render'), [
                    'headers' => array_filter([
                        'Authorization' => 'Bearer '.$this->apiKey,
                        'Accept' => $async ? 'application/json' : 'application/pdf',
                        'Prefer' => $async ? 'respond-async' : null,
                        'User-Agent' => $this->options->userAgent,
                    ]),
                    'timeout' => $this->options->timeout,
                    'connect_timeout' => $this->options->connectTimeout,
                    'verify' => $this->options->verifySsl,
                    'http_errors' => false,
                    'multipart' => $this->multipart($request),
                ]);
            } catch (ConnectException $exception) {
                if ($attempt >= $this->options->retryTimes) {
                    throw RenderFailedException::fromTransport($exception);
                }

                $this->waitBeforeRetry(null, $attempt++);
                continue;
            } catch (GuzzleException $exception) {
                throw RenderFailedException::fromTransport($exception);
            }

            if (! $this->shouldRetry($response) || $attempt >= $this->options->retryTimes) {
                return $response;
            }

            $this->waitBeforeRetry($response, $attempt++);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function multipart(RenderRequest $request): array
    {
        $multipart = [];

        foreach ($request->fields() as $name => $value) {
            if ($value === null) {
                continue;
            }

            if (isset(self::FILE_FIELDS[$name])) {
                $definition = self::FILE_FIELDS[$name];
                $contents = ($definition['json'] ?? false)
                    ? $this->encodeJson($value, $name === 'context')
                    : (string) $value;
                $multipart[] = [
                    'name' => $name,
                    'contents' => $contents,
                    'filename' => $definition['filename'],
                    'headers' => ['Content-Type' => $definition['contentType']],
                ];
                continue;
            }

            $multipart[] = ['name' => $name, 'contents' => $this->encodeField($value)];
        }

        foreach ($request->assets as $asset) {
            $multipart[] = $this->assetPart($asset);
        }

        return $multipart;
    }

    /**
     * @return array<string, mixed>
     */
    private function assetPart(ResolvedAsset $asset): array
    {
        return [
            'name' => $asset->fieldName,
            'contents' => $asset->contents,
            'filename' => $asset->filename,
            'headers' => ['Content-Type' => $asset->mimeType],
        ];
    }

    private function encodeField(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value) || $value instanceof \JsonSerializable || $value instanceof \stdClass) {
            return $this->encodeJson($value);
        }

        return (string) $value;
    }

    private function encodeJson(mixed $value, bool $emptyArrayAsObject = false): string
    {
        if ($emptyArrayAsObject && $value === []) {
            return '{}';
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function shouldRetry(ResponseInterface $response): bool
    {
        return in_array($response->getStatusCode(), [429, 502, 503, 504], true);
    }

    private function waitBeforeRetry(?ResponseInterface $response, int $attempt): void
    {
        $milliseconds = $response !== null ? $this->retryAfterMilliseconds($response) : null;
        $milliseconds ??= $this->options->retrySleepMilliseconds * (2 ** $attempt);

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function retryAfterMilliseconds(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value * 1000;
        }

        try {
            $retryAt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            $seconds = $retryAt->getTimestamp() - time();

            return max(0, $seconds * 1000);
        } catch (Throwable) {
            return null;
        }
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->options->baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function storedPdfUrl(ResponseInterface $response): ?string
    {
        foreach ($response->getHeader('Link') as $header) {
            foreach (preg_split('/,(?=\s*<)/', $header) ?: [] as $part) {
                if (
                    preg_match('/<([^>]+)>/', $part, $urlMatch) === 1
                    && preg_match('/;\s*rel="?stored-pdf"?/i', $part) === 1
                ) {
                    return $urlMatch[1];
                }
            }
        }

        return null;
    }

    private function header(ResponseInterface $response, string $name): ?string
    {
        $value = trim($response->getHeaderLine($name));

        return $value !== '' ? $value : null;
    }
}
