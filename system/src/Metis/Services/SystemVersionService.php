<?php
declare(strict_types=1);

namespace Metis\Services;

use Metis\Core\Version;
use Metis\Core\Application;
use Metis\Core\ModulePathRegistry;

final class SystemVersionService {
    public function current(): array {
        $modules = [];
        $moduleDetails = [];
        $moduleFailures = [];
        $coreServices = [];
        $coreServiceDetails = [];
        $coreServiceFailures = [];
        $installedModuleMap = [];

        if ( Application::has_service( 'module_updates' ) ) {
            foreach ( (array) Application::service( 'module_updates' )->discoverInstalledModules() as $installedModule ) {
                if ( ! is_array( $installedModule ) ) {
                    continue;
                }

                $slug = \metis_key_clean( (string) ( $installedModule['id'] ?? '' ) );
                if ( $slug === '' ) {
                    continue;
                }

                $version = trim( (string) ( $installedModule['version'] ?? '' ) );
                if ( $version === '' ) {
                    $version = 'unknown';
                }

                $runtimeStatus = trim( (string) ( $installedModule['runtime_contract_status'] ?? '' ) );
                $runtimeNote = trim( (string) ( $installedModule['runtime_contract_note'] ?? '' ) );
                $hasRuntimeWarning = in_array(
                    $runtimeStatus,
                    [ 'missing_entry', 'source_backed_entry', 'unreadable_entry', 'unknown_entry_contract' ],
                    true
                );

                $installedModuleMap[ $slug ] = true;
                $modules[ $slug ] = $version;
                $moduleDetails[ $slug ] = [
                    'slug' => $slug,
                    'version' => $version,
                    'status' => $hasRuntimeWarning ? 'failed' : 'loaded',
                    'reason' => $hasRuntimeWarning ? $runtimeNote : '',
                    'package_type' => 'module',
                ];

                if ( $hasRuntimeWarning ) {
                    $moduleFailures[ $slug ] = [
                        'slug' => $slug,
                        'reason' => $runtimeNote,
                        'path' => (string) ( $installedModule['manifest_path'] ?? '' ),
                        'package_type' => 'module',
                    ];
                }
            }
        }

        if ( Application::has_service( 'modules' ) ) {
            $moduleService = Application::service( 'modules' );

            foreach ( $moduleService->all() as $slug => $module ) {
                $version = (string) ( $module['config']['version'] ?? '0.0.0' );
                $packageType = (string) ( $module['package_type'] ?? $module['config']['package_type'] ?? 'module' );
                $targetMap = $packageType === 'core_service' ? 'core' : 'module';

                $detail = [
                    'slug' => (string) $slug,
                    'version' => $version,
                    'status' => 'loaded',
                    'reason' => '',
                    'package_type' => $packageType,
                ];

                if ( $targetMap === 'core' ) {
                    $coreServices[ $slug ] = $version;
                    $coreServiceDetails[ $slug ] = $detail;
                } else {
                    if ( isset( $installedModuleMap[ (string) $slug ] ) ) {
                        continue;
                    }
                    $modules[ $slug ] = $version;
                    $moduleDetails[ $slug ] = $detail;
                }
            }

            if ( method_exists( $moduleService, 'bootFailures' ) ) {
                foreach ( (array) $moduleService->bootFailures() as $failure ) {
                    if ( ! is_array( $failure ) ) {
                        continue;
                    }

                    $slug = \metis_key_clean( (string) ( $failure['module'] ?? '' ) );
                    if ( $slug === '' ) {
                        continue;
                    }

                    $reason = trim( (string) ( $failure['reason'] ?? 'Module failed compliance validation.' ) );
                    $path = (string) ( $failure['path'] ?? '' );
                    $version = $this->manifestVersionFromFailurePath( $path );
                    $packageType = $this->packageTypeForFailure( $slug, $path );
                    if ( $version === '' ) {
                        $version = $packageType === 'core_service'
                            ? (string) ( $coreServices[ $slug ] ?? 'unknown' )
                            : (string) ( $modules[ $slug ] ?? 'unknown' );
                    }

                    $failureDetail = [
                        'slug' => $slug,
                        'reason' => $reason,
                        'path' => $path,
                        'package_type' => $packageType,
                    ];

                    if ( $packageType === 'core_service' ) {
                        $coreServiceFailures[ $slug ] = $failureDetail;
                        if ( ! isset( $coreServices[ $slug ] ) ) {
                            $coreServices[ $slug ] = $version;
                        }

                        $coreServiceDetails[ $slug ] = [
                            'slug' => $slug,
                            'version' => $version,
                            'status' => 'failed',
                            'reason' => $reason,
                            'package_type' => $packageType,
                        ];
                        continue;
                    }

                    if ( isset( $installedModuleMap[ $slug ] ) ) {
                        continue;
                    }

                    $moduleFailures[ $slug ] = $failureDetail;

                    if ( ! isset( $modules[ $slug ] ) ) {
                        $modules[ $slug ] = $version;
                    }

                    $moduleDetails[ $slug ] = [
                        'slug' => $slug,
                        'version' => $version,
                        'status' => 'failed',
                        'reason' => $reason,
                        'package_type' => $packageType,
                    ];
                }
            }
        }

        ksort( $modules );
        ksort( $moduleDetails );
        ksort( $moduleFailures );
        ksort( $coreServices );
        ksort( $coreServiceDetails );
        ksort( $coreServiceFailures );

        $buildSource = Version::sourcePath();
        $buildStamp = @filemtime( $buildSource );

        return [
            'metis_version' => Version::current(),
            'build' => $buildStamp === false ? gmdate( 'Y.m.d' ) : gmdate( 'Y.m.d', $buildStamp ),
            'modules' => $modules,
            'module_details' => array_values( $moduleDetails ),
            'module_failures' => array_values( $moduleFailures ),
            'core_services' => $coreServices,
            'core_service_details' => array_values( $coreServiceDetails ),
            'core_service_failures' => array_values( $coreServiceFailures ),
        ];
    }

    private function manifestVersionFromFailurePath( string $path ): string {
        $path = trim( $path );
        if ( $path === '' ) {
            return '';
        }

        $manifestPath = $path;
        if ( is_dir( $manifestPath ) ) {
            $manifestPath = rtrim( $manifestPath, '/\\' ) . '/module.json';
        } elseif ( substr( $manifestPath, -12 ) !== '/module.json' && substr( $manifestPath, -11 ) !== 'module.json' ) {
            $manifestPath = dirname( $manifestPath ) . '/module.json';
        }

        if ( ! is_file( $manifestPath ) ) {
            return '';
        }

        $raw = (string) @file_get_contents( $manifestPath );
        $payload = json_decode( $raw, true );
        if ( ! is_array( $payload ) ) {
            return '';
        }

        return trim( (string) ( $payload['version'] ?? '' ) );
    }

    private function packageTypeForFailure( string $slug, string $path ): string {
        if ( ModulePathRegistry::isCoreServiceSlug( $slug ) ) {
            return 'core_service';
        }

        $normalizedPath = str_replace( '\\', '/', trim( $path ) );
        $coreServiceRoot = str_replace( '\\', '/', ModulePathRegistry::coreServiceRootPath() );
        if ( $normalizedPath !== '' && $coreServiceRoot !== '' && str_contains( $normalizedPath, trim( $coreServiceRoot, '/' ) ) ) {
            return 'core_service';
        }

        return 'module';
    }
}
