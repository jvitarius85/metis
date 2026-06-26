<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This tool must be run from the command line.\n" );
    exit( 1 );
}

define( 'METIS_STANDALONE', true );
define( 'METIS_PREFIX', 'metis' );
define( 'METIS_PATH', dirname( __DIR__, 2 ) . '/' );
define( 'METIS_URL', 'http://localhost/metis/' );

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'off';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';

require_once dirname( __DIR__ ) . '/src/Metis/Core/CoreBootstrap.php';
metis_define_system_version( dirname( __DIR__, 2 ) . '/' );
metis_core_bootstrap( [ 'standalone_bootstrap' ] );

function metis_recovery_manifest_cli_usage(): never {
    $script = 'php ' . METIS_TOOLS_PATH . 'recovery_manifest.php';
    fwrite( STDERR, implode( PHP_EOL, [
        'Metis recovery manifest CLI',
        '',
        'Usage:',
        '  ' . $script . ' rebuild [reason]',
    ] ) . PHP_EOL );
    exit( 1 );
}

function metis_recovery_manifest_cli_boot(): void {
    if ( ! metis_standalone_has_database_config() ) {
        fwrite( STDERR, 'Missing database config at ' . metis_standalone_database_config_path() . PHP_EOL );
        exit( 1 );
    }

    metis_standalone_boot();
}

$command = (string) ( $argv[1] ?? '' );
if ( $command === '' || in_array( $command, [ '-h', '--help', 'help' ], true ) ) {
    metis_recovery_manifest_cli_usage();
}

try {
    metis_recovery_manifest_cli_boot();
} catch ( Throwable $throwable ) {
    fwrite( STDERR, $throwable->getMessage() . PHP_EOL );
    exit( 1 );
}

if ( $command !== 'rebuild' ) {
    metis_recovery_manifest_cli_usage();
}

$reason = trim( (string) ( $argv[2] ?? 'cli' ) );

try {
    $result = ( new \Metis\Core\Recovery\RecoveryVerifier() )->rebuildManifest( $reason );
    echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
    exit( ! empty( $result['status'] ) && (string) $result['status'] === 'success' ? 0 : 1 );
} catch ( Throwable $throwable ) {
    echo json_encode( [
        'status' => 'exception',
        'message' => $throwable->getMessage(),
        'exception' => get_class( $throwable ),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
    exit( 1 );
}
