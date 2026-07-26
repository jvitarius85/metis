<?php
declare(strict_types=1);

namespace Metis\Core\Editor {
    final class BlockRegistry {
        private static array $definitions = [];
        public static function boot(): void {}
        public static function register( string $type, array $definition ): void { self::$definitions[ $type ] = $definition; }
        public static function get( string $type ): ?array { return self::$definitions[ $type ] ?? null; }
        public static function all(): array { return self::$definitions; }
        public static function exists( string $type ): bool { return isset( self::$definitions[ $type ] ); }
        public static function validateBlock( array $block ): array { return [ 'valid' => true, 'errors' => [] ]; }
    }
}

namespace Metis\Modules\Website\Services {
    final class EditorContextPolicy {
        public static function normalizeRenderMode( string $mode, string $context ): string { return $mode !== '' ? $mode : 'public'; }
        public static function sanitizeStyleForRenderMode( array $style, string $mode ): array { return $style; }
    }
    final class MenuService {}
    final class PostService {}
}

namespace Metis\Modules\People { final class PersonProfileService {} final class ReadService {} }

namespace {
    function metis_key_clean( string $value ): string { return preg_replace( '/[^a-z0-9_]+/', '_', strtolower( trim( $value ) ) ) ?? ''; }
    function metis_escape_attr( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
    function metis_escape_html( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
    function metis_escape_url( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
    function metis_runtime_kses_post( string $value ): string { return $value; }
    function metis_text_clean( string $value ): string { return trim( preg_replace( '/\s+/', ' ', $value ) ?? $value ); }

    $root = dirname( __DIR__ );
    require_once __DIR__ . '/_support/module_path_resolver.php';
    $resolve = static fn ( string $relative ): string => metis_test_resolve_relative( $root, $relative );
    require_once $resolve( 'modules/Website/BlockRegistry.php' );
    require_once $resolve( 'modules/Website/Services/BlockRenderer.php' );

    \Metis\Modules\Website\BlockRegistry::register( 'image_carousel', [ 'schema_raw' => [] ] );
    $html = \Metis\Modules\Website\Services\BlockRenderer::render( [
        'type' => 'image_carousel',
        'data' => [
            'height' => 500,
            'transition' => 'fade',
            'transition_duration_ms' => 600,
            'slides' => [
                [ 'src' => '/media/one.jpg', 'alt' => 'One', 'action_type' => 'url', 'url' => '/learn', 'duration_ms' => 3000 ],
                [ 'src' => '/media/two.jpg', 'alt' => 'Two', 'action_type' => 'popup', 'popup_id' => 7, 'duration_ms' => 4000 ],
            ],
        ],
        'style' => [],
    ] );
    $cinematic_html = \Metis\Modules\Website\Services\BlockRenderer::render( [
        'type' => 'image_carousel',
        'data' => [
            'transition' => 'cinematic',
            'slides' => [ [ 'src' => '/media/cinematic.jpg', 'alt' => 'Cinematic' ] ],
        ],
        'style' => [],
    ] );
    $crossfade_html = \Metis\Modules\Website\Services\BlockRenderer::render( [ 'type' => 'image_carousel', 'data' => [ 'transition' => 'crossfade', 'slides' => [[ 'src' => '/media/crossfade.jpg' ]] ], 'style' => [] ] );
    $ken_burns_html = \Metis\Modules\Website\Services\BlockRenderer::render( [ 'type' => 'image_carousel', 'data' => [ 'transition' => 'ken_burns', 'slides' => [[ 'src' => '/media/ken-burns.jpg', 'duration_ms' => 6000 ]] ], 'style' => [] ] );
    $reveal_html = \Metis\Modules\Website\Services\BlockRenderer::render( [ 'type' => 'image_carousel', 'data' => [ 'transition' => 'reveal', 'slides' => [[ 'src' => '/media/reveal.jpg' ]] ], 'style' => [] ] );
    $checks = [
        [ str_contains( $html, 'aria-roledescription="carousel"' ), 'Carousel must expose an accessible carousel region.' ],
        [ str_contains( $html, 'data-metis-popup="7"' ), 'Carousel popup actions must use the shared popup trigger contract.' ],
        [ str_contains( $html, 'href="/learn"' ), 'Carousel link actions must render their configured URL.' ],
        [ str_contains( $html, 'data-duration-ms="3000"' ), 'Carousel must preserve per-slide display durations.' ],
        [ str_contains( $html, 'aspect-ratio:1983 / 793' ) && ! str_contains( $html, 'style="height:500px"' ), 'Carousel must size itself from the standard responsive banner ratio instead of a fixed height.' ],
        [ str_contains( $html, 'is-transition-fade' ), 'Carousel must render the selected transition.' ],
        [ str_contains( $html, 'width:100vw' ) && str_contains( $html, 'margin-left:-50vw' ), 'Carousel must use the shared full-viewport layout pattern so image width follows the visible viewport.' ],
        [ str_contains( $html, 'display:flex;align-items:center;justify-content:center' ), 'Carousel controls must center their arrow glyphs within the control buttons.' ],
        [ str_contains( $html, 'border:1px solid rgba(15,23,42,.7)' ), 'Carousel slide indicators must use a thin contrast stroke so they remain visible over light imagery.' ],
        [ str_contains( $cinematic_html, 'is-transition-cinematic' ) && str_contains( $cinematic_html, 'cubic-bezier(.22,1,.36,1)' ), 'Carousel must support the cinematic transition with a restrained, smooth easing curve.' ],
        [ str_contains( $crossfade_html, 'is-transition-crossfade' ) && str_contains( $crossfade_html, 'cubic-bezier(.4,0,.2,1)' ), 'Carousel must support the crossfade transition.' ],
        [ str_contains( $ken_burns_html, 'is-transition-ken_burns' ) && str_contains( $ken_burns_html, '--metis-carousel-slide-duration:6000ms' ), 'Carousel must support Ken Burns using the configured slide duration.' ],
        [ str_contains( $reveal_html, 'is-transition-reveal' ) && str_contains( $reveal_html, 'clip-path:inset(0 100% 0 0)' ), 'Carousel must support the reveal transition.' ],
    ];
    $failures = [];
    foreach ( $checks as [ $passed, $message ] ) { if ( ! $passed ) { $failures[] = $message; } }
    if ( $failures !== [] ) { fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL ); exit( 1 ); }
    fwrite( STDOUT, "Website image carousel block checks passed.\n" );
}
