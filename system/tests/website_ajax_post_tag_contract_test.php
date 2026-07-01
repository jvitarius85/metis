<?php
declare(strict_types=1);

namespace Metis\Modules\Website\Services {
    final class PostTagService {
        public static function ensureTagIds( string|array $tag_names ): array {
            if ( is_string( $tag_names ) ) {
                return [ 31, 44 ];
            }

            return [ 52 ];
        }

        public static function getById( int $id ): ?array {
            return match ( $id ) {
                31 => [ 'id' => 31, 'name' => 'Community', 'slug' => 'community' ],
                44 => [ 'id' => 44, 'name' => 'Accessibility', 'slug' => 'accessibility' ],
                52 => [ 'id' => 52, 'name' => 'Advocacy', 'slug' => 'advocacy' ],
                default => null,
            };
        }
    }
}

namespace {
    if ( PHP_SAPI !== 'cli' ) {
        fwrite( STDERR, "This test must be run from the command line.\n" );
        exit( 1 );
    }

    define( 'METIS_ROOT', dirname( __DIR__ ) );

    $GLOBALS['metis_test_request_post'] = [];

    function metis_request_post(): array {
        return is_array( $GLOBALS['metis_test_request_post'] ?? null ) ? $GLOBALS['metis_test_request_post'] : [];
    }

    function metis_runtime_unslash( mixed $value ): mixed {
        return $value;
    }

    function metis_ajax_register_controller( string $action, array $config = [] ): void {}

    function metis_ajax_register_handler( string $action, callable $handler ): void {}

    final class Metis_Logger {
        public static function info( string $message, array $context = [] ): void {}
    }

    $root = dirname( __DIR__ );
    require_once __DIR__ . '/_support/module_path_resolver.php';
    $resolve_relative = static fn ( string $relative ): string => metis_test_resolve_relative( $root, $relative );
    require_once $resolve_relative( 'modules/website/ajax/website.ajax.php' );

    $failures = [];
    $assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
        if ( ! $condition ) {
            $failures[] = $message;
        }
    };

    $GLOBALS['metis_test_request_post'] = [
        'post_tags' => 'community, accessibility',
    ];
    $merged_from_string = metis_website_ajax_post_tag_ids_merged_with_names( [ 12, 31 ] );
    $assert(
        $merged_from_string === [ 12, 31, 44 ],
        'Website post tag merge should preserve existing ids and append created tag ids from string input.'
    );

    $GLOBALS['metis_test_request_post'] = [
        'post_tags' => [ 'Advocacy' ],
    ];
    $merged_from_array = metis_website_ajax_post_tag_ids_merged_with_names( [ 12 ] );
    $assert(
        $merged_from_array === [ 12, 52 ],
        'Website post tag merge should accept array input and merge created tag ids.'
    );

    $payload = metis_website_ajax_post_tag_payload( [ 'post_tag_ids' => [ 31, 44, 999 ] ] );
    $assert(
        ( $payload['post_tags'][0] ?? '' ) === 'Community'
        && ( $payload['post_tags'][1] ?? '' ) === 'Accessibility'
        && ( $payload['tags'][0]['slug'] ?? '' ) === 'community'
        && ( $payload['tags'][1]['slug'] ?? '' ) === 'accessibility',
        'Website post tag payload should resolve tag metadata through the website tag service.'
    );
    $assert(
        count( $payload['post_tags'] ?? [] ) === 2 && count( $payload['tags'] ?? [] ) === 2,
        'Website post tag payload should skip unresolved tag ids.'
    );

    if ( $failures !== [] ) {
        fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
        exit( 1 );
    }

    fwrite( STDOUT, "Website AJAX post tag contract checks passed.\n" );
}
