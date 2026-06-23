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

$settingsJs = $read( 'src/Metis/Core/BuiltInServices/settings/assets/settings.js' );
$settingsAjax = $read( 'src/Metis/Core/BuiltInServices/settings/assets/settings.ajax.php' );
$settingsBootstrap = $read( 'src/Metis/Core/BuiltInServices/settings/views/_settings_bootstrap.php' );
$moduleInstall = $read( 'src/Metis/Core/Services/ModuleInstallService.php' );
$releaseManager = $read( 'src/Metis/Release/ReleaseManager.php' );

$assert(
    str_contains( $settingsJs, "latestRunStatus === 'failed' || latestRunStatus === 'error'" ),
    'Backup status alert must only show the latest run error when the latest run actually failed.'
);

$assert(
    str_contains( $settingsBootstrap, "dirname( __DIR__, 2 ) . '/help/module.json'" ),
    'Help hydration must check the built-in Help module manifest path relative to the core settings service.'
);

$assert(
    str_contains( $settingsBootstrap, "dirname( __DIR__, 3 ) . '/Help/Seeds/HelpDocumentsSeed.php'" ),
    'Help hydration must check the canonical HelpDocumentsSeed path relative to the core settings service.'
);

$assert(
    str_contains( $settingsAjax, "CacheService::clearGroup( 'modules' );" )
    && str_contains( $settingsAjax, 'metis_standalone_invalidate_config_cache' )
    && str_contains( $settingsAjax, 'CacheService::rebuildSystemCaches();' ),
    'Help auto-remediate must reload module/config/runtime caches after reseeding and rebuilding the search index.'
);

$assert(
    str_contains( $moduleInstall, 'use Metis\Core\Modules\ModuleValidator;' )
    && str_contains( $moduleInstall, '(new ModuleValidator())->validateModule($moduleSource, $manifest, $moduleId);' ),
    'Module install must validate extracted module manifests before copying into the runtime module root.'
);

$assert(
    str_contains( $releaseManager, '$this->refreshProtectionPreflightState();' ),
    'Release preflight checks must refresh protection state before integrity and module compliance evaluation.'
);

$assert(
    str_contains( $releaseManager, 'ModulePathRegistry::retireLegacySourceModuleTree();' ),
    'Release refresh and finalization paths must retire stale legacy source modules before integrity and module compliance run.'
);

$assert(
    str_contains( $releaseManager, "CacheService::clearGroup( 'modules' );" )
    && str_contains( $releaseManager, "CacheService::forget( 'updates.modules' );" )
    && str_contains( $releaseManager, "Application::has_service( 'modules' )" ),
    'Release protection preflight refresh must invalidate module caches and reload the module service.'
);

$assert(
    str_contains( $moduleInstall, 'ModulePathRegistry::retireLegacySourceModuleTree();' ),
    'Module install protection refresh must retire stale legacy source modules before rebuilding integrity and compliance state.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Operational hardening contract checks passed.\n" );
