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

require_once $root . '/src/Metis/Core/ModulePathRegistry.php';

$snapshot = \Metis\Core\ModulePathRegistry::sourceRetirementSnapshot();

$assert(
    is_dir( rtrim( (string) $snapshot['development_bundle_source_root'], '/\\' ) ),
    'Development bundle source root must resolve to a readable module bundle directory.'
);

$assert(
    (array) ( $snapshot['missing_bundle_slugs'] ?? [] ) === [],
    'Every legacy source-backed store module must already have a bundle replacement in the development bundle source root.'
);

foreach ( (array) ( $snapshot['modules'] ?? [] ) as $module ) {
    if ( ! is_array( $module ) ) {
        continue;
    }

    $slug = (string) ( $module['slug'] ?? 'unknown' );
    $assert(
        ! empty( $module['bundle_present'] ),
        sprintf( 'Bundle replacement must exist for legacy source module [%s].', $slug )
    );
    $assert(
        ! empty( $module['manifest_present'] ),
        sprintf( 'Bundle replacement for [%s] must include module.json.', $slug )
    );
    $assert(
        ! empty( $module['entry_present'] ),
        sprintf( 'Bundle replacement for [%s] must include Module.php.', $slug )
    );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Module source retirement coverage checks passed.\n" );
