<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

define( 'METIS_STANDALONE', true );
define( 'METIS_PATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/src/Metis/Core/Services/FileService.php';
require_once dirname( __DIR__ ) . '/src/Metis/Core/Services/ProcessRunner.php';
require_once dirname( __DIR__ ) . '/src/Metis/Core/Services/SystemCronInstallerService.php';

$service = new \Metis\Core\Services\SystemCronInstallerService(
    new \Metis\Core\Services\FileService(),
    new \Metis\Core\Services\ProcessRunner(),
    '/usr/bin/php8.4',
    '/var/www/metis/system/tools/run_system_cron.php'
);

$failures = [];
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$entry = $service->managedEntryLine();
$assert(
    $entry === '* * * * * /usr/bin/php8.4 /var/www/metis/system/tools/run_system_cron.php --trigger=server_crontab >/dev/null 2>&1',
    'Managed cron entry must target the internal CLI runner.'
);

$existing = "# existing job\n0 0 * * * /usr/bin/true\n";
$merged = $service->mergeManagedBlock( $existing );
$assert( str_contains( $merged, '# existing job' ), 'Existing non-Metis cron lines must be preserved.' );
$assert( substr_count( $merged, '# >>> METIS SYSTEM CRON >>>' ) === 1, 'Managed block must be added exactly once.' );
$assert( str_contains( $merged, $entry ), 'Managed block must contain the expected cron entry.' );

$mergedTwice = $service->mergeManagedBlock( $merged );
$assert( substr_count( $mergedTwice, '# >>> METIS SYSTEM CRON >>>' ) === 1, 'Merging twice must not duplicate the managed block.' );

$removed = $service->removeManagedBlock( $mergedTwice );
$assert( ! str_contains( $removed, '# >>> METIS SYSTEM CRON >>>' ), 'Removing the managed block must strip the start marker.' );
$assert( str_contains( $removed, '# existing job' ), 'Removing the managed block must leave unrelated cron lines untouched.' );

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "System cron installer service checks passed.\n" );
