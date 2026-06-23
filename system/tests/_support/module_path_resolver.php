<?php
declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/src/Metis/Core/ModulePathRegistry.php';

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

    return \Metis\Core\ModulePathRegistry::resolveLogicalPath( $normalized );
}
