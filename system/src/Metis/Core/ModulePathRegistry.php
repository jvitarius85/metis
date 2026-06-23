<?php
declare(strict_types=1);

namespace Metis\Core;

final class ModulePathRegistry {
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
        'grandystash' => [
            'status' => 'normalize_slug',
            'target' => 'grandys_stash',
            'notes' => 'Legacy source directory name does not match the store/runtime slug and should be normalized during migration.',
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
            'status' => 'normalize_slug',
            'target' => 'grandys_stash',
            'notes' => 'Legacy source directory name does not match the store/runtime slug and should be normalized during migration.',
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
        'src/Metis/Core/Runtime/NewsletterModuleRuntimeBridge.php' => [
            'newsletter',
        ],
        'src/Metis/Core/Runtime/WebsiteModuleRuntimeBridge.php' => [
            'website',
        ],
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
     * @return array<string,string>|null
     */
    public static function transitionalSourceModule( string $slug ): ?array {
        $slug = \metis_key_clean( $slug );
        return is_array( self::TRANSITIONAL_SOURCE_MODULES[ $slug ] ?? null )
            ? self::TRANSITIONAL_SOURCE_MODULES[ $slug ]
            : null;
    }

    /**
     * @return array{module_root:string,core_service_root:string,store_modules:array<int,string>,core_services:array<int,string>,source_module_inventory:array<string,array<string,string>>,legacy_store_managed_runtime_bridges:array<string,array<int,string>>,transitional_source_modules:array<string,array<string,string>>}
     */
    public static function migrationSnapshot(): array {
        return [
            'module_root' => self::moduleRootPath(),
            'core_service_root' => self::coreServiceRootPath(),
            'store_modules' => self::storeManagedModuleSlugs(),
            'core_services' => self::coreServiceSlugs(),
            'source_module_inventory' => self::sourceModuleInventory(),
            'legacy_store_managed_runtime_bridges' => self::legacyStoreManagedRuntimeBridges(),
            'transitional_source_modules' => self::transitionalSourceModules(),
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

    private static function normalizedPath( string $path ): string {
        return rtrim( str_replace( '\\', '/', $path ), '/' ) . '/';
    }
}
