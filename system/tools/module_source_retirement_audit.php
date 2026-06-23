<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This tool must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__, 2 );

require_once $root . '/system/src/Metis/Core/CoreBootstrap.php';
require_once $root . '/system/src/Metis/Core/ModulePathRegistry.php';

$snapshot = \Metis\Core\ModulePathRegistry::sourceRetirementSnapshot();

fwrite(
    STDOUT,
    json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL
);

exit( ! empty( $snapshot['ok'] ) ? 0 : 2 );
