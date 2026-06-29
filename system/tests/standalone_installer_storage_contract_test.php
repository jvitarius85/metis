<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$source = file_get_contents( $root . '/src/Metis/Core/Runtime/StandaloneApplicationBootstrap.php' );
$governance = require $root . '/config/governance.php';
$failures = [];

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$assert(
    is_string( $source ) && str_contains( $source, "'Cache' => \$root . '/storage/runtime/cache'" ),
    'Standalone installer must require the runtime cache directory instead of the retired top-level storage/cache path.'
);

$assert(
    is_string( $source ) && ! str_contains( $source, "/storage/cache'" ),
    'Standalone installer must not validate the retired storage/cache directory.'
);

$assert(
    is_string( $source ) && ! str_contains( $source, "/storage/tmp'" ),
    'Standalone installer must not block setup on the retired storage/tmp directory.'
);

$assert(
    is_string( $source ) && ! str_contains( $source, "/storage/uploads'" ),
    'Standalone installer must not block setup on the legacy storage/uploads directory.'
);

$assert(
    is_string( $source ) && ! str_contains( $source, "/storage/media'" ),
    'Standalone installer must not block setup on the legacy storage/media directory.'
);

$requiredMediaRoots = array_values( array_filter( (array) ( $governance['required_media_roots'] ?? [] ), 'is_string' ) );
$assert(
    is_string( $source ) && str_contains( $source, "metis_media_storage_roots( false )" ),
    'Standalone installer must derive media directories from the canonical runtime media roots.'
);

foreach ( $requiredMediaRoots as $relativePath ) {
    $label = ucwords( str_replace( '-', ' ', basename( $relativePath ) ) );
    $assert(
        is_string( $source ) && str_contains( $source, "'" . $label . "'" ),
        sprintf( 'Standalone installer must still expose the canonical media check label [%s].', $relativePath )
    );
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Standalone installer storage contract checks passed.\n" );
