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
     * @return array<string,string>|null
     */
    public static function transitionalSourceModule( string $slug ): ?array {
        $slug = \metis_key_clean( $slug );
        return is_array( self::TRANSITIONAL_SOURCE_MODULES[ $slug ] ?? null )
            ? self::TRANSITIONAL_SOURCE_MODULES[ $slug ]
            : null;
    }

    /**
     * @return array{module_root:string,core_service_root:string,store_modules:array<int,string>,core_services:array<int,string>,transitional_source_modules:array<string,array<string,string>>}
     */
    public static function migrationSnapshot(): array {
        return [
            'module_root' => self::moduleRootPath(),
            'core_service_root' => self::coreServiceRootPath(),
            'store_modules' => self::storeManagedModuleSlugs(),
            'core_services' => self::coreServiceSlugs(),
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
