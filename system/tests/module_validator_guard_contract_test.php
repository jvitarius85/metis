<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

require_once dirname( __DIR__ ) . '/src/Metis/Core/Modules/ModuleValidator.php';

$workspace = sys_get_temp_dir() . '/metis-module-validator-' . bin2hex( random_bytes( 6 ) );
$failures = [];

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

if ( ! mkdir( $workspace, 0777, true ) && ! is_dir( $workspace ) ) {
    fwrite( STDERR, "Unable to create temp workspace.\n" );
    exit( 1 );
}

$writeBootstrap = static function ( string $directory, string $contents ): void {
    if ( ! is_dir( $directory ) ) {
        mkdir( $directory, 0777, true );
    }

    file_put_contents( $directory . '/bootstrap.php', $contents );
};

$validator = new \Metis\Core\Modules\ModuleValidator();
$method = new ReflectionMethod( \Metis\Core\Modules\ModuleValidator::class, 'validateBootstrapFunctionCollisions' );

$guardedPath = $workspace . '/guarded';
$unguardedPath = $workspace . '/unguarded';

$writeBootstrap(
    $guardedPath,
    <<<'PHP'
<?php
if ( ! function_exists( 'metis_people_table_exists' ) ) {
    function metis_people_table_exists( string $table ): bool {
        return $table !== '';
    }
}
PHP
);

$writeBootstrap(
    $unguardedPath,
    <<<'PHP'
<?php
function metis_people_table_exists( string $table ): bool {
    return $table !== '';
}
PHP
);

try {
    $method->invoke( $validator, $guardedPath, 'bootstrap.php', 'guarded' );
    $guardedPassed = true;
} catch ( Throwable $e ) {
    $guardedPassed = false;
}

$assert(
    $guardedPassed,
    'Guarded bootstrap helpers should be allowed when the runtime already defines the same helper name.'
);

$unguardedFailed = false;
try {
    $method->invoke( $validator, $unguardedPath, 'bootstrap.php', 'unguarded' );
} catch ( RuntimeException $e ) {
    $unguardedFailed = str_contains( $e->getMessage(), 'conflicts with an existing runtime function' );
}

$assert(
    $unguardedFailed,
    'Unguarded bootstrap helper collisions must still fail validation before the module is activated.'
);

@unlink( $guardedPath . '/bootstrap.php' );
@unlink( $unguardedPath . '/bootstrap.php' );
@rmdir( $guardedPath );
@rmdir( $unguardedPath );
@rmdir( $workspace );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Module validator guard contract checks passed.\n" );
