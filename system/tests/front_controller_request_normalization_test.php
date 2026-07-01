<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', dirname( $root ) . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['QUERY_STRING'] = '';

ob_start();
require_once $root . '/src/Metis/Core/Kernel/Runtime.php';
metis_kernel_bootstrap( 'web' );
ob_end_clean();

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$normalize = static function ( string $requestUri, string $query = '' ): array {
    $_SERVER['REQUEST_URI'] = $requestUri . ( $query !== '' ? '?' . $query : '' );
    $_SERVER['QUERY_STRING'] = $query;
    $_SERVER['PATH_INFO'] = '';
    $_SERVER['ORIG_PATH_INFO'] = '';
    $_SERVER['REDIRECT_URL'] = '';
    $GLOBALS['metis_query_vars'] = [];

    $attributes = metis_kernel_normalize_front_controller_request( 'web', [] );

    return [
        'request_uri' => (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
        'path_info' => (string) ( $_SERVER['PATH_INFO'] ?? '' ),
        'redirect_url' => (string) ( $_SERVER['REDIRECT_URL'] ?? '' ),
        'attributes' => $attributes,
        'ajax' => metis_runtime_get_query_var( 'metis_api_ajax', 0 ),
        'provider' => metis_runtime_get_query_var( 'metis_webhook_provider', '' ),
    ];
};

$ajax = $normalize( '/system/ajax', 'action=metis_example' );
$assert( $ajax['request_uri'] === '/api/ajax?action=metis_example', 'Legacy /system/ajax must normalize to the canonical AJAX endpoint and preserve query strings.' );
$assert( $ajax['path_info'] === '/api/ajax', 'Legacy /system/ajax must normalize PATH_INFO to the canonical AJAX endpoint.' );
$assert( (int) $ajax['ajax'] === 1, 'Legacy /system/ajax must seed the AJAX query var for compatibility.' );

$external_ajax = $normalize( '/e/ac', 'action=metis_example' );
$assert( $external_ajax['request_uri'] === '/api/ajax?action=metis_example', 'External /e/ac must normalize to the canonical AJAX endpoint and preserve query strings.' );
$assert( $external_ajax['path_info'] === '/api/ajax', 'External /e/ac must normalize PATH_INFO to the canonical AJAX endpoint.' );
$assert( (int) $external_ajax['ajax'] === 1, 'External /e/ac must seed the AJAX query var for compatibility.' );

$cron = $normalize( '/system/cron' );
$assert( $cron['request_uri'] === '/api/cron', 'Legacy /system/cron must normalize to the canonical API cron endpoint.' );
$assert( $cron['redirect_url'] === '/api/cron', 'Legacy /system/cron must normalize redirect metadata to the canonical API cron endpoint.' );

$external_cron = $normalize( '/e/cj' );
$assert( $external_cron['request_uri'] === '/api/cron', 'External /e/cj must normalize to the canonical API cron endpoint.' );
$assert( $external_cron['redirect_url'] === '/api/cron', 'External /e/cj must normalize redirect metadata to the canonical API cron endpoint.' );

$webhook = $normalize( '/system/webhook/stripe' );
$assert( $webhook['request_uri'] === '/metis-webhooks/stripe', 'Legacy /system/webhook/{provider} must normalize to the canonical webhook route.' );
$assert( (string) ( $webhook['attributes']['provider'] ?? '' ) === 'stripe', 'Legacy /system/webhook/{provider} must populate the webhook provider attribute.' );
$assert( $webhook['provider'] === 'stripe', 'Legacy /system/webhook/{provider} must seed the webhook provider query var for compatibility.' );

$external_webhook = $normalize( '/e/wh/stripe' );
$assert( $external_webhook['request_uri'] === '/metis-webhooks/stripe', 'External /e/wh/{provider} must normalize to the canonical webhook route.' );
$assert( (string) ( $external_webhook['attributes']['provider'] ?? '' ) === 'stripe', 'External /e/wh/{provider} must populate the webhook provider attribute.' );
$assert( $external_webhook['provider'] === 'stripe', 'External /e/wh/{provider} must seed the webhook provider query var for compatibility.' );

$cron_alias = $normalize( '/api/cron' );
$assert( $cron_alias['request_uri'] === '/api/cron', 'Canonical API cron endpoint must remain unchanged during normalization.' );

$untouched = $normalize( '/admin/contacts' );
$assert( $untouched['request_uri'] === '/admin/contacts', 'Non-wrapper routes must pass through normalization unchanged.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Front controller request normalization checks passed.\n" );
