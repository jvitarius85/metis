<?php
declare(strict_types=1);

function metis_test_private_modules_root( string $system_root ): string {
    $configured = getenv( 'METIS_PRIVATE_MODULES_ROOT' );
    if ( is_string( $configured ) && trim( $configured ) !== '' ) {
        return rtrim( str_replace( '\\', '/', trim( $configured ) ), '/' );
    }

    $project_root = dirname( $system_root );
    return rtrim( str_replace( '\\', '/', dirname( $project_root ) . '/metis-private/modules' ), '/' );
}

function metis_test_resolve_relative( string $system_root, string $relative ): string {
    $normalized = ltrim( str_replace( '\\', '/', $relative ), '/' );

    foreach ( [ 'help', 'hermes', 'people', 'portal', 'profile', 'settings' ] as $slug ) {
        $prefix = 'modules/' . $slug . '/';
        if ( str_starts_with( $normalized, $prefix ) ) {
            return rtrim( str_replace( '\\', '/', $system_root ), '/' ) . '/src/Metis/Core/BuiltInServices/' . $slug . '/' . substr( $normalized, strlen( $prefix ) );
        }
    }

    if ( str_starts_with( $normalized, 'modules/' ) ) {
        $private_modules_root = metis_test_private_modules_root( $system_root );
        if ( is_dir( $private_modules_root ) ) {
            return $private_modules_root . '/' . substr( $normalized, strlen( 'modules/' ) );
        }
    }

    return rtrim( str_replace( '\\', '/', $system_root ), '/' ) . '/' . $normalized;
}
