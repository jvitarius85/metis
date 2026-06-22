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

$read = static function ( string $path ) use ( $root ): string {
    $contents = file_get_contents( $root . '/' . ltrim( $path, '/\\' ) );
    return $contents === false ? '' : $contents;
};

$moduleInstall = $read( 'src/Metis/Core/Services/ModuleInstallService.php' );
$moduleValidator = $read( 'src/Metis/Core/Modules/ModuleValidator.php' );
$mediaBootstrap = file_get_contents( '/Users/jvitarius85/Documents/GitHub/metis-private/modules/media/bootstrap.php' );
$mediaBootstrap = $mediaBootstrap === false ? '' : $mediaBootstrap;

$installStart = strpos( $moduleInstall, 'public function installLatest' );
$installEnd = strpos( $moduleInstall, 'public function uninstall' );
$installBody = $installStart !== false && $installEnd !== false
    ? substr( $moduleInstall, $installStart, $installEnd - $installStart )
    : '';

$assert(
    str_contains( $installBody, "\$stagedDestination = \$workspace . '/runtime-module';" )
    && str_contains( $installBody, "\$this->verifyInstalledRuntimeContract(\$stagedDestination, \$manifest, \$moduleId);" )
    && str_contains( $installBody, '@rename($stagedDestination, $destination)' ),
    'Module install must stage the extracted bundle and promote it into the runtime root only after staged validation succeeds.'
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
    str_contains( $mediaBootstrap, "if ( ! function_exists( 'metis_media_find_by_token' ) ) {" )
    && str_contains( $mediaBootstrap, "if ( ! function_exists( 'metis_media_find_by_filename' ) ) {" ),
    'Media bootstrap compatibility helpers must be guarded so core runtime helpers can coexist without fatal redeclarations.'
);

$assert(
    str_contains( $moduleValidator, 'validateBootstrapFunctionCollisions' )
    && str_contains( $moduleValidator, 'bootstrapDeclaredFunctions' )
    && str_contains( $moduleValidator, 'bootstrap declares helper [%s] that conflicts with an existing runtime function.' )
    && str_contains( $moduleValidator, 'token_get_all' ),
    'Module validation must reject bootstrap helper collisions before the runtime includes the bundle.'
);

if ( $failures !== [] ) {
    fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
    exit( 1 );
}

fwrite( STDOUT, "Module runtime install contract checks passed.\n" );
