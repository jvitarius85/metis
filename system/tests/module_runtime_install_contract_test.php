<?php
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' ) {
    fwrite( STDERR, "This test must be run from the command line.\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );
$failures = [];

require_once __DIR__ . '/_support/module_path_resolver.php';

$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
    if ( ! $condition ) {
        $failures[] = $message;
    }
};

$read = static function ( string $path ) use ( $root ): string {
    $contents = file_get_contents( $root . '/' . ltrim( $path, '/\\' ) );
    return $contents === false ? '' : $contents;
};

$moduleInstall = $read( 'src/Metis/Core/Services/ModuleInstallService.php' );
$moduleValidator = $read( 'src/Metis/Core/Modules/ModuleValidator.php' );
$mediaBootstrapPath = metis_test_private_modules_root( $root ) . '/media/bootstrap.php';
$mediaBootstrap = file_get_contents( $mediaBootstrapPath );
$mediaBootstrap = $mediaBootstrap === false ? '' : $mediaBootstrap;

$installStart = strpos( $moduleInstall, 'public function installLatest' );
$installEnd = strpos( $moduleInstall, 'public function uninstall' );
$installBody = $installStart !== false && $installEnd !== false
    ? substr( $moduleInstall, $installStart, $installEnd - $installStart )
    : '';

$assert(
    str_contains( $installBody, "\$stagedDestination = \$workspace . '/runtime-module';" )
    && str_contains( $installBody, "\$this->verifyInstalledRuntimeContract(\$stagedDestination, \$manifest, \$moduleId);" )
    && str_contains( $installBody, '@rename($stagedDestination, $destination)' )
    && str_contains( $installBody, '$schemaResult = $this->runInstalledModuleSchema($moduleId);' ),
    'Module install must stage the extracted bundle, promote it into the runtime root, and then run the installed module schema entrypoint.'
);

$assert(
    str_contains( $installBody, '@rename($destination, $previousDestination)' )
    && ! str_contains( $installBody, '$this->files->remove($destination);' ),
    'Module install must not delete the live runtime module before the staged replacement is ready.'
);

$assert(
    str_contains( $moduleInstall, 'private function verifyInstalledRuntimeContract(string $modulePath, array $manifest, string $moduleId): void' )
    && str_contains( $moduleInstall, "missing module.json after staging." )
    && str_contains( $moduleInstall, "missing entry file [%s] after staging." )
    && str_contains( $moduleInstall, "missing bootstrap file [%s] after staging." ),
    'Module install must verify staged runtime contract files before swapping the bundle into system/modules.'
);

$assert(
    str_contains( $moduleInstall, 'private function runInstalledModuleSchema(string $moduleId): array' )
    && str_contains( $moduleInstall, "RuntimeModuleEntryResolver::resolve(\$moduleId)" )
    && str_contains( $moduleInstall, "if (\\method_exists(\$moduleClass, 'ensureRuntimeSchema'))" )
    && str_contains( $moduleInstall, "if (\\method_exists(\$moduleClass, 'ensureSchema'))" ),
    'Module install must resolve the installed module entry class and prefer ensureRuntimeSchema/ensureSchema ownership hooks.'
);

$assert(
    ! str_contains( $mediaBootstrap, 'function metis_media_find_by_token' )
    && ! str_contains( $mediaBootstrap, 'function metis_media_find_by_filename' ),
    'Media runtime bundles must not redeclare upload helper functions that already belong to core runtime.'
);

$assert(
    str_contains( $moduleValidator, 'validateBootstrapFunctionCollisions' )
    && str_contains( $moduleValidator, 'bootstrapDeclaredFunctions' )
    && str_contains( $moduleValidator, 'coreDeclaredFunctions' )
    && str_contains( $moduleValidator, 'bootstrap declares helper [%s] that conflicts with an existing runtime function.' )
    && str_contains( $moduleValidator, 'RecursiveDirectoryIterator' )
    && str_contains( $moduleValidator, 'token_get_all' ),
    'Module validation must reject bootstrap helper collisions against core runtime declarations before the runtime includes the bundle.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Module runtime install contract checks passed.\n" );
