<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$system = dirname( __DIR__ );
$root = dirname( $system );
$failures = [];

require_once __DIR__ . '/_support/module_path_resolver.php';

$privateRoot = metis_test_private_modules_root( $system );
if ( is_dir( $privateRoot ) ) {
    putenv( 'METIS_PRIVATE_MODULES_ROOT=' . $privateRoot );
}

require_once $system . '/src/Metis/Core/CoreBootstrap.php';

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$bundleClasses = [
    'Metis\\Modules\\Forms\\SchemaManager' => '/modules/forms/',
    'Metis\\Modules\\Import\\Services\\ImportService' => '/modules/import/',
];

foreach ( $bundleClasses as $class => $expectedPathFragment ) {
    $assert( class_exists( $class ), sprintf( 'Bundle class [%s] must autoload successfully.', $class ) );
    if ( ! class_exists( $class ) ) {
        continue;
    }

    $reflection = new ReflectionClass( $class );
    $fileName = str_replace( '\\', '/', (string) $reflection->getFileName() );
    $assert(
        str_contains( $fileName, $expectedPathFragment ) && str_contains( $fileName, '/metis-private/' ),
        sprintf( 'Bundle class [%s] must resolve from the bundle source root instead of src/Metis/Modules.', $class )
    );
}

$knownSourceFallbacks = [
    'Metis\\Modules\\Newsletter\\DeliveryService' => '/src/Metis/Modules/Newsletter/',
    'Metis\\Modules\\Website\\Services\\ThemeService' => '/src/Metis/Modules/Website/',
];

foreach ( $knownSourceFallbacks as $class => $expectedPathFragment ) {
    $assert( class_exists( $class ), sprintf( 'Class [%s] must remain loadable while bundle migration is incomplete.', $class ) );
    if ( ! class_exists( $class ) ) {
        continue;
    }

    $reflection = new ReflectionClass( $class );
    $fileName = str_replace( '\\', '/', (string) $reflection->getFileName() );
    $assert(
        str_contains( $fileName, $expectedPathFragment ),
        sprintf( 'Known bundle gap [%s] must remain visible until that class is moved into the runtime bundle.', $class )
    );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Module bundle autoloader checks passed.\n" );
