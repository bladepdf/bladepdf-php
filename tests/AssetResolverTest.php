<?php

declare(strict_types=1);

namespace BladePDF\Tests;

use BladePDF\Assets\AssetResolver;
use BladePDF\Assets\AssetResolverOptions;
use BladePDF\Exceptions\AssetAccessDeniedException;
use BladePDF\Exceptions\InvalidRenderConfigurationException;
use PHPUnit\Framework\TestCase;

final class AssetResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/bladepdf-core-assets-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/css', 0777, true);
        mkdir($this->root.'/fonts', 0777, true);
        mkdir($this->root.'/images', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->root);
    }

    public function test_it_resolves_html_css_fonts_js_and_svg_with_suffixes(): void
    {
        file_put_contents($this->root.'/images/logo.svg', '<svg></svg>');
        file_put_contents($this->root.'/fonts/inter.woff2', 'font-bytes');
        file_put_contents($this->root.'/app.js', 'window.ready = true;');
        file_put_contents(
            $this->root.'/css/app.css',
            '@font-face{src:url("../fonts/inter.woff2?v=1")}body{background:url("../images/logo.svg#mark")}',
        );

        $result = $this->resolver()->resolve(
            '<link href="/css/app.css" rel="stylesheet"><script src="/app.js"></script><img src="/images/logo.svg#icon">',
        );

        self::assertCount(4, $result->assets);
        self::assertMatchesRegularExpression('/href="asset:\/\/\/[a-f0-9]{32}\.css"/', $result->html);
        self::assertMatchesRegularExpression('/src="asset:\/\/\/[a-f0-9]{32}\.js"/', $result->html);
        self::assertStringContainsString('.svg#icon', $result->html);

        $css = array_values(array_filter(
            $result->assets,
            static fn ($asset): bool => $asset->mimeType === 'text/css',
        ))[0];
        self::assertStringContainsString('.woff2?v=1', $css->contents);
        self::assertStringContainsString('.svg#mark', $css->contents);
    }

    public function test_css_import_cycles_are_resolved_once(): void
    {
        file_put_contents($this->root.'/css/a.css', '@import url("b.css"); .a{color:red}');
        file_put_contents($this->root.'/css/b.css', '@import url("a.css"); .b{color:blue}');

        $result = $this->resolver()->resolve('<link rel="stylesheet" href="/css/a.css">');

        self::assertCount(2, $result->assets);
        self::assertStringContainsString('asset:///', $result->assets[0]->contents);
        self::assertStringContainsString('asset:///', $result->assets[1]->contents);
    }

    public function test_external_protocol_relative_and_inline_references_are_preserved(): void
    {
        $html = '<img src="https://cdn.example.com/a.png"><script src="//cdn.example.com/a.js"></script><img src="data:image/png;base64,AA==">';

        $result = $this->resolver()->resolve($html);

        self::assertSame($html, $result->html);
        self::assertSame([], $result->assets);
    }

    public function test_local_host_srcset_inline_styles_and_query_fragments_are_rewritten(): void
    {
        file_put_contents($this->root.'/images/one.png', 'one');
        file_put_contents($this->root.'/images/two.png', 'two');

        $result = $this->resolver()->resolve(
            '<img srcset="/images/one.png?v=1 1x, data:image/png;base64,AA== 1.5x, https://app.test/images/two.png#two 2x" style="background:url(\'/images/one.png#bg\')">',
        );

        self::assertCount(2, $result->assets);
        self::assertStringContainsString('.png?v=1 1x', $result->html);
        self::assertStringContainsString('data:image/png;base64,AA== 1.5x', $result->html);
        self::assertStringContainsString('.png#two 2x', $result->html);
        self::assertStringContainsString('.png#bg', $result->html);
    }

    public function test_traversal_and_file_urls_outside_roots_are_denied(): void
    {
        $outside = dirname($this->root).'/bladepdf-private-'.bin2hex(random_bytes(8)).'.txt';
        file_put_contents($outside, 'private');

        try {
            foreach (['/../'.basename($outside), 'file://'.$outside] as $reference) {
                try {
                    $this->resolver()->resolve('<img src="'.$reference.'">');
                    self::fail('Outside asset was accepted: '.$reference);
                } catch (AssetAccessDeniedException) {
                    self::assertTrue(true);
                }
            }
        } finally {
            unlink($outside);
        }
    }

    public function test_duplicate_sources_are_uploaded_once_and_manual_target_overrides_same_name(): void
    {
        file_put_contents($this->root.'/images/logo.png', 'automatic');
        file_put_contents($this->root.'/replacement.png', 'manual');

        $first = $this->resolver()->resolve(
            '<img src="/images/logo.png"><img src="/images/logo.png#mark">',
        );
        self::assertCount(1, $first->assets);

        $second = $this->resolver()->resolve(
            '<img src="asset:///logo.png">',
            manualAssets: [
                ['path' => $this->root.'/images/logo.png', 'target' => 'logo.png'],
                ['path' => $this->root.'/replacement.png', 'target' => 'logo.png', 'mime' => 'image/png'],
            ],
        );

        self::assertCount(1, $second->assets);
        self::assertSame('asset:///logo.png', $second->assets[0]->fieldName);
        self::assertSame('manual', $second->assets[0]->contents);
    }

    public function test_javascript_and_svg_contents_are_not_traversed(): void
    {
        file_put_contents($this->root.'/app.js', 'fetch("/runtime.json"); import("./lazy.js");');
        file_put_contents($this->root.'/images/sprite.svg', '<svg><image href="nested.png"/></svg>');

        $result = $this->resolver()->resolve(
            '<script src="/app.js"></script><svg><use href="/images/sprite.svg#icon"></use></svg>',
        );

        self::assertCount(2, $result->assets);
        self::assertSame('fetch("/runtime.json"); import("./lazy.js");', $result->assets[0]->contents);
        self::assertSame('<svg><image href="nested.png"/></svg>', $result->assets[1]->contents);
    }

    public function test_windows_paths_are_handled_without_being_mistaken_for_url_schemes(): void
    {
        $result = $this->resolver()->resolve('<img src="C:\\pdf-assets\\logo.png">');

        self::assertSame('<img src="C:\\pdf-assets\\logo.png">', $result->html);
        self::assertSame([], $result->assets);
    }

    public function test_existing_absolute_file_outside_roots_is_denied_but_manual_asset_is_allowed(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'bladepdf-outside-');
        self::assertIsString($outside);
        file_put_contents($outside, 'private');

        try {
            try {
                $this->resolver()->resolve('<img src="'.$outside.'">');
                self::fail('Outside asset was accepted automatically.');
            } catch (AssetAccessDeniedException) {
                self::assertTrue(true);
            }

            $result = $this->resolver()->resolve(
                '<img src="asset:///explicit.txt">',
                manualAssets: [['path' => $outside, 'target' => 'explicit.txt']],
            );
            self::assertCount(1, $result->assets);
            self::assertSame('asset:///explicit.txt', $result->assets[0]->fieldName);
        } finally {
            unlink($outside);
        }
    }

    public function test_symlink_escape_is_denied(): void
    {
        if (! function_exists('symlink')) {
            self::markTestSkipped('Symlinks are unavailable.');
        }

        $outside = tempnam(sys_get_temp_dir(), 'bladepdf-secret-');
        self::assertIsString($outside);
        file_put_contents($outside, 'secret');
        symlink($outside, $this->root.'/images/secret.txt');

        try {
            $this->expectException(AssetAccessDeniedException::class);
            $this->resolver()->resolve('<img src="/images/secret.txt">');
        } finally {
            unlink($outside);
        }
    }

    public function test_explicit_asset_target_validation_matches_gateway_rules(): void
    {
        $this->expectException(InvalidRenderConfigurationException::class);

        $this->resolver()->resolve(
            '',
            manualAssets: [['path' => __FILE__, 'target' => 'header_html']],
            autoResolveAssets: false,
        );
    }

    private function resolver(): AssetResolver
    {
        return new AssetResolver(new AssetResolverOptions(
            documentRoot: $this->root,
            searchRoots: [$this->root],
            localHosts: ['app.test'],
        ));
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.'/'.$item;

            if (is_link($path) || is_file($path)) {
                unlink($path);
            } else {
                $this->deleteDirectory($path);
            }
        }

        rmdir($directory);
    }
}
