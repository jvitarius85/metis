<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$failures = [];

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

require_once $root . '/src/Metis/Core/ModulePathRegistry.php';

$legacySlugs = \Metis\Core\ModulePathRegistry::legacyStoreManagedSourceModuleSlugs();
sort( $legacySlugs );

$patterns = array_map(
    static fn ( string $slug ): string => preg_quote(
        str_replace( ' ', '', ucwords( str_replace( [ '_', '-' ], ' ', $slug ) ) ),
        '/'
    ),
    $legacySlugs
);

$namespacePattern = '/Metis\\\\Modules\\\\(' . implode( '|', $patterns ) . ')\\\\/';
$scanRoots = [
    $root . '/src/Metis/Core',
    $root . '/src/Metis/Services',
    $root . '/src/Metis/Hermes',
];

$actual = [];

foreach ( $scanRoots as $scanRoot ) {
    if ( ! is_dir( $scanRoot ) ) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $scanRoot, FilesystemIterator::SKIP_DOTS )
    );

    foreach ( $iterator as $file ) {
        if ( ! $file instanceof SplFileInfo || ! $file->isFile() || strtolower( $file->getExtension() ) !== 'php' ) {
            continue;
        }

        $contents = @file_get_contents( $file->getPathname() );
        if ( ! is_string( $contents ) || $contents === '' ) {
            continue;
        }

        if ( preg_match_all( $namespacePattern, $contents, $matches ) === false || $matches === [] ) {
            continue;
        }

        $moduleSlugs = array_values(
            array_unique(
                array_map(
                    static function ( string $match ): string {
                        $normalized = preg_replace( '/(?<!^)[A-Z]/', '_$0', $match );
                        return strtolower( (string) $normalized );
                    },
                    (array) ( $matches[1] ?? [] )
                )
            )
        );
        sort( $moduleSlugs );

        if ( $moduleSlugs === [] ) {
            continue;
        }

        $relativePath = substr( $file->getPathname(), strlen( $root ) + 1 );
        $actual[ $relativePath ] = $moduleSlugs;
    }
}

ksort( $actual );

$allowed = [
    'src/Metis/Core/BuiltInServices/settings/views/_settings_bootstrap.php' => [ 'website' ],
    'src/Metis/Core/Editor/EditorAutosaveService.php' => [ 'website' ],
    'src/Metis/Core/Editor/EditorPreviewService.php' => [ 'website' ],
    'src/Metis/Core/Editor/EditorVersionService.php' => [ 'website' ],
    'src/Metis/Core/Runtime/StandaloneApplicationBootstrap.php' => [ 'board', 'calendar', 'contacts', 'finance', 'forms', 'import', 'newsletter', 'website' ],
    'src/Metis/Core/Services/EntityResolverService.php' => [ 'contacts' ],
    'src/Metis/Services/HermesCmsAdminService.php' => [ 'website' ],
    'src/Metis/Services/HermesContactAdminService.php' => [ 'contacts' ],
    'src/Metis/Services/HermesNewsletterAdminService.php' => [ 'newsletter' ],
    'src/Metis/Services/HermesWebsiteAdminService.php' => [ 'website' ],
];

$unexpectedFiles = array_diff_key( $actual, $allowed );
foreach ( $unexpectedFiles as $path => $slugs ) {
    $assert(
        false,
        sprintf(
            'New core-to-store-module dependency detected in [%s]: %s',
            $path,
            implode( ', ', $slugs )
        )
    );
}

foreach ( $allowed as $path => $allowedSlugs ) {
    $actualSlugs = $actual[ $path ] ?? [];
    $unexpectedSlugs = array_values( array_diff( $actualSlugs, $allowedSlugs ) );
    if ( $unexpectedSlugs !== [] ) {
        $assert(
            false,
            sprintf(
                'Core dependency file [%s] now references additional legacy store modules: %s',
                $path,
                implode( ', ', $unexpectedSlugs )
            )
        );
    }
}

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Module store dependency boundary checks passed.\n" );
