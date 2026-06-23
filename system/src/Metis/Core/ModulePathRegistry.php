<?php
declare(strict_types=1);

namespace Metis\Core;

final class ModulePathRegistry {
    private const MODULE_NAMESPACE_SEGMENTS = [
        'board' => 'Board',
        'calendar' => 'Calendar',
        'communications_inbound' => 'CommunicationsInbound',
        'contacts' => 'Contacts',
        'donations' => 'Donations',
        'drive' => 'Drive',
        'finance' => 'Finance',
        'forms' => 'Forms',
        'forms_import' => 'FormsImport',
        'grandys_stash' => 'GrandyStash',
        'grandystash' => 'GrandyStash',
        'help' => 'Help',
        'hermes' => 'Hermes',
        'import' => 'Import',
        'media' => 'Media',
        'modules' => 'Modules',
        'newsletter' => 'Newsletter',
        'people' => 'People',
        'portal' => 'Portal',
        'profile' => 'Profile',
        'resources' => 'Resources',
        'settings' => 'Settings',
        'testimonies' => 'Testimonies',
        'website' => 'Website',
    ];
    private const CORE_SERVICE_SLUGS = [ 'help', 'hermes', 'modules', 'people', 'portal', 'profile', 'settings' ];
    private const STORE_MANAGED_MODULE_SLUGS = [
        'board',
        'calendar',
        'contacts',
        'donations',
        'drive',
        'finance',
        'forms',
        'grandys_stash',
        'import',
        'media',
        'newsletter',
        'resources',
        'testimonies',
        'website',
    ];
    private const TRANSITIONAL_SOURCE_MODULES = [
        'communicationsinbound' => [
            'status' => 'core_service_runtime',
            'target' => 'communications_inbound_runtime',
            'notes' => 'Inbound mail processing should remain a core runtime integration surface that store modules can call into.',
        ],
        'formsimport' => [
            'status' => 'fold_into_store_module',
            'target' => 'forms',
            'notes' => 'Forms import should migrate into the Forms module package instead of shipping as a standalone source-side module.',
        ],
        'help' => [
            'status' => 'built_in_service',
            'target' => 'help',
            'notes' => 'Help remains a built-in core service.',
        ],
        'hermes' => [
            'status' => 'built_in_service',
            'target' => 'hermes',
            'notes' => 'Hermes remains a built-in core service.',
        ],
        'modules' => [
            'status' => 'built_in_service',
            'target' => 'modules',
            'notes' => 'Module administration UI remains a built-in core service.',
        ],
        'people' => [
            'status' => 'built_in_service',
            'target' => 'people',
            'notes' => 'People remains a built-in core service.',
        ],
        'portal' => [
            'status' => 'built_in_service',
            'target' => 'portal',
            'notes' => 'Portal remains a built-in core service.',
        ],
        'profile' => [
            'status' => 'built_in_service',
            'target' => 'profile',
            'notes' => 'Profile remains a built-in core service.',
        ],
        'settings' => [
            'status' => 'built_in_service',
            'target' => 'settings',
            'notes' => 'Settings remains a built-in core service.',
        ],
    ];
    private const SOURCE_MODULE_INVENTORY = [
        'board' => [
            'status' => 'legacy_store_module',
            'target' => 'board',
            'notes' => 'Board still ships source-side PHP and must migrate fully into the runtime bundle before the source directory can be removed.',
        ],
        'calendar' => [
            'status' => 'legacy_store_module',
            'target' => 'calendar',
            'notes' => 'Calendar still ships source-side PHP and must migrate fully into the runtime bundle before the source directory can be removed.',
        ],
        'communicationsinbound' => [
            'status' => 'core_service_runtime',
            'target' => 'communications_inbound_runtime',
            'notes' => 'Inbound mail processing should remain a core runtime integration surface that store modules can call into.',
        ],
        'contacts' => [
            'status' => 'legacy_store_module',
            'target' => 'contacts',
            'notes' => 'Contacts still ships source-side PHP and must migrate fully into the runtime bundle before the source directory can be removed.',
        ],
        'donations' => [
            'status' => 'legacy_store_module',
            'target' => 'donations',
            'notes' => 'Donations still ships source-side PHP and must migrate fully into the runtime bundle before the source directory can be removed.',
        ],
        'drive' => [
            'status' => 'legacy_store_module',
            'target' => 'drive',
            'notes' => 'Drive still ships source-side PHP and must migrate fully into the runtime bundle before the source directory can be removed.',
        ],
        'finance' => [
            'status' => 'legacy_store_module',
            'target' => 'finance',
            'notes' => 'Finance still ships source-side PHP and must migrate fully into the runtime bundle before the source directory can be removed.',
        ],
        'forms' => [
            'status' => 'legacy_store_module',
            'target' => 'forms',
            'notes' => 'Forms still ships source-side PHP and must migrate fully into the runtime bundle before the source directory can be removed.',
        ],
        'formsimport' => [
            'status' => 'fold_into_store_module',
            'target' => 'forms',
            'notes' => 'Forms import should migrate into the Forms module package instead of shipping as a standalone source-side module.',
        ],
        'grandystash' => [
            'status' => 'legacy_store_module',
            'target' => 'grandys_stash',
            'notes' => 'Grandy Stash still ships source-side PHP under its legacy directory name and must migrate fully into the runtime bundle before the source directory can be removed.',
        ],
        'help' => [
            'status' => 'built_in_service',
            'target' => 'help',
            'notes' => 'Help remains a built-in core service.',
        ],
        'hermes' => [
            'status' => 'built_in_service',
            'target' => 'hermes',
            'notes' => 'Hermes remains a built-in core service.',
        ],
        'import' => [
            'status' => 'legacy_store_module',
            'target' => 'import',
            'notes' => 'Import still ships source-side PHP and must migrate fully into the runtime bundle before the source directory can be removed.',
        ],
        'media' => [
            'status' => 'legacy_store_module',
            'target' => 'media',
            'notes' => 'Media still ships source-side PHP and must migrate fully into the runtime bundle before the source directory can be removed.',
        ],
        'modules' => [
            'status' => 'built_in_service',
            'target' => 'modules',
            'notes' => 'Module administration UI remains a built-in core service.',
        ],
        'newsletter' => [
            'status' => 'legacy_store_module',
            'target' => 'newsletter',
            'notes' => 'Newsletter still ships source-side PHP and must migrate fully into the runtime bundle before the source directory can be removed.',
        ],
        'people' => [
            'status' => 'built_in_service',
            'target' => 'people',
            'notes' => 'People remains a built-in core service.',
        ],
        'portal' => [
            'status' => 'built_in_service',
            'target' => 'portal',
            'notes' => 'Portal remains a built-in core service.',
        ],
        'profile' => [
            'status' => 'built_in_service',
            'target' => 'profile',
            'notes' => 'Profile remains a built-in core service.',
        ],
        'resources' => [
            'status' => 'legacy_store_module',
            'target' => 'resources',
            'notes' => 'Resources still ships source-side PHP and must migrate fully into the runtime bundle before the source directory can be removed.',
        ],
        'settings' => [
            'status' => 'built_in_service',
            'target' => 'settings',
            'notes' => 'Settings remains a built-in core service.',
        ],
        'testimonies' => [
            'status' => 'legacy_store_module',
            'target' => 'testimonies',
            'notes' => 'Testimonies still ships source-side PHP and must migrate fully into the runtime bundle before the source directory can be removed.',
        ],
        'website' => [
            'status' => 'legacy_store_module',
            'target' => 'website',
            'notes' => 'Website still ships source-side PHP and must migrate fully into the runtime bundle before the source directory can be removed.',
        ],
    ];
    private const LEGACY_STORE_MANAGED_RUNTIME_BRIDGES = [
        'src/Metis/Core/Runtime/ModuleSchemaRuntimeBridge.php' => [
            'board',
            'calendar',
            'contacts',
            'finance',
            'forms',
            'import',
            'newsletter',
            'website',
        ],
    ];
    private const LEGACY_STORE_MANAGED_DIRECT_RUNTIME_BRIDGES = [];
    private const APPROVED_CORE_TO_STORE_MODULE_DEPENDENCIES = [
        'src/Metis/Core/BuiltInServices/people/PersonProfileService.php' => [ 'website' ],
        'src/Metis/Core/TransitionModules/forms_import/SchemaManager.php' => [ 'forms' ],
    ];

    public static function coreServiceSlugs(): array {
        return self::CORE_SERVICE_SLUGS;
    }

    public static function isCoreServiceSlug( string $slug ): bool {
        return in_array( \metis_key_clean( $slug ), self::CORE_SERVICE_SLUGS, true );
    }

    public static function storeManagedModuleSlugs(): array {
        return self::STORE_MANAGED_MODULE_SLUGS;
    }

    public static function isStoreManagedModuleSlug( string $slug ): bool {
        return in_array( \metis_key_clean( $slug ), self::STORE_MANAGED_MODULE_SLUGS, true );
    }

    /**
     * @return array<string,array<string,string>>
     */
    public static function transitionalSourceModules(): array {
        return self::TRANSITIONAL_SOURCE_MODULES;
    }

    /**
     * @return array<string,array<string,string>>
     */
    public static function sourceModuleInventory(): array {
        return self::SOURCE_MODULE_INVENTORY;
    }

    /**
     * @return array<string,array<string,string>>
     */
    public static function legacyStoreManagedSourceModules(): array {
        return array_filter(
            self::SOURCE_MODULE_INVENTORY,
            static fn ( array $entry ): bool => (string) ( $entry['status'] ?? '' ) === 'legacy_store_module'
        );
    }

    /**
     * @return array<int,string>
     */
    public static function legacyStoreManagedSourceModuleSlugs(): array {
        return array_keys( self::legacyStoreManagedSourceModules() );
    }

    /**
     * @return array<string,array<int,string>>
     */
    public static function legacyStoreManagedRuntimeBridges(): array {
        return self::LEGACY_STORE_MANAGED_RUNTIME_BRIDGES;
    }

    /**
     * @return array<string,array<int,string>>
     */
    public static function legacyStoreManagedDirectRuntimeBridges(): array {
        return self::LEGACY_STORE_MANAGED_DIRECT_RUNTIME_BRIDGES;
    }

    /**
     * @return array<string,array<int,string>>
     */
    public static function approvedCoreToStoreModuleDependencies(): array {
        return self::APPROVED_CORE_TO_STORE_MODULE_DEPENDENCIES;
    }

    /**
     * @return array<string,string>|null
     */
    public static function transitionalSourceModule( string $slug ): ?array {
        $slug = \metis_key_clean( $slug );
        return is_array( self::TRANSITIONAL_SOURCE_MODULES[ $slug ] ?? null )
            ? self::TRANSITIONAL_SOURCE_MODULES[ $slug ]
            : null;
    }

    /**
     * @return array{module_root:string,core_service_root:string,store_modules:array<int,string>,core_services:array<int,string>,source_module_inventory:array<string,array<string,string>>,legacy_store_managed_runtime_bridges:array<string,array<int,string>>,legacy_store_managed_direct_runtime_bridges:array<string,array<int,string>>,transitional_source_modules:array<string,array<string,string>>}
     */
    public static function migrationSnapshot(): array {
        return [
            'module_root' => self::moduleRootPath(),
            'core_service_root' => self::coreServiceRootPath(),
            'transition_module_root' => self::transitionModuleRootPath(),
            'development_bundle_source_root' => self::developmentBundleSourceRootPath(),
            'store_modules' => self::storeManagedModuleSlugs(),
            'core_services' => self::coreServiceSlugs(),
            'source_module_inventory' => self::sourceModuleInventory(),
            'legacy_store_managed_runtime_bridges' => self::legacyStoreManagedRuntimeBridges(),
            'legacy_store_managed_direct_runtime_bridges' => self::legacyStoreManagedDirectRuntimeBridges(),
            'transitional_source_modules' => self::transitionalSourceModules(),
        ];
    }

    public static function developmentBundleSourceRootPath(): string {
        $configured = getenv( 'METIS_PRIVATE_MODULES_ROOT' );
        if ( is_string( $configured ) && trim( $configured ) !== '' ) {
            return self::normalizedPath( trim( $configured ) );
        }

        $projectRoot = dirname( __DIR__, 4 );
        return self::normalizedPath( dirname( $projectRoot ) . '/metis-private/modules/' );
    }

    /**
     * @return array{classmap:int,static:int,total:int}
     */
    public static function composerSourceModuleAutoloadReferenceCounts(): array {
        $projectRoot = dirname( __DIR__, 4 );
        $targets = [
            'classmap' => $projectRoot . '/system/vendor/composer/autoload_classmap.php',
            'static' => $projectRoot . '/system/vendor/composer/autoload_static.php',
        ];
        $counts = [
            'classmap' => 0,
            'static' => 0,
            'total' => 0,
        ];

        foreach ( $targets as $key => $path ) {
            if ( ! is_file( $path ) ) {
                continue;
            }

            $contents = @file_get_contents( $path );
            if ( ! is_string( $contents ) || $contents === '' ) {
                continue;
            }

            $count = preg_match_all( '#system/src/Metis/Modules/#', $contents, $matches );
            $counts[ $key ] = $count === false ? 0 : $count;
        }

        $counts['total'] = (int) $counts['classmap'] + (int) $counts['static'];

        return $counts;
    }

    /**
     * @return array{
     *     ok:bool,
     *     source_module_root:string,
     *     runtime_module_root:string,
     *     development_bundle_source_root:string,
     *     runtime_module_manifest_count:int,
     *     composer_autoload_source_module_refs:array{classmap:int,static:int,total:int},
     *     direct_runtime_bridge_count:int,
     *     runtime_bridge_frontier_count:int,
     *     missing_mirrored_source_files_count:int,
     *     missing_bundle_slugs:array<int,string>,
     *     bundle_slug_count:int,
     *     modules:array<int,array<string,mixed>>,
     *     blockers:array<int,string>
     * }
     */
    public static function sourceRetirementSnapshot(): array {
        $sourceRoot = self::normalizedPath( dirname( __DIR__, 3 ) . '/src/Metis/Modules/' );
        $runtimeRoot = self::moduleRootPath();
        $bundleRoot = self::developmentBundleSourceRootPath();
        $legacySlugs = self::legacyStoreManagedSourceModuleSlugs();
        sort( $legacySlugs );

        $bundleDirectories = [];
        if ( is_dir( $bundleRoot ) ) {
            foreach ( glob( rtrim( $bundleRoot, '/\\' ) . '/*', GLOB_ONLYDIR ) ?: [] as $directory ) {
                $slug = self::normalizedSlug( basename( $directory ) );
                if ( $slug !== '' ) {
                    $bundleDirectories[ $slug ] = self::normalizedPath( $directory );
                }
            }
        }

        $modules = [];
        $missingBundleSlugs = [];
        $missingMirroredSourceFilesCount = 0;
        foreach ( $legacySlugs as $slug ) {
            $inventory = self::SOURCE_MODULE_INVENTORY[ $slug ] ?? [];
            $bundleSlug = self::normalizedSlug( (string) ( $inventory['target'] ?? $slug ) );
            if ( $bundleSlug === '' ) {
                $bundleSlug = $slug;
            }

            $bundlePath = (string) ( $bundleDirectories[ $bundleSlug ] ?? '' );
            $manifestPath = $bundlePath !== '' ? rtrim( $bundlePath, '/\\' ) . '/module.json' : '';
            $entryPath = $bundlePath !== '' ? rtrim( $bundlePath, '/\\' ) . '/Module.php' : '';
            $sourcePath = rtrim( $sourceRoot, '/\\' ) . '/' . self::namespaceSegmentForSlug( $slug );
            $missingMirroredFiles = self::missingMirroredSourceFiles( $sourcePath, $bundlePath, self::namespaceSegmentForSlug( $slug ) );
            $moduleRow = [
                'slug' => $slug,
                'bundle_slug' => $bundleSlug,
                'source_path' => $sourcePath,
                'bundle_path' => $bundlePath,
                'bundle_present' => $bundlePath !== '' && is_dir( $bundlePath ),
                'manifest_present' => $manifestPath !== '' && is_file( $manifestPath ),
                'entry_present' => $entryPath !== '' && is_file( $entryPath ),
                'missing_mirrored_source_files' => $missingMirroredFiles,
            ];

            if ( empty( $moduleRow['bundle_present'] ) || empty( $moduleRow['manifest_present'] ) || empty( $moduleRow['entry_present'] ) ) {
                $missingBundleSlugs[] = $slug;
            }
            $missingMirroredSourceFilesCount += count( $missingMirroredFiles );

            $modules[] = $moduleRow;
        }

        $runtimeModuleManifestCount = count( glob( rtrim( $runtimeRoot, '/\\' ) . '/*/module.json' ) ?: [] );
        $autoloadRefs = self::composerSourceModuleAutoloadReferenceCounts();
        $directBridgeCount = count( self::legacyStoreManagedDirectRuntimeBridges() );
        $runtimeBridgeFrontierCount = count( self::legacyStoreManagedRuntimeBridges() );

        $blockers = [];
        if ( ! is_dir( $bundleRoot ) ) {
            $blockers[] = sprintf( 'Development bundle source root is missing: %s', rtrim( $bundleRoot, '/\\' ) );
        }
        if ( $missingBundleSlugs !== [] ) {
            $blockers[] = sprintf( 'Legacy source modules still missing bundle replacements: %s', implode( ', ', $missingBundleSlugs ) );
        }
        if ( $directBridgeCount > 0 ) {
            $blockers[] = sprintf( 'Direct source-coupled runtime bridges remain: %d', $directBridgeCount );
        }
        if ( $missingMirroredSourceFilesCount > 0 ) {
            $blockers[] = sprintf(
                'Legacy source files still missing mirrored bundle counterparts: %d',
                $missingMirroredSourceFilesCount
            );
        }

        return [
            'ok' => $blockers === [],
            'source_module_root' => $sourceRoot,
            'runtime_module_root' => $runtimeRoot,
            'development_bundle_source_root' => $bundleRoot,
            'runtime_module_manifest_count' => $runtimeModuleManifestCount,
            'composer_autoload_source_module_refs' => $autoloadRefs,
            'direct_runtime_bridge_count' => $directBridgeCount,
            'runtime_bridge_frontier_count' => $runtimeBridgeFrontierCount,
            'missing_mirrored_source_files_count' => $missingMirroredSourceFilesCount,
            'missing_bundle_slugs' => array_values( $missingBundleSlugs ),
            'bundle_slug_count' => count( $bundleDirectories ),
            'modules' => $modules,
            'blockers' => $blockers,
        ];
    }

    public static function moduleRootPath(): string {
        return self::normalizedPath(
            \defined( 'METIS_MODULES_PATH' )
                ? (string) \METIS_MODULES_PATH
                : dirname( __DIR__, 3 ) . '/modules/'
        );
    }

    public static function coreServiceRootPath(): string {
        return self::normalizedPath(
            \defined( 'METIS_CORE_SERVICES_PATH' )
                ? (string) \METIS_CORE_SERVICES_PATH
                : __DIR__ . '/BuiltInServices/'
        );
    }

    public static function transitionModuleRootPath(): string {
        return self::normalizedPath(
            \defined( 'METIS_TRANSITION_MODULES_PATH' )
                ? (string) \METIS_TRANSITION_MODULES_PATH
                : __DIR__ . '/TransitionModules/'
        );
    }

    public static function rootDefinitions(): array {
        $roots = [];

        $coreServices = self::coreServiceRootPath();
        if ( is_dir( $coreServices ) ) {
            $roots[] = [
                'path' => $coreServices,
                'package_type' => 'core_service',
            ];
        }

        $modules = self::moduleRootPath();
        if ( is_dir( $modules ) ) {
            $roots[] = [
                'path' => $modules,
                'package_type' => 'module',
            ];
        }

        return $roots;
    }

    public static function allRootPaths(): array {
        return array_values(
            array_map(
                static fn ( array $root ): string => (string) $root['path'],
                self::rootDefinitions()
            )
        );
    }

    public static function manifestPaths( ?string $packageType = null ): array {
        $paths = [];

        foreach ( self::rootDefinitions() as $root ) {
            if ( $packageType !== null && ( $root['package_type'] ?? '' ) !== $packageType ) {
                continue;
            }

            foreach ( glob( rtrim( (string) $root['path'], '/\\' ) . '/*/module.json' ) ?: [] as $manifestPath ) {
                $paths[] = (string) $manifestPath;
            }
        }

        sort( $paths );

        return $paths;
    }

    public static function moduleDirectories( ?string $packageType = null ): array {
        $directories = [];

        foreach ( self::rootDefinitions() as $root ) {
            if ( $packageType !== null && ( $root['package_type'] ?? '' ) !== $packageType ) {
                continue;
            }

            foreach ( glob( rtrim( (string) $root['path'], '/\\' ) . '/*', GLOB_ONLYDIR ) ?: [] as $directory ) {
                $directories[] = (string) $directory;
            }
        }

        sort( $directories );

        return $directories;
    }

    public static function packageTypeForPath( string $path ): string {
        $normalized = self::normalizedPath( $path );

        foreach ( self::rootDefinitions() as $root ) {
            $rootPath = self::normalizedPath( (string) ( $root['path'] ?? '' ) );
            if ( $rootPath !== '' && str_starts_with( $normalized, $rootPath ) ) {
                return (string) ( $root['package_type'] ?? 'module' );
            }
        }

        $slug = basename( rtrim( $normalized, '/\\' ) );
        return self::isCoreServiceSlug( $slug ) ? 'core_service' : 'module';
    }

    public static function modulePath( string $slug ): ?string {
        $slug = \metis_key_clean( $slug );
        if ( $slug === '' ) {
            return null;
        }

        foreach ( self::rootDefinitions() as $root ) {
            $candidate = rtrim( (string) $root['path'], '/\\' ) . '/' . $slug;
            if ( is_dir( $candidate ) ) {
                return $candidate;
            }
        }

        return null;
    }

    public static function namespaceSegmentForSlug( string $slug ): string {
        $slug = self::normalizedSlug( $slug );
        if ( $slug === '' ) {
            return '';
        }

        return (string) ( self::MODULE_NAMESPACE_SEGMENTS[ $slug ] ?? self::studlyModuleDirectory( $slug ) );
    }

    public static function slugForNamespaceSegment( string $segment ): string {
        $segment = trim( $segment );
        if ( $segment === '' ) {
            return '';
        }

        foreach ( self::MODULE_NAMESPACE_SEGMENTS as $slug => $namespaceSegment ) {
            if ( strcasecmp( $namespaceSegment, $segment ) === 0 ) {
                return $slug;
            }
        }

        return self::normalizedSlug( $segment );
    }

    private static function normalizedPath( string $path ): string {
        return rtrim( str_replace( '\\', '/', $path ), '/' ) . '/';
    }

    private static function studlyModuleDirectory( string $slug ): string {
        $parts = preg_split( '/[^a-z0-9]+/i', strtolower( $slug ) ) ?: [];

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

    private static function normalizedSlug( string $value ): string {
        $value = strtolower( trim( $value ) );
        $value = preg_replace( '/[^a-z0-9]+/', '_', $value ) ?? '';

        return trim( $value, '_' );
    }

    /**
     * @return array<int,string>
     */
    private static function missingMirroredSourceFiles( string $sourcePath, string $bundlePath, string $namespaceSegment ): array {
        if ( ! is_dir( $sourcePath ) || ! is_dir( $bundlePath ) ) {
            return [];
        }

        $missing = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $sourcePath, \FilesystemIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || strtolower( $file->getExtension() ) !== 'php' ) {
                continue;
            }

            $relative = substr( $file->getPathname(), strlen( $sourcePath ) + 1 );
            $candidate = $relative === $namespaceSegment . 'Module.php'
                ? rtrim( $bundlePath, '/\\' ) . '/Module.php'
                : rtrim( $bundlePath, '/\\' ) . '/' . $relative;

            if ( ! is_file( $candidate ) ) {
                $missing[] = str_replace( '\\', '/', $relative );
            }
        }

        sort( $missing );

        return $missing;
    }
}
