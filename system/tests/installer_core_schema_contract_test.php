<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$failures = [];

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$read = static function ( string $relative ) use ( $root ): string {
    $contents = file_get_contents( $root . '/' . ltrim( $relative, '/\\' ) );
    return $contents === false ? '' : $contents;
};

$databaseRuntime = $read( 'src/Metis/Core/DatabaseRuntime.php' );
$installerRuntime = $read( 'src/Metis/Core/Runtime/StandaloneApplicationBootstrap.php' );
$schemaBridge = $read( 'src/Metis/Core/Runtime/ModuleSchemaRuntimeBridge.php' );
$installStart = strpos( $databaseRuntime, 'function metis_install_db(): void {' );
$installEnd = $installStart !== false
    ? strpos( $databaseRuntime, '// -------------------------------------------------------------------------', $installStart + 1 )
    : false;
$installBody = $installStart !== false && $installEnd !== false && $installEnd > $installStart
    ? substr( $databaseRuntime, $installStart, $installEnd - $installStart )
    : $databaseRuntime;

$assert(
    ! str_contains( $installBody, "Metis_Tables::get( 'contacts' )" )
    && ! str_contains( $installBody, "Metis_Tables::get( 'contact_dav_tokens' )" )
    && ! str_contains( $installBody, "Metis_Tables::get( 'contact_dav_sync' )" )
    && ! str_contains( $installBody, "Metis_Tables::get( 'newsletter_lists' )" )
    && ! str_contains( $installBody, "Metis_Tables::get( 'newsletter_subs' )" ),
    'Core database installer must not create store-managed Contacts or Newsletter tables.'
);

$assert(
    str_contains( $installBody, "Metis_Tables::get( 'settings' )" )
    && str_contains( $installBody, "Metis_Tables::get( 'auth_users' )" )
    && str_contains( $installBody, "Metis_Tables::get( 'job_queue' )" )
    && str_contains( $installBody, "Metis_Tables::get( 'sync_state' )" )
    && str_contains( $installBody, "Metis_Tables::get( 'media_files' )" )
    && str_contains( $installBody, "Metis_Tables::get( 'navigation_items' )" ),
    'Core database installer must retain the core-owned settings, auth, jobs, sync, media, and navigation tables.'
);

$assert(
    ! str_contains( $installerRuntime, "'contacts' =>" )
    && ! str_contains( $installerRuntime, "'forms' =>" )
    && ! str_contains( $installerRuntime, "'newsletter' =>" )
    && ! str_contains( $installerRuntime, "'board' =>" )
    && ! str_contains( $installerRuntime, "'calendar' =>" )
    && ! str_contains( $installerRuntime, "'finance' =>" )
    && ! str_contains( $installerRuntime, "'website' =>" )
    && ! str_contains( $installerRuntime, "'import' =>" )
    && ! str_contains( $installerRuntime, "'grandy_stash' =>" )
    && ! str_contains( $installerRuntime, "'cms' =>" ),
    'Standalone installer must not enumerate store-managed or stale schema steps.'
);

$assert(
    str_contains( $installerRuntime, 'data-step-indicator="modules"' )
    && str_contains( $installerRuntime, '<h2>Optional Modules</h2>' )
    && str_contains( $installerRuntime, "post('install_modules'")
    && str_contains( $installerRuntime, "post('complete')")
    && str_contains( $installerRuntime, "metis_standalone_install_module_registry_snapshot( true )" )
    && str_contains( $installerRuntime, 'Module store metadata could not be loaded during install.' )
    && str_contains( $installerRuntime, 'Metis Modules repository name, branch, and published registry.' ),
    'Standalone installer must expose an optional module-store step and defer final completion until that step is finished or skipped.'
);

$assert(
    str_contains( $schemaBridge, "'people' =>" )
    && str_contains( $schemaBridge, "'hermes' =>" )
    && str_contains( $schemaBridge, "'communications_inbound' =>" )
    && str_contains( $schemaBridge, "'drive' =>" )
    && str_contains( $schemaBridge, "'recovery' =>" )
    && str_contains( $schemaBridge, "'entity_id_service' =>" )
    && str_contains( $schemaBridge, "'backup_service' =>" )
    && str_contains( $schemaBridge, "'help_search_store' =>" ),
    'Installer schema bridge must expose only the remaining core-owned and built-in schema installers.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Installer core schema contract checks passed.\n" );
