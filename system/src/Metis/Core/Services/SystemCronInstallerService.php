<?php
declare(strict_types=1);

namespace Metis\Core\Services;

final class SystemCronInstallerService {
    private const MANAGED_BLOCK_START = '# >>> METIS SYSTEM CRON >>>';
    private const MANAGED_BLOCK_END = '# <<< METIS SYSTEM CRON <<<';
    private const DEFAULT_SCHEDULE = '* * * * *';

    public function __construct(
        private readonly FileService $files = new FileService(),
        private readonly ProcessRunner $runner = new ProcessRunner(),
        private readonly ?string $phpBinary = null,
        private readonly ?string $runnerPath = null,
        private readonly string $schedule = self::DEFAULT_SCHEDULE
    ) {}

    public function status(): array {
        $crontab = $this->readCurrentCrontab();
        $existing = $crontab['contents'];
        $installed = str_contains( $existing, self::MANAGED_BLOCK_START ) && str_contains( $existing, $this->managedEntryLine() );

        return [
            'supported' => $crontab['supported'],
            'installed' => $installed,
            'schedule' => $this->schedule(),
            'command' => $this->managedEntryLine(),
            'managed_block' => $this->managedBlock(),
            'runner_path' => $this->resolvedRunnerPath(),
            'php_binary' => $this->resolvedPhpBinary(),
            'raw_crontab' => $existing,
        ];
    }

    public function install(): array {
        $current = $this->readCurrentCrontab();
        if ( ! $current['supported'] ) {
            throw new \RuntimeException( 'System crontab is not available for this server user.' );
        }

        $updated = $this->mergeManagedBlock( $current['contents'] );
        $this->applyCrontab( $updated );

        return $this->status();
    }

    public function uninstall(): array {
        $current = $this->readCurrentCrontab();
        if ( ! $current['supported'] ) {
            throw new \RuntimeException( 'System crontab is not available for this server user.' );
        }

        $updated = $this->removeManagedBlock( $current['contents'] );
        $this->applyCrontab( $updated );

        return $this->status();
    }

    public function managedBlock(): string {
        return self::MANAGED_BLOCK_START . PHP_EOL
            . '# Managed by Metis Settings > Scheduler. Changes inside this block will be replaced.' . PHP_EOL
            . $this->managedEntryLine() . PHP_EOL
            . self::MANAGED_BLOCK_END;
    }

    public function managedEntryLine(): string {
        return sprintf(
            '%s %s %s --trigger=server_crontab >/dev/null 2>&1',
            $this->schedule(),
            $this->resolvedPhpBinary(),
            $this->resolvedRunnerPath()
        );
    }

    public function mergeManagedBlock( string $existing ): string {
        $stripped = $this->removeManagedBlock( $existing );
        $stripped = trim( preg_replace( "/\n{3,}/", "\n\n", str_replace( "\r", '', $stripped ) ) ?? $stripped );

        if ( $stripped === '' ) {
            return $this->managedBlock() . PHP_EOL;
        }

        return $stripped . PHP_EOL . PHP_EOL . $this->managedBlock() . PHP_EOL;
    }

    public function removeManagedBlock( string $existing ): string {
        $normalized = str_replace( "\r", '', $existing );
        $pattern = '/' . preg_quote( self::MANAGED_BLOCK_START, '/' ) . '\n.*?' . preg_quote( self::MANAGED_BLOCK_END, '/' ) . '\n?/s';
        $cleaned = preg_replace( $pattern, '', $normalized );
        if ( ! is_string( $cleaned ) ) {
            return trim( $normalized ) . PHP_EOL;
        }

        $cleaned = trim( preg_replace( "/\n{3,}/", "\n\n", $cleaned ) ?? $cleaned );
        return $cleaned === '' ? '' : $cleaned . PHP_EOL;
    }

    private function readCurrentCrontab(): array {
        $result = $this->runProcess( [ 'crontab', '-l' ], 'system.cron.installer.read', 'system_cron_installer_read' );
        $stderr = strtolower( trim( (string) $result['stderr'] ) );

        if ( (int) $result['exit_code'] === 0 ) {
            return [
                'supported' => true,
                'contents' => (string) $result['stdout'],
            ];
        }

        if ( str_contains( $stderr, 'no crontab for' ) ) {
            return [
                'supported' => true,
                'contents' => '',
            ];
        }

        throw new \RuntimeException( trim( (string) $result['stderr'] ) !== '' ? trim( (string) $result['stderr'] ) : 'Unable to read the current crontab.' );
    }

    private function applyCrontab( string $contents ): void {
        $tempPath = 'storage/runtime/system-cron/install.crontab';

        try {
            $this->files->write( $tempPath, $contents );
            $result = $this->runProcess(
                [ 'crontab', $this->files->rootPath( $tempPath ) ],
                'system.cron.installer.apply',
                'system_cron_installer_apply'
            );
        } finally {
            try {
                if ( $this->files->exists( $this->files->rootPath( $tempPath ) ) ) {
                    $this->files->remove( $tempPath );
                }
            } catch ( \Throwable ) {
            }
        }

        if ( (int) $result['exit_code'] !== 0 ) {
            $stderr = trim( (string) $result['stderr'] );
            throw new \RuntimeException( $stderr !== '' ? $stderr : 'Unable to apply the updated crontab.' );
        }
    }

    private function runProcess( array $command, string $operation, string $event ): array {
        return $this->runner->run( $command, \METIS_PATH, [
            'security_context' => [
                'operation' => $operation,
                'source' => self::class,
            ],
            'audit_context' => [
                'event' => $event,
                'resource' => 'system_cron',
            ],
            'permission_context' => [
                'module' => 'settings',
                'permission' => 'system.cron.manage',
                'enforce' => false,
                'preauthorized' => true,
                'authorization_source' => 'system_cron_installer_service',
            ],
        ], 30 );
    }

    private function resolvedPhpBinary(): string {
        $binary = trim( (string) ( $this->phpBinary ?? \PHP_BINARY ) );
        return $binary !== '' ? $binary : 'php';
    }

    private function resolvedRunnerPath(): string {
        $configured = trim( (string) ( $this->runnerPath ?? '' ) );
        if ( $configured !== '' ) {
            return $configured;
        }

        $path = rtrim( (string) \METIS_PATH, '/\\' ) . '/system/tools/run_system_cron.php';
        $real = \realpath( $path );
        return is_string( $real ) && $real !== '' ? $real : $path;
    }

    private function schedule(): string {
        $schedule = trim( $this->schedule );
        return $schedule !== '' ? $schedule : self::DEFAULT_SCHEDULE;
    }
}
