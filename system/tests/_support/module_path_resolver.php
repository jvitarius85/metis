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

function metis_test_resolve_module_relative( string $system_root, string $relative ): ?string {
    $normalized = ltrim( str_replace( '\\', '/', $relative ), '/' );
    if ( ! str_starts_with( $normalized, 'src/Metis/Modules/' ) ) {
        return null;
    }

    return \Metis\Core\ModulePathRegistry::resolveLogicalPath( $normalized );
}

function metis_test_resolve_relative( string $system_root, string $relative ): string {
    $normalized = ltrim( str_replace( '\\', '/', $relative ), '/' );

    $module_relative = metis_test_resolve_module_relative( $system_root, $normalized );
    if ( is_string( $module_relative ) && $module_relative !== '' ) {
        return $module_relative;
    }

    return \Metis\Core\ModulePathRegistry::resolveLogicalPath( $normalized );
}
