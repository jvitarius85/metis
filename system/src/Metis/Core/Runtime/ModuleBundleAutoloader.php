<?php
declare(strict_types=1);

namespace Metis\Core\Runtime;

use Metis\Core\ModulePathRegistry;

final class ModuleBundleAutoloader {
    private const PREFIX = 'Metis\\Modules\\';

    private static bool $registered = false;

    public static function register(): void {
        if ( self::$registered ) {
            return;
        }

        spl_autoload_register( [ self::class, 'autoload' ], true, true );
        self::$registered = true;
    }

    public static function autoload( string $class ): void {
        if ( ! str_starts_with( $class, self::PREFIX ) ) {
            return;
        }

        $relative = substr( $class, strlen( self::PREFIX ) );
        $parts = explode( '\\', $relative );
        $moduleSegment = array_shift( $parts );
        if ( ! is_string( $moduleSegment ) || $moduleSegment === '' ) {
            return;
        }

        $slug = ModulePathRegistry::slugForNamespaceSegment( $moduleSegment );
        if ( $slug === '' ) {
            return;
        }

        foreach ( self::roots() as $root ) {
            $moduleDir = rtrim( $root, '/\\' ) . '/' . $slug;
            if ( ! is_dir( $moduleDir ) ) {
                continue;
            }

            foreach ( self::candidateFiles( $moduleDir, $moduleSegment, $parts ) as $file ) {
                if ( ! is_file( $file ) ) {
                    continue;
                }

                require_once $file;
                if (
                    class_exists( $class, false )
                    || interface_exists( $class, false )
                    || trait_exists( $class, false )
                ) {
                    return;
                }
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private static function roots(): array {
        $roots = [];

        foreach ( [ ModulePathRegistry::moduleRootPath(), ModulePathRegistry::developmentBundleSourceRootPath() ] as $root ) {
            $normalized = rtrim( $root, '/\\' );
            if ( $normalized !== '' && is_dir( $normalized ) && ! in_array( $normalized, $roots, true ) ) {
                $roots[] = $normalized;
            }
        }

        return $roots;
    }

    /**
     * @param array<int,string> $parts
     * @return array<int,string>
     */
    private static function candidateFiles( string $moduleDir, string $moduleSegment, array $parts ): array {
        $candidates = [];
        $className = (string) ( end( $parts ) ?: '' );
        $directories = $parts !== [] ? array_slice( $parts, 0, -1 ) : [];

        if ( $className === $moduleSegment . 'Module' ) {
            $candidates[] = $moduleDir . '/Module.php';
        }

        if ( $className !== '' ) {
            $candidates[] = self::buildCandidatePath( $moduleDir, $directories, $className, false );
            $candidates[] = self::buildCandidatePath( $moduleDir, $directories, $className, true );
        }

        return array_values(
            array_unique(
                array_filter(
                    $candidates,
                    static fn ( string $path ): bool => $path !== ''
                )
            )
        );
    }

    /**
     * @param array<int,string> $directories
     */
    private static function buildCandidatePath( string $moduleDir, array $directories, string $className, bool $lowercaseDirectories ): string {
        $relativeDirectories = $directories;
        if ( $lowercaseDirectories ) {
            $relativeDirectories = array_map( 'strtolower', $directories );
        }

        $relativePath = $relativeDirectories === []
            ? $className . '.php'
            : implode( '/', $relativeDirectories ) . '/' . $className . '.php';

        return rtrim( $moduleDir, '/\\' ) . '/' . $relativePath;
    }
}
