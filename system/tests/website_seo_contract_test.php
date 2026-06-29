<?php
declare(strict_types=1);

namespace Metis\Core {
    final class Application {}
}

namespace {
    if ( PHP_SAPI !== 'cli' ) {
        fwrite( STDERR, "This test must be run from the command line.\n" );
        exit( 1 );
    }

    function metis_key_clean( string $value ): string {
        $value = strtolower( trim( $value ) );
        return preg_replace( '/[^a-z0-9_]+/', '_', $value ) ?? '';
    }

    function metis_escape_attr( string $value ): string {
        return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
    }

    function metis_json_encode( mixed $value ): string {
        return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    function metis_runtime_is_safe_url_value( string $value ): bool {
        return ! preg_match( '#^\s*javascript:#i', $value );
    }

    function metis_home_url( string $path = '/' ): string {
        return 'https://example.test' . ( str_starts_with( $path, '/' ) ? $path : '/' . $path );
    }

    function metis_portal_name(): string {
        return 'Example Site';
    }

    $root = dirname( __DIR__ );
    require_once __DIR__ . '/_support/module_path_resolver.php';
    $resolve_relative = static fn ( string $relative ): string => metis_test_resolve_relative( $root, $relative );
    require_once $resolve_relative( 'modules/website/Services/SeoService.php' );

    $failures = [];
    $assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    $payload = \Metis\Modules\Website\Services\SeoService::buildRenderPayload(
        [
            'seo_meta' => [
                'advanced' => [
                    'og_title' => 'Launch Story',
                ],
            ],
        ],
        [
            'path' => '/news/welcome',
            'content_type' => 'post',
        ],
        [
            'title' => 'Welcome to Launch Week',
            'excerpt' => 'A short launch announcement.',
            'publish_date' => '2026-06-28 14:00:00',
            'updated_at' => '2026-06-29 09:15:00',
            'author_name' => 'Jane Doe',
            'author_url' => '/people/jane-doe/',
            'featured_image_url' => '/media/launch-cover',
        ],
        [],
        [],
        'Welcome to Launch Week',
        'A short launch announcement.',
        []
    );
    $placeholderPayload = \Metis\Modules\Website\Services\SeoService::buildRenderPayload(
        [
            'seo_meta' => [
                'advanced' => [
                    'meta_description' => 'testing',
                ],
            ],
        ],
        [
            'path' => '/',
            'content_type' => 'page',
            'is_homepage' => true,
        ],
        [
            'title' => 'Mobilize Waco',
        ],
        [],
        [],
        'Mobilize Waco',
        'testing',
        []
    );

    $head = \Metis\Modules\Website\Services\SeoService::buildHeadTags( $payload, [ 'content_type' => 'post' ] );
    $headHtml = implode( "\n", $head );
    $seoSource = (string) file_get_contents( $resolve_relative( 'modules/website/Services/SeoService.php' ) );
    $rendererSource = (string) file_get_contents( $resolve_relative( 'modules/website/Services/WebsiteRenderer.php' ) );
    $bootstrapSource = (string) file_get_contents( $resolve_relative( 'modules/website/bootstrap.php' ) );
    $routesSource = (string) file_get_contents( $resolve_relative( 'modules/website/routes/routes.php' ) );

    $assert( (string) ( $payload['canonical'] ?? '' ) === 'https://example.test/news/welcome', 'SEO payload should normalize canonical URLs from public paths.' );
    $assert( (string) ( $payload['twitter_card'] ?? '' ) === 'summary_large_image', 'SEO payload should emit large Twitter cards when an image is available.' );
    $assert( str_contains( $headHtml, 'meta name="robots" content="index, follow"' ), 'SEO head tags should default public pages to index, follow.' );
    $assert( str_contains( $headHtml, 'meta property="og:type" content="article"' ), 'SEO head tags should label posts as Open Graph articles.' );
    $assert( str_contains( $headHtml, 'meta name="twitter:image" content="https://example.test/media/launch-cover"' ), 'SEO head tags should emit a normalized Twitter image URL.' );
    $assert( str_contains( $headHtml, '"@type":"Article"' ) && str_contains( $headHtml, '"datePublished":"2026-06-28T14:00:00+00:00"' ), 'SEO structured data should emit Article schema with publish date.' );
    $assert( (string) ( $placeholderPayload['description'] ?? '' ) !== 'testing', 'SEO payload should replace placeholder descriptions with a stronger default summary.' );
    $assert( str_contains( (string) ( $placeholderPayload['description'] ?? '' ), 'Mobilize Waco' ), 'Homepage SEO fallback description should retain the site title when content is thin.' );

    $assert( str_contains( $rendererSource, 'SeoService::buildRenderPayload' ), 'WebsiteRenderer must delegate SEO payload generation to the shared SEO service.' );
    $assert( str_contains( $rendererSource, 'SeoService::buildHeadTags' ), 'WebsiteRenderer must delegate SEO head tag generation to the shared SEO service.' );
    $assert( ! str_contains( $rendererSource, '<h1 class="metis-structured-section__title">' ), 'Structured section headers should not emit additional H1 tags.' );
    $assert( str_contains( $rendererSource, "metis-structured-hero-block__copy" ) && str_contains( $rendererSource, "<h2>' . metis_escape_html( \$title ) . '</h2>" ), 'Structured hero blocks should render their titles as H2 tags.' );
    $assert( str_contains( $rendererSource, 'normalizePublicHtmlSemantics' ) && str_contains( $rendererSource, 'shouldRemoveEmptyInlineEmphasis' ), 'WebsiteRenderer should normalize rich text semantics and remove empty emphasis tags.' );
    $assert( str_contains( $bootstrapSource, "'website.sitemap'" ) && str_contains( $bootstrapSource, "'website.robots'" ), 'Website bootstrap must register sitemap and robots public routes.' );
    $assert( str_contains( $routesSource, 'function metis_website_handle_sitemap_route' ) && str_contains( $routesSource, 'function metis_website_handle_robots_route' ), 'Website routes must expose sitemap and robots handlers.' );
    $assert( str_contains( $seoSource, 'renderSitemapXml' ) && str_contains( $seoSource, 'renderRobotsTxt' ), 'Shared SEO service must own sitemap and robots rendering.' );
    $assert( str_contains( $seoSource, 'defaultSiteDescription' ) && str_contains( $seoSource, 'isLowSignalSummary' ), 'Shared SEO service should harden weak meta descriptions with default fallbacks.' );

    if ( $failures !== [] ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    fwrite( STDOUT, "Website SEO contract checks passed.\n" );
}
