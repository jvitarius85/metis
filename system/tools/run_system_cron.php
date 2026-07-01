<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/Metis/Core/Runtime/CliToolGuard.php';
metis_require_cli_tool();

define( 'METIS_STANDALONE', true );
define( 'METIS_PATH', dirname( __DIR__, 2 ) . '/' );

require_once dirname( __DIR__ ) . '/src/Metis/Core/CoreBootstrap.php';
metis_core_bootstrap( 'standalone_bootstrap' );
metis_standalone_boot();

$trigger = 'server_crontab';
foreach ( $argv as $arg ) {
    if ( str_starts_with( (string) $arg, '--trigger=' ) ) {
        $trigger = trim( substr( (string) $arg, 10 ) ) ?: $trigger;
    }
}

$requestId = function_exists( 'metis_audit_request_id' ) ? (string) metis_audit_request_id() : bin2hex( random_bytes( 8 ) );
$queued = Metis_Cron_Manager::queue_due_tasks( [], false, $trigger, $requestId );
$drain = Metis_Cron_Manager::drain_job_queue( 'system_cron_server' );

$ok = empty( $queued['summary']['failed'] ) && (int) ( $drain['failed'] ?? 0 ) === 0;
$payload = [
    'ok' => $ok,
    'trigger' => $trigger,
    'request_id' => $requestId,
    'queued' => $queued,
    'drain' => $drain,
];

fwrite( $ok ? STDOUT : STDERR, (string) ( json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ?: '{}' ) . PHP_EOL );
exit( $ok ? 0 : 1 );
