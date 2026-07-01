<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This tool must run from the CLI.\n" );
    exit( 1 );
}

require_once dirname( __DIR__ ) . '/src/bootstrap.php';

function metis_update_server_trigger_option( array $argv, string $name, string $default = '' ): string {
    $prefix = '--' . $name . '=';
    foreach ( $argv as $arg ) {
        if ( str_starts_with( (string) $arg, $prefix ) ) {
            return trim( substr( (string) $arg, strlen( $prefix ) ) );
        }
    }

    return $default;
}

$installationId = metis_update_server_trigger_option( $argv, 'installation-id' );
$trigger = metis_update_server_trigger_option( $argv, 'trigger', 'update_server_scheduler' );

$result = metis_update_server_app()->triggerInstallationCrons( $trigger, $installationId );
echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
exit( ! empty( $result['ok'] ) ? 0 : 1 );
