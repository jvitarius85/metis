<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$source = (string) file_get_contents( $root . '/src/Metis/Core/Runtime/StandaloneApplicationBootstrap.php' );
$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$assert(
    str_contains( $source, 'function metis_standalone_register_update_server_installation(): array' )
        && str_contains( $source, "Application::has_service( 'update_server_client' )" )
        && str_contains( $source, "service( 'module_updates' )->discoverInstalledModules()" ),
    'Installer runtime must expose an update-server registration helper that can register the install with its discovered module inventory.'
);

$completeStart = strpos( $source, "if ( \$action === 'complete' ) {" );
$completeEnd = strpos( $source, "metis_standalone_install_json( [\n                    'ok' => true,", $completeStart !== false ? $completeStart : 0 );
$completeBody = $completeStart !== false && $completeEnd !== false ? substr( $source, $completeStart, $completeEnd - $completeStart ) : '';

$assert(
    str_contains( $completeBody, '$update_registration = metis_standalone_register_update_server_installation();' )
        && str_contains( $completeBody, "throw new RuntimeException( (string) ( \$update_registration['message'] ?? 'Update server registration failed.' ) );" )
        && str_contains( $completeBody, 'metis_standalone_mark_installed();' ),
    'Installer completion must register the installation with the update server before writing the install lock.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Update server installer registration contract checks passed.\n" );
