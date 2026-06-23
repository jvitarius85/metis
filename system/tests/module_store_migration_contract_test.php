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

$normalize_slug = static function ( string $value ): string {
    $value = strtolower( trim( $value ) );
    $value = preg_replace( '/[^a-z0-9]+/', '_', $value );
    return trim( (string) $value, '_' );
};

require_once $root . '/src/Metis/Core/ModulePathRegistry.php';

$moduleRoot = \Metis\Core\ModulePathRegistry::moduleRootPath();
$coreServiceRoot = \Metis\Core\ModulePathRegistry::coreServiceRootPath();
$rootDefinitions = \Metis\Core\ModulePathRegistry::rootDefinitions();
$storeModules = \Metis\Core\ModulePathRegistry::storeManagedModuleSlugs();
$coreServices = \Metis\Core\ModulePathRegistry::coreServiceSlugs();
$sourceInventory = \Metis\Core\ModulePathRegistry::sourceModuleInventory();
$legacyStoreSourceModules = \Metis\Core\ModulePathRegistry::legacyStoreManagedSourceModules();

$assert(
    str_ends_with( str_replace( '\\', '/', $moduleRoot ), '/system/modules/' ),
    'Runtime module root must resolve to system/modules/.'
);

$assert(
    str_ends_with( str_replace( '\\', '/', $coreServiceRoot ), '/system/src/Metis/Core/BuiltInServices/' ),
    'Core service root must resolve to system/src/Metis/Core/BuiltInServices/.'
);

$packageTypes = array_values(
    array_map(
        static fn ( array $root ): string => (string) ( $root['package_type'] ?? '' ),
        $rootDefinitions
    )
);
sort( $packageTypes );

$assert(
    $packageTypes === [ 'core_service', 'module' ],
    'Runtime discovery must only scan the built-in core-service root and the runtime module root.'
);

foreach ( $rootDefinitions as $definition ) {
    $path = str_replace( '\\', '/', (string) ( $definition['path'] ?? '' ) );
    $assert(
        ! str_contains( $path, '/system/src/Metis/Modules/' ),
        'Runtime discovery must not scan system/src/Metis/Modules/.'
    );
}

$expectedLegacyStoreModules = [
    'board',
    'calendar',
    'contacts',
    'donations',
    'drive',
    'finance',
    'forms',
    'grandystash',
    'import',
    'media',
    'newsletter',
    'resources',
    'testimonies',
    'website',
];
$actualLegacyStoreModules = array_keys( $legacyStoreSourceModules );
sort( $expectedLegacyStoreModules );
sort( $actualLegacyStoreModules );

$assert(
    $actualLegacyStoreModules === $expectedLegacyStoreModules,
    'Legacy source-backed store-module inventory changed. Update ModulePathRegistry and the migration plan deliberately before adding or removing source-side store modules.'
);

$sourceModuleRoot = $root . '/src/Metis/Modules';
$filesystemSourceDirectories = array_values(
    array_filter(
        scandir( $sourceModuleRoot ) ?: [],
        static fn ( string $entry ): bool => $entry !== '.' && $entry !== '..' && is_dir( $sourceModuleRoot . '/' . $entry )
    )
);
$filesystemSourceSlugs = array_values(
    array_map(
        static function ( string $directory ) use ( $normalize_slug ): string {
            return $normalize_slug( $directory );
        },
        $filesystemSourceDirectories
    )
);
sort( $filesystemSourceSlugs );

$inventorySlugs = array_keys( $legacyStoreSourceModules );
sort( $inventorySlugs );

$assert(
    $filesystemSourceSlugs === $inventorySlugs,
    'Every remaining source-side module directory under system/src/Metis/Modules must be declared as a legacy store-backed module in ModulePathRegistry.'
);

$overlap = array_intersect( $storeModules, $coreServices );
$assert(
    $overlap === [],
    'Store-managed module slugs and built-in core-service slugs must remain disjoint.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Module store migration contract checks passed.\n" );
