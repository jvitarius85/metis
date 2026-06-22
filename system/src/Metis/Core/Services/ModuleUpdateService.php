<?php
declare(strict_types=1);

namespace Metis\Core\Services;

use Metis\Core\Cache\CacheService;
use Metis\Core\ModulePathRegistry;
use Metis\Core\Version;

final class ModuleUpdateService {
    private const CACHE_KEY = 'updates.modules';
    private const CACHE_TTL = 21600;
    private const SEMVER_PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/';

    public function __construct(
        private readonly GitHubUpdateService $githubUpdates,
        private readonly FileService $files = new FileService(),
        private readonly LoggerService $logger = new LoggerService()
    ) {}

    public function statusSnapshot(): array {
        $cached = CacheService::get( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        return $this->checkForUpdates( false );
    }

    public function checkForUpdates( bool $forceRefresh = false ): array {
        if ( ! $forceRefresh ) {
            $cached = CacheService::get( self::CACHE_KEY );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        } else {
            CacheService::forget( self::CACHE_KEY );
        }

        $checkedAt = gmdate( 'c' );
        $currentMetisVersion = Version::current();
        $installedModules = $this->discoverInstalledModules();
        $registry = $this->githubUpdates->moduleRegistry( $forceRefresh );
        $registryModules = is_array( $registry['modules'] ?? null ) ? (array) $registry['modules'] : [];
        $registryStatus = trim( (string) ( $registry['status'] ?? 'unavailable' ) );
        $registryError = trim( (string) ( $registry['error'] ?? '' ) );

        $results = [];
        $updateCount = 0;
        $blockedCount = 0;

        foreach ( $installedModules as $module ) {
            $moduleId = (string) ( $module['id'] ?? '' );
            $installedVersion = trim( (string) ( $module['version'] ?? '' ) );
            $installedChannel = trim( (string) ( $module['release_channel'] ?? 'stable' ) );
            $installedMinimumMetis = trim( (string) ( $module['minimum_metis'] ?? '' ) );
            $registryEntry = is_array( $registryModules[ $moduleId ] ?? null ) ? (array) $registryModules[ $moduleId ] : [];
            $latestVersion = trim( (string) ( $registryEntry['latest'] ?? '' ) );
            $requiredMetis = trim( (string) ( $registryEntry['minimum_metis'] ?? $installedMinimumMetis ) );
            $releaseChannel = trim( (string) ( $registryEntry['release_channel'] ?? $installedChannel ) );

            $result = [
                'module' => $moduleId,
                'id' => $moduleId,
                'name' => (string) ( $module['name'] ?? $registryEntry['name'] ?? $moduleId ),
                'description' => trim( (string) ( $module['description'] ?? $registryEntry['description'] ?? '' ) ),
                'current' => $installedVersion,
                'latest' => $latestVersion,
                'minimum_metis' => $requiredMetis,
                'installed_minimum_metis' => $installedMinimumMetis,
                'release_channel' => $installedChannel,
                'registry_release_channel' => $releaseChannel,
                'current_metis_version' => $currentMetisVersion,
                'update_available' => false,
                'status' => 'current',
                'reason' => '',
                'download_url' => trim( (string) ( $registryEntry['download_url'] ?? '' ) ),
                'sha256' => trim( (string) ( $registryEntry['sha256'] ?? '' ) ),
                'manifest_path' => (string) ( $module['manifest_path'] ?? '' ),
            ];

            if ( $registryEntry === [] ) {
                $result['status'] = 'registry_missing';
                $result['reason'] = 'Module is not present in the remote registry.';
                $results[] = $result;
                continue;
            }

            if ( ! $this->isSemanticVersion( $installedVersion ) ) {
                $result['status'] = 'invalid_installed_version';
                $result['reason'] = 'Installed module version is not valid semantic versioning.';
                $blockedCount++;
                $results[] = $result;
                continue;
            }

            if ( ! $this->isSemanticVersion( $latestVersion ) ) {
                $result['status'] = 'invalid_registry_version';
                $result['reason'] = 'Registry latest version is not valid semantic versioning.';
                $blockedCount++;
                $results[] = $result;
                continue;
            }

            if ( $releaseChannel !== '' && $installedChannel !== '' && $releaseChannel !== $installedChannel ) {
                $result['status'] = 'release_channel_mismatch';
                $result['reason'] = sprintf( 'Registry channel [%s] does not match installed channel [%s].', $releaseChannel, $installedChannel );
                $blockedCount++;
                $results[] = $result;
                continue;
            }

            if ( $requiredMetis !== '' && version_compare( $currentMetisVersion, $requiredMetis, '<' ) ) {
                $result['status'] = 'requires_newer_metis';
                $result['reason'] = sprintf( 'Requires Metis %s or newer.', $requiredMetis );
                $blockedCount++;
                $results[] = $result;
                continue;
            }

            if ( version_compare( $latestVersion, $installedVersion, '>' ) ) {
                $result['status'] = 'update_available';
                $result['update_available'] = true;
                $updateCount++;
            }

            $results[] = $result;
        }

        usort(
            $results,
            static fn ( array $left, array $right ): int => strcmp( (string) ( $left['name'] ?? '' ), (string) ( $right['name'] ?? '' ) )
        );

        $payload = [
            'checked_at' => $checkedAt,
            'current_metis_version' => $currentMetisVersion,
            'updates_available' => $updateCount > 0,
            'update_count' => $updateCount,
            'blocked_count' => $blockedCount,
            'module_count' => count( $results ),
            'registry_status' => $registryStatus,
            'registry_error' => $registryError,
            'registry_generated_at' => trim( (string) ( $registry['generated_at'] ?? '' ) ),
            'modules' => $results,
        ];

        CacheService::set( self::CACHE_KEY, $payload, self::CACHE_TTL );

        $this->logger->activity( 'module_updates_checked', [
            'registry_status' => $registryStatus,
            'module_count' => count( $results ),
            'update_count' => $updateCount,
            'blocked_count' => $blockedCount,
            'updates_available' => $updateCount > 0,
        ] );

        return $payload;
    }

    public function discoverInstalledModules(): array {
        $manifests = ModulePathRegistry::manifestPaths( 'module' );
        $modules = [];

        foreach ( $manifests as $manifestPath ) {
            $payload = $this->readManifest( $manifestPath );
            if ( $payload === null ) {
                continue;
            }

            $moduleId = metis_key_clean( (string) ( $payload['id'] ?? $payload['slug'] ?? basename( dirname( $manifestPath ) ) ) );
            if ( $moduleId === '' ) {
                $this->logger->warn( 'module_update_manifest_missing_id', [
                    'path' => $manifestPath,
                ] );
                continue;
            }

            $moduleName = trim( (string) ( $payload['label'] ?? $payload['title'] ?? $payload['name'] ?? $moduleId ) );
            if ( $moduleName === '' ) {
                $moduleName = $moduleId;
            }

            $runtimeContract = $this->runtimeContractSnapshot( dirname( $manifestPath ), $payload, $moduleId );

            $modules[] = [
                'id' => $moduleId,
                'name' => $moduleName,
                'description' => trim( (string) ( $payload['description'] ?? '' ) ),
                'version' => trim( (string) ( $payload['version'] ?? '' ) ),
                'minimum_metis' => trim( (string) ( $payload['minimum_metis'] ?? '' ) ),
                'release_channel' => trim( (string) ( $payload['release_channel'] ?? 'stable' ) ),
                'manifest_path' => $manifestPath,
                'entry_path' => (string) ( $runtimeContract['entry_path'] ?? '' ),
                'entry_class' => (string) ( $runtimeContract['entry_class'] ?? '' ),
                'runtime_contract_status' => (string) ( $runtimeContract['status'] ?? 'unknown' ),
                'runtime_contract_note' => (string) ( $runtimeContract['note'] ?? '' ),
            ];
        }

        usort(
            $modules,
            static fn ( array $left, array $right ): int => strcmp( (string) ( $left['id'] ?? '' ), (string) ( $right['id'] ?? '' ) )
        );

        return $modules;
    }

    private function readManifest( string $manifestPath ): ?array {
        try {
            $payload = $this->files->readJson( $manifestPath, [] );
        } catch ( \Throwable $exception ) {
            $this->logger->warn( 'module_update_manifest_read_failed', [
                'path' => $manifestPath,
                'message' => $exception->getMessage(),
            ] );
            return null;
        }

        if ( $payload === [] ) {
            $this->logger->warn( 'module_update_manifest_invalid', [
                'path' => $manifestPath,
            ] );
            return null;
        }

        return $payload;
    }

    private function isSemanticVersion( string $version ): bool {
        return preg_match( self::SEMVER_PATTERN, trim( $version ) ) === 1;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array{entry_path:string,entry_class:string,status:string,note:string}
     */
    private function runtimeContractSnapshot( string $modulePath, array $manifest, string $moduleId ): array {
        $entry = ltrim( (string) ( $manifest['entry'] ?? 'Module.php' ), '/' );
        $entryPath = $entry !== '' ? rtrim( $modulePath, '/\\' ) . '/' . $entry : '';
        $entryClass = trim( (string) ( $manifest['class'] ?? '' ) );
        if ( $entryClass === '' ) {
            $studly = $this->studlyName( (string) ( $manifest['name'] ?? $moduleId ) );
            if ( $studly !== '' ) {
                $entryClass = 'Metis\\Modules\\' . $studly . '\\' . $studly . 'Module';
            }
        }

        if ( $entryPath === '' || ! is_file( $entryPath ) ) {
            return [
                'entry_path' => $entryPath,
                'entry_class' => $entryClass,
                'status' => 'missing_entry',
                'note' => 'Bundle entry file is missing.',
            ];
        }

        $source = (string) @file_get_contents( $entryPath );
        if ( $source === '' ) {
            return [
                'entry_path' => $entryPath,
                'entry_class' => $entryClass,
                'status' => 'unreadable_entry',
                'note' => 'Bundle entry file could not be read.',
            ];
        }

        $classBase = $entryClass !== '' && str_contains( $entryClass, '\\' )
            ? substr( $entryClass, (int) strrpos( $entryClass, '\\' ) + 1 )
            : $entryClass;
        $classPattern = $classBase !== ''
            ? '/\b(?:final\s+|abstract\s+)?class\s+' . preg_quote( $classBase, '/' ) . '\b/'
            : '';

        if ( $classPattern !== '' && preg_match( $classPattern, $source ) === 1 ) {
            return [
                'entry_path' => $entryPath,
                'entry_class' => $entryClass,
                'status' => 'self_contained_entry',
                'note' => 'Bundle entry file defines its own module class.',
            ];
        }

        if ( str_contains( $source, 'placeholder' ) || str_contains( $source, 'Runtime boot/registration is handled by src/Metis services' ) ) {
            return [
                'entry_path' => $entryPath,
                'entry_class' => $entryClass,
                'status' => 'source_backed_entry',
                'note' => 'Bundle entry file is still a placeholder and depends on source-side module code.',
            ];
        }

        return [
            'entry_path' => $entryPath,
            'entry_class' => $entryClass,
            'status' => 'unknown_entry_contract',
            'note' => 'Bundle entry file exists but does not clearly declare the expected module class.',
        ];
    }

    private function studlyName( string $value ): string {
        $parts = preg_split( '/[^a-z0-9]+/i', strtolower( $value ) ) ?: [];

        return implode(
            '',
            array_map(
                static fn ( string $part ): string => ucfirst( $part ),
                array_values(
                    array_filter(
                        $parts,
                        static fn ( string $part ): bool => $part !== ''
                    )
                )
            )
        );
    }
}
