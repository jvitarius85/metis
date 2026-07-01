<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This command must run from the CLI.\n" );
    exit( 1 );
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

function metis_update_server_auth_option( array $argv, string $name, string $default = '' ): string {
    $prefix = '--' . $name . '=';
    foreach ( $argv as $arg ) {
        if ( str_starts_with( (string) $arg, $prefix ) ) {
            return trim( substr( (string) $arg, strlen( $prefix ) ) );
        }
    }

    return $default;
}

function metis_update_server_run_or_fail( array $command ): void {
    $descriptor = [
        0 => [ 'pipe', 'r' ],
        1 => STDOUT,
        2 => STDERR,
    ];
    $process = proc_open( $command, $descriptor, $pipes );
    if ( ! is_resource( $process ) ) {
        throw new RuntimeException( 'Failed to start helper installation command.' );
    }
    fclose( $pipes[0] );
    $exitCode = proc_close( $process );
    if ( ! is_int( $exitCode ) || $exitCode !== 0 ) {
        throw new RuntimeException( 'Helper installation command failed: ' . implode( ' ', $command ) );
    }
}

if ( function_exists( 'posix_geteuid' ) && posix_geteuid() !== 0 ) {
    fwrite( STDERR, "Run this command as root or via sudo.\n" );
    exit( 1 );
}

$output = metis_update_server_auth_option( $argv, 'output', '/usr/local/bin/metis-update-auth-helper' );
$group = metis_update_server_auth_option( $argv, 'group', 'metis-update-auth' );
$pamService = metis_update_server_auth_option( $argv, 'pam-service', 'login' );
$shellUser = metis_update_server_auth_option( $argv, 'shell-user' );
$source = dirname(__DIR__) . '/auth_helper/metis_update_auth_helper.c';

if ( ! is_file( $source ) ) {
    throw new RuntimeException( 'Helper source file is missing.' );
}

$compiler = trim( (string) shell_exec( 'command -v gcc || command -v cc || true' ) );
if ( $compiler === '' ) {
    throw new RuntimeException( 'No C compiler found. Install gcc or cc first.' );
}

metis_update_server_run_or_fail( [ 'sh', '-lc', 'getent group ' . escapeshellarg( $group ) . ' >/dev/null || groupadd --system ' . escapeshellarg( $group ) ] );
metis_update_server_run_or_fail( [ $compiler, '-O2', '-Wall', '-Wextra', '-o', $output, $source, '-lpam' ] );
metis_update_server_run_or_fail( [ 'chown', 'root:' . $group, $output ] );
metis_update_server_run_or_fail( [ 'chmod', '4750', $output ] );
metis_update_server_run_or_fail( [ 'usermod', '-a', '-G', $group, 'www-data' ] );
if ( $shellUser !== '' ) {
    metis_update_server_run_or_fail( [ 'usermod', '-a', '-G', $group, $shellUser ] );
}

echo json_encode( [
    'ok' => true,
    'output' => $output,
    'group' => $group,
    'pam_service' => $pamService,
    'shell_user' => $shellUser,
    'restart_required' => 'Reload or restart php-fpm so www-data picks up the new supplementary group membership.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
