<?php

declare(strict_types=1);

namespace BladePDF\Assets;

use InvalidArgumentException;

final readonly class AssetResolverOptions
{
    /**
     * @var list<string>
     */
    public array $searchRoots;

    /**
     * @var list<string>
     */
    public array $localHosts;

    /**
     * @param  list<string>  $searchRoots
     * @param  list<string>  $localHosts
     */
    public function __construct(
        public ?string $documentRoot = null,
        array $searchRoots = [],
        array $localHosts = ['localhost', '127.0.0.1', '::1'],
        public bool $autoResolve = true,
    ) {
        $this->searchRoots = $this->normalizeRoots($documentRoot, $searchRoots);
        $this->localHosts = array_values(array_unique(array_map(
            static fn (string $host): string => strtolower(trim($host, " \t\n\r\0\x0B[]")),
            array_filter($localHosts, static fn (string $host): bool => trim($host) !== ''),
        )));
    }

    /**
     * @param  list<string>  $searchRoots
     * @return list<string>
     */
    private function normalizeRoots(?string $documentRoot, array $searchRoots): array
    {
        $roots = $documentRoot !== null ? [$documentRoot, ...$searchRoots] : $searchRoots;
        $normalized = [];

        foreach ($roots as $root) {
            if (trim($root) === '') {
                throw new InvalidArgumentException('BladePDF asset roots must be non-empty strings.');
            }

            $resolved = realpath($root);

            if ($resolved === false || ! is_dir($resolved)) {
                throw new InvalidArgumentException(sprintf('BladePDF asset root [%s] is not an existing directory.', $root));
            }

            $resolved = rtrim($resolved, DIRECTORY_SEPARATOR);

            if (! in_array($resolved, $normalized, true)) {
                $normalized[] = $resolved;
            }
        }

        return $normalized;
    }

    public function normalizedDocumentRoot(): ?string
    {
        if ($this->documentRoot === null) {
            return null;
        }

        $resolved = realpath($this->documentRoot);

        return $resolved === false ? null : rtrim($resolved, DIRECTORY_SEPARATOR);
    }
}
