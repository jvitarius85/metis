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

$read = static function ( string $path ) use ( $root ): string {
    $contents = file_get_contents( $root . '/' . ltrim( $path, '/\\' ) );
    return $contents === false ? '' : $contents;
};

$integrityRuntime = $read( 'src/Metis/Core/IntegrityRuntime.php' );

$assert(
    str_contains( $integrityRuntime, "str_starts_with( \$basename, '._' )" ),
    'Integrity runtime must ignore macOS AppleDouble files so module installs and backups do not leave false discrepancy failures behind.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Integrity runtime contract checks passed.\n" );
