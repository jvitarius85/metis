<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This tool must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__, 2 );
require_once $root . '/system/src/Metis/Core/CoreBootstrap.php';
require_once $root . '/system/src/Metis/Core/Runtime/SanitizationRuntime.php';
require_once $root . '/system/src/Metis/Core/Modules/ModuleValidator.php';

use Metis\Core\Modules\ModuleValidator;

$target = isset( $argv[1] ) ? trim( (string) $argv[1] ) : '';
if ( $target === '' ) {
    fwrite( STDERR, "Usage: php system/tools/module_bundle_audit.php /absolute/or/relative/module-dir\n" );
    exit( 1 );
}

$targetPath = $target;
if ( ! str_starts_with( $targetPath, '/' ) ) {
    $targetPath = $root . '/' . ltrim( $targetPath, '/' );
}
$targetPath = rtrim( str_replace( '\\', '/', $targetPath ), '/' );

if ( ! is_dir( $targetPath ) ) {
    fwrite( STDERR, "Module directory not found: {$targetPath}\n" );
    exit( 1 );
}

$validator = new ModuleValidator();

if ( is_file( $targetPath . '/module.json' ) ) {
    $result = audit_module_bundle( $targetPath, $validator );
    fwrite( STDOUT, json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
    exit( $result['ok'] ? 0 : 2 );
}

$modules = [];
foreach ( glob( $targetPath . '/*', GLOB_ONLYDIR ) ?: [] as $childDir ) {
    if ( is_file( $childDir . '/module.json' ) ) {
        $modules[] = audit_module_bundle( rtrim( str_replace( '\\', '/', $childDir ), '/' ), $validator );
    }
}

if ( $modules === [] ) {
    fwrite( STDERR, "No module bundles found under: {$targetPath}\n" );
    exit( 1 );
}

$summary = [
    'ok' => true,
    'module_count' => count( $modules ),
    'passed' => 0,
    'failed' => 0,
    'entry_statuses' => [],
    'runtime_coupling_statuses' => [],
];

foreach ( $modules as $module ) {
    $status = (string) ( $module['entry_status'] ?? 'unknown_entry_contract' );
    $summary['entry_statuses'][ $status ] = (int) ( $summary['entry_statuses'][ $status ] ?? 0 ) + 1;
    $coupling = (string) ( $module['runtime_coupling_status'] ?? 'unknown_runtime_coupling' );
    $summary['runtime_coupling_statuses'][ $coupling ] = (int) ( $summary['runtime_coupling_statuses'][ $coupling ] ?? 0 ) + 1;

    if ( ! empty( $module['ok'] ) ) {
        $summary['passed']++;
        continue;
    }

    $summary['ok'] = false;
    $summary['failed']++;
}

$result = [
    'ok' => $summary['ok'],
    'root_path' => $targetPath,
    'summary' => $summary,
    'modules' => $modules,
];

fwrite( STDOUT, json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );

exit( $summary['ok'] ? 0 : 2 );

/**
 * @return array<string, mixed>
 */
function audit_module_bundle( string $targetPath, ModuleValidator $validator ): array {
    $manifestPath = $targetPath . '/module.json';
    $manifestRaw = (string) @file_get_contents( $manifestPath );
    $manifest = json_decode( $manifestRaw, true );
    if ( ! is_array( $manifest ) ) {
        return [
            'ok' => false,
            'module_path' => $targetPath,
            'manifest_path' => $manifestPath,
            'slug' => basename( $targetPath ),
            'entry_path' => '',
            'entry_class' => '',
            'entry_status' => 'invalid_manifest',
            'entry_note' => 'module.json is not valid JSON.',
            'services' => [],
            'errors' => [ 'module.json is not valid JSON.' ],
        ];
    }

    $fallbackSlug = basename( $targetPath );
    $errors = [];

    try {
        $validated = $validator->validateModule( $targetPath, $manifest, $fallbackSlug );
    } catch ( Throwable $throwable ) {
        $errors[] = $throwable->getMessage();
        $validated = $manifest;
    }

    $entry = ltrim( (string) ( $validated['entry'] ?? 'Module.php' ), '/' );
    $entryPath = $entry !== '' ? $targetPath . '/' . $entry : '';
    $entryClass = trim( (string) ( $validated['class'] ?? '' ) );
    if ( $entryClass === '' ) {
        $baseName = trim( (string) ( $validated['name'] ?? $validated['slug'] ?? $fallbackSlug ) );
        $parts = preg_split( '/[^a-z0-9]+/i', strtolower( $baseName ) ) ?: [];
        $studly = implode(
            '',
            array_map(
                static fn ( string $part ): string => ucfirst( $part ),
                array_values( array_filter( $parts, static fn ( string $part ): bool => $part !== '' ) )
            )
        );
        if ( $studly !== '' ) {
            $entryClass = 'Metis\\Modules\\' . $studly . '\\' . $studly . 'Module';
        }
    }

    $entryStatus = 'missing_entry';
    $entryNote = 'Bundle entry file is missing.';
    if ( $entryPath !== '' && is_file( $entryPath ) ) {
        $entrySource = (string) @file_get_contents( $entryPath );
        if ( $entrySource === '' ) {
            $entryStatus = 'unreadable_entry';
            $entryNote = 'Bundle entry file could not be read.';
        } else {
            $classBase = $entryClass !== '' && str_contains( $entryClass, '\\' )
                ? substr( $entryClass, (int) strrpos( $entryClass, '\\' ) + 1 )
                : $entryClass;
            $classPattern = $classBase !== ''
                ? '/\b(?:final\s+|abstract\s+)?class\s+' . preg_quote( $classBase, '/' ) . '\b/'
                : '';

            if ( $classPattern !== '' && preg_match( $classPattern, $entrySource ) === 1 ) {
                $entryStatus = 'self_contained_entry';
                $entryNote = 'Bundle entry file defines the expected module class.';
            } elseif ( str_contains( $entrySource, 'placeholder' ) || str_contains( $entrySource, 'Runtime boot/registration is handled by src/Metis services' ) ) {
                $entryStatus = 'source_backed_entry';
                $entryNote = 'Bundle entry file is still a placeholder and depends on source-side code.';
            } else {
                $entryStatus = 'unknown_entry_contract';
                $entryNote = 'Bundle entry file exists but does not clearly declare the expected module class.';
            }
        }
    }

    $serviceFiles = [];
    foreach ( (array) ( $validated['services'] ?? [] ) as $serviceFile ) {
        $serviceFile = ltrim( (string) $serviceFile, '/' );
        if ( $serviceFile !== '' ) {
            $serviceFiles[] = $serviceFile;
        }
    }

    [ $runtimeCouplingStatus, $runtimeCouplingNote, $runtimeCouplingRefs ] = audit_runtime_coupling(
        $targetPath,
        $entryClass
    );

    return [
        'ok' => $errors === [] && $entryStatus === 'self_contained_entry',
        'module_path' => $targetPath,
        'manifest_path' => $manifestPath,
        'slug' => (string) ( $validated['slug'] ?? $fallbackSlug ),
        'entry_path' => $entryPath,
        'entry_class' => $entryClass,
        'entry_status' => $entryStatus,
        'entry_note' => $entryNote,
        'runtime_coupling_status' => $runtimeCouplingStatus,
        'runtime_coupling_note' => $runtimeCouplingNote,
        'runtime_coupling_refs' => $runtimeCouplingRefs,
        'services' => $serviceFiles,
        'errors' => $errors,
    ];
}

/**
 * @return array{0:string,1:string,2:array<int,array<string,string>>}
 */
function audit_runtime_coupling( string $targetPath, string $entryClass ): array {
    $moduleNamespace = '';
    if (
        preg_match(
            '/^Metis\\\\Modules\\\\([^\\\\]+)\\\\[^\\\\]+$/',
            $entryClass,
            $matches
        ) === 1
    ) {
        $moduleNamespace = (string) ( $matches[1] ?? '' );
    }
    $bundleRoot = dirname( $targetPath );

    $allowedRefs = [];
    $blockingRefs = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $targetPath, FilesystemIterator::SKIP_DOTS )
    );

    foreach ( $iterator as $file ) {
        if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
            continue;
        }

        $path = str_replace( '\\', '/', $file->getPathname() );
        if ( ! str_ends_with( $path, '.php' ) ) {
            continue;
        }

        if ( preg_match( '#/(views|templates)/#', $path ) === 1 ) {
            continue;
        }

        $source = (string) @file_get_contents( $path );
        if ( $source === '' ) {
            continue;
        }

        if ( preg_match_all( '/Metis\\\\Modules\\\\([^\\\\\s\'";()]+)(?:\\\\[^\s\'";()]*)?/', $source, $moduleMatches ) > 0 ) {
            foreach ( $moduleMatches[0] as $index => $reference ) {
                $segment = (string) ( $moduleMatches[1][ $index ] ?? '' );
                if ( $segment !== '' && $segment === $moduleNamespace ) {
                    continue;
                }

                $referenceSlug = '';
                if ( class_exists( '\Metis\Core\ModulePathRegistry' ) ) {
                    $referenceSlug = (string) \Metis\Core\ModulePathRegistry::slugForNamespaceSegment( $segment );
                }
                if (
                    $referenceSlug !== ''
                    && is_dir( rtrim( $bundleRoot, '/\\' ) . '/' . $referenceSlug )
                ) {
                    continue;
                }

                $isAllowedCoreRuntimeDependency = false;
                if ( class_exists( '\Metis\Core\ModulePathRegistry' ) ) {
                    $isAllowedCoreRuntimeDependency =
                        ( $referenceSlug !== '' && \Metis\Core\ModulePathRegistry::isCoreServiceSlug( $referenceSlug ) )
                        || $referenceSlug === 'communications_inbound';
                }

                if ( $isAllowedCoreRuntimeDependency ) {
                    $allowedRefs[] = [
                        'file' => $path,
                        'type' => 'core_runtime_module_namespace',
                        'reference' => (string) $reference,
                    ];
                    continue;
                }

                $blockingRefs[] = [
                    'file' => $path,
                    'type' => 'module_namespace',
                    'reference' => (string) $reference,
                ];
            }
        }

        if ( preg_match_all( '/Metis\\\\Core\\\\BuiltInServices\\\\[^\s\'";()]*/', $source, $coreMatches ) > 0 ) {
            foreach ( $coreMatches[0] as $reference ) {
                $allowedRefs[] = [
                    'file' => $path,
                    'type' => 'core_service_namespace',
                    'reference' => (string) $reference,
                ];
            }
        }

        if ( str_contains( $source, 'METIS_SRC_PATH' ) ) {
            $blockingRefs[] = [
                'file' => $path,
                'type' => 'source_path_reference',
                'reference' => 'METIS_SRC_PATH',
            ];
        }
    }

    if ( $blockingRefs === [] && $allowedRefs === [] ) {
        return [
            'bundle_only',
            'Bundle runtime files do not reference source-side module namespaces or source-path includes.',
            [],
        ];
    }

    if ( $blockingRefs === [] ) {
        return [
            'core_runtime_dependency',
            'Bundle runtime depends only on allowed core runtime services.',
            array_slice( $allowedRefs, 0, 25 ),
        ];
    }

    return [
        'source_coupled',
        'Bundle runtime still references source-side namespaces or source-path includes.',
        array_slice( array_merge( $blockingRefs, $allowedRefs ), 0, 25 ),
    ];
}
