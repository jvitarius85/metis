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
    'Metis\\Modules\\Newsletter\\DeliveryService' => '/modules/newsletter/',
    'Metis\\Modules\\Website\\Services\\ThemeService' => '/modules/website/',
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
        sprintf( 'Bundle class [%s] must resolve from the bundle source root instead of a retired source-module mirror.', $class )
    );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Module bundle autoloader checks passed.\n" );
