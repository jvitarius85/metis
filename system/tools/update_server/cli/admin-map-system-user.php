<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This command must run from the CLI.\n" );
    exit( 1 );
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

function metis_update_server_admin_option( array $argv, string $name, string $default = '' ): string {
    $prefix = '--' . $name . '=';
    foreach ( $argv as $arg ) {
        if ( str_starts_with( (string) $arg, $prefix ) ) {
            return trim( substr( (string) $arg, strlen( $prefix ) ) );
        }
    }

    return $default;
}

function metis_update_server_current_cli_user(): string {
    $sudoUser = trim( (string) getenv( 'SUDO_USER' ) );
    if ( $sudoUser !== '' ) {
        return $sudoUser;
    }

    if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) ) {
        $record = posix_getpwuid( posix_geteuid() );
        if ( is_array( $record ) && trim( (string) ( $record['name'] ?? '' ) ) !== '' ) {
            return trim( (string) $record['name'] );
        }
    }

    return trim( (string) shell_exec( 'id -un 2>/dev/null' ) );
}

$email = strtolower( metis_update_server_admin_option( $argv, 'email' ) );
$systemUser = metis_update_server_admin_option( $argv, 'system-user', metis_update_server_current_cli_user() );

if ( $email === '' || $systemUser === '' ) {
    fwrite( STDERR, "Usage: php cli/admin-map-system-user.php --email=admin@example.com [--system-user=current-shell-user]\n" );
    exit( 1 );
}

$user = metis_update_server_app()->assignAdminSystemUser( $email, $systemUser );
echo json_encode( [
    'ok' => true,
    'email' => (string) ( $user['email'] ?? $email ),
    'system_username' => (string) ( $user['system_username'] ?? $systemUser ),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
