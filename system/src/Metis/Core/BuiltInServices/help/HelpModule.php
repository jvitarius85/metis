<?php
declare(strict_types=1);

namespace Metis\Modules\Help;

if ( class_exists( __NAMESPACE__ . '\\HelpModule', false ) ) {
    return;
}

use Metis\Core\HelpSearchStore;
use Metis\Http\Response;

final class HelpModule {
    private static bool $booted = false;

    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;
        \metis_on( 'init', [ self::class, 'ensureRuntimeSeeded' ], 6 );
    }

    public static function ensureRuntimeSeeded(): void {
        if ( ! self::shouldSeedRuntimeForCurrentRequest() ) {
            return;
        }

        $seed = static function (): void {
            try {
                ( new HelpSearchStore() )->ensureSeeded();
            } catch ( \Throwable $e ) {
                if ( class_exists( 'Metis_Logger' ) ) {
                    \Metis_Logger::warn( 'help.seed_failed', [ 'message' => $e->getMessage() ] );
                }
            }
        };

        if ( function_exists( 'metis_runtime_run_once_per_signature' ) ) {
            \metis_runtime_run_once_per_signature(
                'help_seed',
                [ __FILE__, dirname( __DIR__, 2 ) . '/Core/HelpSearchStore.php' ],
                $seed
            );
            return;
        }

        $seed();
    }

    private static function shouldSeedRuntimeForCurrentRequest(): bool {
        if ( PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg' ) {
            return true;
        }

        $requestUri = trim( self::serverValue( 'REQUEST_URI' ) );
        if ( $requestUri === '' ) {
            return true;
        }

        $path = (string) parse_url( $requestUri, PHP_URL_PATH );
        if ( $path === '' ) {
            return true;
        }

        $normalized = '/' . ltrim( $path, '/' );

        if ( str_starts_with( $normalized, '/admin/help' ) || str_starts_with( $normalized, '/help' ) ) {
            return true;
        }

        if ( str_starts_with( $normalized, '/admin/settings' ) || str_starts_with( $normalized, '/admin/runtime' ) ) {
            return true;
        }

        if ( str_starts_with( $normalized, '/ajax/' ) ) {
            $action = strtolower( self::requestValue( 'action' ) );
            return str_contains( $action, 'help' ) || str_contains( $action, 'remediate' );
        }

        return false;
    }

    private static function serverValue( string $key, string $default = '' ): string {
        $value = filter_input( INPUT_SERVER, $key, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE );
        if ( ! is_string( $value ) || $value === '' ) {
            return $default;
        }

        return $value;
    }

    private static function requestValue( string $key, string $default = '' ): string {
        $query = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE );
        if ( is_string( $query ) && $query !== '' ) {
            return $query;
        }

        $body = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE );
        if ( is_string( $body ) && $body !== '' ) {
            return $body;
        }

        return $default;
    }

    public static function errorResponse( int $status, string $title, string $message ): Response {
        return self::shellResponse(
            'error',
            [
                'page_kind' => 'error',
                'page_title' => $title,
                'page_subtitle' => $message,
                'tree' => [],
            ],
            $status
        );
    }

    public static function shellResponse( string $view, array $state, int $status = 200 ): Response {
        \metis_set_query_var( 'metis_domain', 'help' );
        \metis_set_query_var( 'metis_view', $view );
        \metis_set_query_var( 'metis_help_state', $state );

        if ( ! empty( $state['page_title'] ) && function_exists( 'metis_set_page_title' ) ) {
            \metis_set_page_title( (string) $state['page_title'] );
        }

        if ( function_exists( 'nocache_headers' ) ) {
            \nocache_headers();
        }
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        $shell = \METIS_SRC_PATH . 'Metis/Core/Runtime/ShellTemplate.php';
        if ( ! file_exists( $shell ) ) {
            return Response::html( '<div class="metis-error">METIS shell is missing.</div>', 500 );
        }

        ob_start();
        if ( function_exists( 'metis_security_trusted_include' ) ) {
            \metis_security_trusted_include( $shell );
        } else {
            require $shell;
        }

        return Response::html(
            (string) ob_get_clean(),
            $status,
            [ 'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0' ]
        );
    }

    public static function renderTemplate( string $path, array $vars ): string {
        extract( $vars, EXTR_SKIP );
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }
}
